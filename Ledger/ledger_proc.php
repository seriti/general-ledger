<?php
//just some easy namespace neatness.
//*** LEGACY CODE FOR REFERENCE ONLY ***
class ledger {
  
  public static function open_period($conn,$company_id,$period_id,&$error_str) {
    $error_str='';
    
    $periods=self::check_period_sequence($conn,$company_id,$period_id,$error_tmp);
    if($error_tmp!='') {
      $error_str.='Period sequence error:'.$error_tmp;
    } else {
      if($periods['next']!=0 and $periods['next']['status']=='CLOSED') {
        $error_str.='Next period['.$periods['next']['name'].'] is CLOSED! '.
                    'You will need to OPEN all subsequent CLOSED periods first, starting with the most recent one.';
      }  
      
    }    
    
    //NB: Not necessary to delete previous CLOSE transactions as when period closed again any additional required
    //CLOSE transactions will be generated. Alternatively you can manually delete existing CLOSE transactions before closing
        
    //finally update period status
    if($error_str=='') {
      $sql='UPDATE '.TABLE_PREFIX.'period SET status = "OPEN" '.
           'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" AND '.
                 'period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'" ';
      seriti_mysql::execute_sql($sql,$conn,$error_tmp);
      if($error_tmp!='') $error_str.='Could not OPEN period: '.$error_tmp;
    }  
     
    if($error_str=='') return true; else return false;	     
  } 
  
  public static function close_period($conn,$company_id,$period_id,&$error_str) {
    $error_str='';
    $error_tmp='';
    
    //get company details
    $company=self::get_company($conn,$company_id);
    
    //retained income account id
    $ret_account_id=$company['ret_account_id'];
    
    $periods=self::check_period_sequence($conn,$company_id,$period_id,$error_tmp);
    if($error_tmp!='') {
      $error_str.='Period sequence error:'.$error_tmp;
    } else {
      if($periods['previous']!=0 and $periods['previous']['status']!=='CLOSED') {
        $error_str.='Previous period['.$periods['previous']['name'].'] is NOT CLOSED! '.
                    'You will need to CLOSE all previous periods first, starting with the earliest one.';
      } 
      
      if($periods['current']['status']=='CLOSED') {
        $error_str.='Current Period['.$periods['current']['name'].'] is already CLOSED!<br/>';
      }   
      
    } 
    //echo "company ID $company_id, period ID $period_id";   
    //print_r($periods);
    //exit;
    
    
    //calculate latest balances
    if($error_str=='') {
      $options=array();
      self::calculate_balances($conn,$company_id,$period_id,$options,$error_tmp);
      if($error_tmp!='') $error_str.='Could not calculate balances: '.$error_tmp;
    }
    
    //generate INCOME account closing entries to company Retained income account
    if($error_str=='') {
      $transact=array();
      $transact['company_id']=$company_id;
      //NB: CLOSE transactions must be excluded from income statment
      $transact['type_id']='CLOSE';
      $transact['date_create']=date('Y-m-d');
      $transact['date']=$periods['current']['date_end'];
      //Primary account for display but not used with type_id = CUSTOM/CLOSE
      $transact['account_id']=$ret_account_id;
      $transact['debit_credit']='C';
      
      $transact['description']='CLOSE period['.$periods['current']['name'].'] INCOME accounts';
      $debit_accounts=array();
      $credit_accounts=array();
      
      $sql='SELECT B.account_id,B.account_balance '.
           'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
           'WHERE B.period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'"  AND '.
                 'A.type_id LIKE "INCOME%" AND B.account_balance <> 0 ';
      $balance_close=seriti_mysql::read_sql_list($sql,$conn);  
      if($balance_close!=0) {
        $close_total=0;
        foreach($balance_close as $account_id=>$balance) {
          $close_total+=$balance;
          
          if($balance>0) {
            $debit_accounts[$account_id]=$balance;
          } else {  
            $credit_accounts[$account_id]=abs($balance);
          }  
        }
               
        //possible that close_total could be 0
        if($close_total>0) $credit_accounts[$ret_account_id]=$close_total;
        if($close_total<0) $debit_accounts[$ret_account_id]=abs($close_total);
        
        $transact['amount']=abs($close_total);
        $transact['debit_accounts']=json_encode($debit_accounts);
        $transact['credit_accounts']=json_encode($credit_accounts);
        
        //create transaction record
        $transact_id=seriti_mysql::insert_record($conn,TABLE_PREFIX.'transact',$transact,$error_tmp);
        if($error_tmp!='') {
          $error_str.='Could NOT create INCOME accounts closing transaction:'.$error_tmp; 
        } else {  
          //process transaction ledger entries
          $options=array();
          self::process_transaction($conn,$transact_id,$company_id,$options,$error_tmp);
          if($error_tmp!='') {
            $error_str.='Could NOT process INCOME accounts closing transaction['.$transact_id.']: '.$error_tmp.'<br/>';
          } 
        }
        
      }
    }
    
    //generate EXPENSE account closing entries to company Retained income account
    if($error_str=='') {  
      //Primary account for display but not used with type_id = CLOSE/CUSTOM
      $transact['account_id']=$ret_account_id;
      $transact['debit_credit']='D';
      
      //NB other trasnaction details unchanged.
      $transact['description']='CLOSE period['.$periods['current']['name'].'] EXPENSE accounts';
      $debit_accounts=array();
      $credit_accounts=array();
      
      $sql='SELECT B.account_id,B.account_balance '.
           'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
           'WHERE B.period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'"  AND '.
                 'A.type_id LIKE "EXPENSE%" AND B.account_balance <> 0 ';
      $balance_close=seriti_mysql::read_sql_list($sql,$conn);  
      if($balance_close!=0) {
        $close_total=0;
        foreach($balance_close as $account_id=>$balance) {
          $close_total+=$balance;
          
          if($balance>0) {
            $credit_accounts[$account_id]=$balance;
          } else {  
            $debit_accounts[$account_id]=abs($balance);
          }  
        }
        
        //possible that close_total could be 0
        if($close_total>0) $debit_accounts[$ret_account_id]=$close_total;
        if($close_total<0) $credit_accounts[$ret_account_id]=abs($close_total);
        
        $transact['amount']=abs($close_total);
        $transact['debit_accounts']=json_encode($debit_accounts);
        $transact['credit_accounts']=json_encode($credit_accounts);
        
        //create transaction record
        $transact_id=seriti_mysql::insert_record($conn,TABLE_PREFIX.'transact',$transact,$error_tmp);
        if($error_tmp!='') {
          $error_str.='Could NOT create EXPENSE accounts closing transaction!'; 
        } else {  
          //process transaction ledger entries
          $options=array();
          self::process_transaction($conn,$transact_id,$company_id,$options,$error_tmp);
          if($error_tmp!='') {
            $error_str.='Could not process EXPENSE accounts closing transaction['.$transact_id.']: '.$error_tmp.'<br/>';
          } 
        }
      }
    }  
    
    //calculate closing balances including closing transactions 
    if($error_str=='') {
      $options=array();
      self::calculate_balances($conn,$company_id,$period_id,$options,$error_tmp);
      if($error_tmp!='') $error_str.='Could not calculate closing balances: '.$error_tmp;
    }
    
    //finally update period status
    if($error_str=='') {
      $sql='UPDATE '.TABLE_PREFIX.'period SET status = "CLOSED" '.
           'WHERE period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'" ';
      seriti_mysql::execute_sql($sql,$conn,$error_tmp);
      if($error_tmp!='') $error_str.='Could not OPEN period['.$period_id.']';
    }  
     
    if($error_str=='') return true; else return false;	     
  }  
  
  public static function calculate_balances($conn,$company_id,$period_id,$options=array(),&$error_str) {
    $error_str='';
    
    //escape SQL variables
    $period_id=seriti_mysql::escape_sql($conn,$period_id);
    $company_id=seriti_mysql::escape_sql($conn,$company_id);
    
    //get period record
    $sql='SELECT period_id,name,date_start,date_end,period_id_previous,company_id,status '.
         'FROM '.TABLE_PREFIX.'period WHERE period_id = "'.$period_id.'" ';
    $period=seriti_mysql::read_sql_record($sql,$conn); 
    if($period==0) $error_str.='INVALID balance period ID['.$period_id.']<br/>';
    
    //basic validation
    if($error_str=='') {
      if($period['company_id']!=$company_id) {
        $error_str.='Period['.$period_id.'] company['.$period['company_id'].'] not same as balance company['.$company_id.']<br/>';
      } 
    }
    
    //get all accounts including hidden ones just in case there are lurking transactions/balances somewhere
    if($error_str=='') {  
      $sql='SELECT account_id,name,type_id,description '.
           'FROM '.TABLE_PREFIX.'account '.
           'WHERE company_id = "'.$company_id.'" '.
           'ORDER BY type_id ';  
      $accounts=seriti_mysql::read_sql_array($sql,$conn);   
      if($accounts==0) $error_str.='NO accounts exist for Company['.$company_id.']!<br/>';   
    } 
    
    //get opening balances
    if($error_str=='') {
      if($period['period_id_previous']!=0) {
        $sql='SELECT account_id,account_balance FROM '.TABLE_PREFIX.'balance '.
             'WHERE period_id = "'.$period['period_id_previous'].'" ';
        $balance_open=seriti_mysql::read_sql_list($sql,$conn);  
        if($balance_open==0) $error_str.='Previous Period ID['.$period['period_id_previous'].'] has NO balances!';   
      } else {
        $balance_open=array();
      }
    }
    
    //process entries and generate closing balances
    if($error_str=='') {
      $balance_close=array();
      
      foreach($accounts as $account_id=>$account) {
        $debit_total=0;
        $credit_total=0;
        
        $sql='SELECT debit_credit,SUM(amount) FROM '.TABLE_PREFIX.'entry '.
             'WHERE account_id = "'.$account_id.'" AND '.
                   'date >= "'.$period['date_start'].'" AND date <= "'.$period['date_end'].'" '.
             'GROUP BY debit_credit ';
        $entry=seriti_mysql::read_sql_list($sql,$conn); 
        if($entry!=0) {
          if(isset($entry['D'])) $debit_total=$entry['D'];
          if(isset($entry['C'])) $credit_total=$entry['C'];
        }
      
        if(substr($account['type_id'],0,5)==='ASSET' or substr($account['type_id'],0,7)==='EXPENSE') {
          //DEBIT balances
          $balance_close[$account_id]=$debit_total-$credit_total; 
        } else {
          //CREDIT balances
          $balance_close[$account_id]=$credit_total-$debit_total; 
        }  
        
        //bring in any opening balances
        if(isset($balance_open[$account_id])) {
          $balance_close[$account_id]=$balance_open[$account_id]+$balance_close[$account_id];
        } 
        
        //remove any empty balances
        if($balance_close[$account_id]==0) unset($balance_close[$account_id]);
      }
      
    } 
    
    //save balances to db
    if($error_str=='') {
      //remove all old balance records
      $sql='DELETE FROM '.TABLE_PREFIX.'balance '.
           'WHERE period_id = "'.$period_id.'" ';
      seriti_mysql::execute_sql($sql,$conn,$error_tmp);
      if($error_tmp!='') $error_str.='Could NOT delete closing balances for period['.$period_id.']';
      //create new records
      foreach($balance_close as $account_id=>$balance) {
        $sql='INSERT INTO '.TABLE_PREFIX.'balance (period_id,account_id,account_balance ) '.
             'VALUES("'.$period_id.'","'.$account_id.'","'.$balance.'")';
        seriti_mysql::execute_sql($sql,$conn,$error_tmp);
        if($error_tmp!='') $error_str.='Could NOT insert closing balances for account['.$account_id.']';
      }
    } 
    
    //save balance time stamp to Company
    if($error_str=='') {
      $sql='UPDATE '.TABLE_PREFIX.'company SET calc_timestamp = NOW() '.
           'WHERE company_id = "'.$company_id.'" ';
      seriti_mysql::execute_sql($sql,$conn,$error_tmp);
        if($error_tmp!='') $error_str.='Could NOT update company timestamp!';     
    }  
  
    if($error_str=='') return true; else return false;	   
  }
  
  public static function setup_report_period($conn,$report,$company_id,$period_id,&$error_str) {
    $error_tmp='';
    $error_str='';
    
    $sql='SELECT name,status,date_start,date_end,company_id '.
         'FROM '.TABLE_PREFIX.'period WHERE period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'" ';
    $period=seriti_mysql::read_sql_record($sql,$conn);
    if($period==0) {
      $error_str.='INVALID period['.$period_id.']';
    } else {
      if($period['company_id']!=$company_id) $error_str.='Period company['.$period['company_id'].'] NOT VALID!'; 
    }    
    
    if($report=='BALANCE') {
      //check if any transactions processed since last calculate_balances()
      /* disabled as calc_timestamp needs to be moved to gl_period table
      $sql_company='(SELECT calc_timestamp FROM '.TABLE_PREFIX.'company '.
                    'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'")';
      $sql_transact='(SELECT MAX(date_process) FROM '.TABLE_PREFIX.'transact '.
                     'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'")';
      //returns transact timestamp - balance calc timestamp
      $sql='SELECT TIMESTAMPDIFF(SECOND,'.$sql_company.','.$sql_transact.')';
      $seconds=seriti_mysql::read_sql_value($sql,$conn);
      if($seconds==null) $seconds=100;
      */
      
      //force recalc for now
      $seconds=100;
           
      //calculate balances if period not closed AND last calculation out of date
      if($error_str=='') {
        if($period['status']!='CLOSED' and $seconds>0) {
          $options=array();
          self::calculate_balances($conn,$company_id,$period_id,$options,$error_tmp);
          if($error_tmp!='') {
            $error_str.='Could not generate trial balance for period['.$period['name'].']:'.$error_tmp;
          }  
        }   
      }
    }  
    
    if($error_str=='') return $period; else return false;	 
  }
  
  public static function balance_sheet($conn,$company_id,$period_id,$options=array(),&$error_str) {
    global $seriti_config;
    global $ACC_TYPE;
    
    $error_tmp='';
    $error_str='';
    $html='';
    
    $curr_symbol='R';   
    
    //get company details
    $company=self::get_company($conn,$company_id);
    
    if(!isset($options['format'])) $options['format']='HTML';
    
    $period=self::setup_report_period($conn,'BALANCE',$company_id,$period_id,$error_tmp);
    if($error_tmp!='') $error_str.='Period ERROR: '.$error_tmp;
       
    //get period balances
    if($error_str=='') {
      $sql='SELECT B.account_id,B.account_balance,A.type_id,A.name,A.description '.
           'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
           'WHERE B.period_id = "'.seriti_mysql::escape_sql($conn,$period_id).'" '.
           'ORDER BY A.type_id ';
      $balances=seriti_mysql::read_sql_array($sql,$conn);  
      if($balances==0) $error_str.='NO balances found for period['.$period['name'].']!';   
    } 
    
    //generate balance sheet
    if($error_str=='') {
      $data=array();
      $income=0.00;
      $expenses=0.00;
      $open_pl=0.00;
      
      //setup empty balance sheet account arrays
      foreach($ACC_TYPE as $type_id=>$name) {
        if(substr($type_id,0,6)!=='INCOME' and substr($type_id,0,7)!=='EXPENSE') { 
          $data[$type_id]=array(); 
        }  
      }  
            
      //organise balances into type_id arrays
      foreach($balances as $account_id=>$balance) {
        $data[$balance['type_id']][$account_id]=$balance;
        
        //NB: should be zero if period CLOSED
        if(substr($balance['type_id'],0,6)=='INCOME') $income+=floatval($balance['account_balance']);
        if(substr($balance['type_id'],0,7)=='EXPENSE') $expenses+=floatval($balance['account_balance']);
      } 
      $open_pl=$income-$expenses;
      
      //prepare account arrays for html or pdf      
      $total_asset=0.00;
      $assets=array();
      $r=0;
      $assets[0][$r]='ASSETS:';
      $assets[1][$r]='';
      //get all asset balances
      foreach($data as $type_id=>$data_arr) {
        if((substr($type_id,0,5)==='ASSET') and count($data_arr)) {
          $rt=0;
          $total=0;
          foreach($data_arr as $account_id=>$balance) {
            $r++;
            $rt++;
            $assets[0][$r]=$balance['name'];
            $assets[1][$r]=$balance['account_balance'];
            $total+=floatval($balance['account_balance']);
          } 
          $total_asset+=$total;
          if($rt>1) {
            $r++;
            $assets[0][$r]='CUSTOM_ROW';
            $assets[1][$r]='BLANK';
            $r++;
            $assets[0][$r]='Total '.$ACC_TYPE[$type_id].':';
            $assets[1][$r]=$total;
          }  
        } 
      }
      //show total assets
      if($total_asset!=0) {
        $r++;
        $assets[0][$r]='CUSTOM_ROW';
        $assets[1][$r]='BLANK';
        $r++;
        $assets[0][$r]='Total ASSETS:';
        $assets[1][$r]=$total_asset; 
      } else {
        $assets[1][$r]='No asset balances'; 
      }   
      
      
      $total_liability=0.00;
      $liability=array();
      $r=0;
      $liability[0][$r]='LIABILITIES:';
      $liability[1][$r]='';
      //get all liability balances
      foreach($data as $type_id=>$data_arr) {
        if((substr($type_id,0,9)==='LIABILITY') and count($data_arr)) {
          $rt=0;
          $total=0;
          foreach($data_arr as $account_id=>$balance) {
            $r++;
            $rt++;
            $liability[0][$r]=$balance['name'];
            $liability[1][$r]=$balance['account_balance'];
            $total+=floatval($balance['account_balance']);
          } 
          $total_liability+=$total;
          if($rt>1) {
            $r++;
            $liability[0][$r]='CUSTOM_ROW';
            $liability[1][$r]='BLANK';
            $r++;
            $liability[0][$r]='Total '.$ACC_TYPE[$type_id].':';
            $liability[1][$r]=$total;
          }  
        } 
      }
      //show total liabilities
      if($total_liability!=0) {
        $r++;
        $liability[0][$r]='CUSTOM_ROW';
        $liability[1][$r]='BLANK';
        $r++;
        $liability[0][$r]='Total LIABILITIES:';
        $liability[1][$r]=$total_liability; 
      } else {
        $liability[1][$r]='No liability balances'; 
      }
      
      $total_equity=0.00;
      $equity=array();
      $r=0;
      $equity[0][$r]='EQUITY:';
      $equity[1][$r]='';
      //get all asset balances
      foreach($data as $type_id=>$data_arr) {
        if((substr($type_id,0,6)==='EQUITY') and count($data_arr)) {
          $rt=0;
          $total=0;
          foreach($data_arr as $account_id=>$balance) {
            $r++;
            $rt++;
            $equity[0][$r]=$balance['name'];
            $equity[1][$r]=$balance['account_balance'];
            $total+=floatval($balance['account_balance']);
          } 
          $total_equity+=$total;
          if($rt>1) {
            $r++;
            $equity[0][$r]='CUSTOM_ROW';
            $equity[1][$r]='BLANK';
            $r++;
            $equity[0][$r]='Total '.$ACC_TYPE[$type_id].':';
            $equity[1][$r]=$total;
          } 
           
        } 
      }
      
      //insert any current PL for non closed periods
      if($open_pl!=0.00) {
        $r++;
        $equity[0][$r]='CUSTOM_ROW';
        $equity[1][$r]='BLANK';
        $r++;
        $equity[0][$r]='OPEN P&L(Income-Expenses)';
        $equity[1][$r]=$open_pl;
        $total_equity+=$open_pl;
      }
      
      //show total assets
      if($total_equity!=0) {
        $r++;
        $equity[0][$r]='CUSTOM_ROW';
        $equity[1][$r]='BLANK';
        $r++;
        $equity[0][$r]='Total EQUITY:';
        $equity[1][$r]=$total_equity; 
      } else {
        $equity[1][$r]='No equity balances'; 
      }  
      
      //left and right balance sheet totals
      $left_total=$total_asset; 
      $right_total=$total_equity+$total_liability;
      
      //layout setup
      $row_h=7;//ignored for html
      $align='L';//ignored for html
      $col_width=array(100,100);
      $col_type=array('','DBL2');
      $html_options=array();
      $output=array();
                  
      if($options['format']=='HTML') {
        $left_col=seriti_html::array_draw_table($assets,$row_h,$col_width,$col_type,$align,$html_options,$output);

        $right_col=seriti_html::array_draw_table($liability,$row_h,$col_width,$col_type,$align,$html_options,$output);
        $right_col.='<br/>'; 
        $right_col.=seriti_html::array_draw_table($equity,$row_h,$col_width,$col_type,$align,$html_options,$output);
        
        $html='<table class="table  table-striped table-bordered table-hover table-condensed">'.
              '<tr valign="top"><td>'.$left_col.'</td><td valign="top">'.$right_col.'</td></tr>'.
              '<tr><td align="right">Balance: '.number_format($left_total,2).'</td>'.
                  '<td align="right">Balance: '.number_format($right_total,2).'</td></tr>'.
              '</table>';
      }   
      
      if($options['format']==='CSV') {
        $csv_data='';
        $doc_name=$company['name'].'_balance_sheet_'.$period['name'].'.csv';
        $doc_name=str_replace(' ','_',$doc_name);
        
        if(count($assets!=0)) {
          $csv_data.=seriti_csv::array_dump_csv($assets);
          $csv_data.="\r\n";
        }
        
        if(count($liability!=0)) {
          $csv_data.=seriti_csv::array_dump_csv($liability);
          $csv_data.="\r\n";
        }
        
        if(count($equity!=0)) {
          $csv_data.=seriti_csv::array_dump_csv($equity);
          $csv_data.="\r\n";
        }
        
        
        seriti_doc::output_doc($csv_data,$doc_name,'DOWNLOAD','csv');
        exit;
      } 
      
      if($options['format']=='PDF') {
        $pdf_dir=$seriti_config['path']['base'].$seriti_config['path']['files'];
        $pdf_name=$company['name'].'_balance_sheet_'.$period['name'].'.pdf';
        $pdf_name=str_replace(' ','_',$pdf_name);
                
        $pdf=new seriti_pdf('Portrait','mm','A4');
        $pdf->AliasNbPages();
          
        $pdf->setup_layout($conn);
        //change setup system setting if there is one
        //$pdf->h1_title=array(33,33,33,'B',10,'',8,20,'L','NO',33,33,33,'B',12,20,180);
        //$pdf->bg_image=$logo;
        
        //$pdf->footer_text=$footer_text;

        //NB footer must be set before this
        $pdf->AddPage();

        $row_h=5;
                     
        $pdf->SetY(40);
        $pdf->change_font('H1');
        $pdf->Cell(50,$row_h,'BALANCE SHEET :',0,0,'R',0);
        $pdf->Cell(50,$row_h,$company['name'],0,0,'L',0);
        $pdf->Ln($row_h);
        $pdf->Cell(50,$row_h,'For period :',0,0,'R',0);
        $pdf->Cell(50,$row_h,$period['name'].' from '.$period['date_start'].' to '.$period['date_end'],0,0,'L',0);
        $pdf->Ln($row_h*2);
        
        
        //ASSETS
        if(count($assets!=0)) {
          $pdf->change_font('TEXT');
          $pdf->array_draw_table($assets,$row_h,$col_width,$col_type,'L');
          $pdf->Ln($row_h);
        }
        
        //LIABILITIES
        if(count($liability!=0)) {
          $pdf->change_font('TEXT');
          $pdf->array_draw_table($liability,$row_h,$col_width,$col_type,'L');
          $pdf->Ln($row_h);
        }
        
        //EQUITY
        if(count($equity!=0)) {
          $pdf->change_font('TEXT');
          $pdf->array_draw_table($equity,$row_h,$col_width,$col_type,'L');
          $pdf->Ln($row_h);
        }
        
        $pdf->change_font('H1');
        $pdf->Cell(50,$row_h,'Total Assets :',0,0,'R',0);
        $pdf->Cell(50,$row_h,number_format(($total_asset),2),0,0,'L',0);
        $pdf->Ln($row_h);
        $pdf->Cell(50,$row_h,'Total Liabilities & Equity :',0,0,'R',0);
        $pdf->Cell(50,$row_h,number_format(($total_equity+$total_liability),2),0,0,'L',0);
        $pdf->Ln($row_h);
        
        //finally create pdf file
        //$file_path=$pdf_dir.$pdf_name;
        //$pdf->Output($file_path,'F');   
        //or send to browser
        $pdf->Output($pdf_name,'D'); 
        exit; 
      }  
    }  
    
    return $html;  
  }
  
  public static function income_statement($conn,$company_id,$period_id,$options=array(),&$error_str) {
    global $seriti_config;
    
    $error_tmp='';
    $error_str='';
    $html='';
    
    if(!isset($options['format'])) $options['format']='HTML';
    if(!isset($options['zero_balances'])) $options['zero_balances']=false;
    
    //get company details
    $company=self::get_company($conn,$company_id);
    
    $period=self::setup_report_period($conn,'INCOME',$company_id,$period_id,$error_tmp);
    if($error_tmp!='') $error_str.='Period ERROR: '.$error_tmp;
    
    //********************
    
    //escape SQL variables
    $period_id=seriti_mysql::escape_sql($conn,$period_id);
    $company_id=seriti_mysql::escape_sql($conn,$company_id);
    
    //get all INCOME & EXPENSE accounts including hidden ones just in case there are lurking transactions somewhere
    if($error_str=='') {  
      $sql='SELECT account_id,name,type_id,description '.
           'FROM '.TABLE_PREFIX.'account '.
           'WHERE company_id = "'.$company_id.'" AND '.
                 '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%") ';
      $accounts=seriti_mysql::read_sql_array($sql,$conn);   
      if($accounts==0) $error_str.='NO income or expense accounts exist for Company['.$company_id.']!<br/>';   
    } 
    
    //process entries and generate balances excluding CLOSE transactions if any for period
    //NB cannot use balances as for a CLOSED period they will be zero
    if($error_str=='') {
      $balances=array();
      
      foreach($accounts as $account_id=>$account) {
        $debit_total=0;
        $credit_total=0;
        
        $sql='SELECT E.debit_credit,SUM(E.amount) '.
             'FROM '.TABLE_PREFIX.'entry AS E JOIN '.TABLE_PREFIX.'transact AS T ON(E.transact_id = T.transact_id) '.
             'WHERE E.account_id = "'.$account_id.'" AND T.type_id <> "CLOSE" AND '.
                   'E.date >= "'.$period['date_start'].'" AND E.date <= "'.$period['date_end'].'" '.
             'GROUP BY E.debit_credit ';
        $entry=seriti_mysql::read_sql_list($sql,$conn); 
        if($entry!=0) {
          if(isset($entry['D'])) $debit_total=$entry['D'];
          if(isset($entry['C'])) $credit_total=$entry['C'];
        }
      
        if(substr($account['type_id'],0,7)=='EXPENSE') {
          //DEBIT balances
          $balances[$account_id]=$debit_total-$credit_total; 
        } 
        
        if(substr($account['type_id'],0,6)=='INCOME')  {
          //CREDIT balances
          $balances[$account_id]=$credit_total-$debit_total; 
        }  
      }
      
    }
    
    //generate income statement
    if($error_str=='') {
      $income=array();
      $expense=array();
      $total_income=0.00;
      $total_expense=0.00;
      $net_income=0.00;
      
      //determine which account balances to show(ie exclude zero accounts for now)
      $ri=0;
      $income[0][$ri]='INCOME:';
      $income[1][$ri]='';
      $re=0;
      $expense[0][$re]='EXPENSE:';
      $expense[1][$re]='';
      foreach($accounts as $account_id=>$account) {
        if(substr($account['type_id'],0,6)==='INCOME' and $balances[$account_id]!=0) {
          $ri++;
          $income[0][$ri]=$account['name'];
          $income[1][$ri]=$balances[$account_id];
          //$income[$account_id]=$balances[$account_id];
          $total_income+=$balances[$account_id];
        } 
           
        if(substr($account['type_id'],0,7)==='EXPENSE' and $balances[$account_id]!=0) {
          $re++;
          $expense[0][$re]=$account['name'];
          $expense[1][$re]=$balances[$account_id];
          //$expense[$account_id]=$balances[$account_id];
          $total_expense+=$balances[$account_id];
        } 
      } 
      
      //add totals
      $ri++;
      $income[0][$ri]='CUSTOM_ROW';
      $income[1][$ri]='BLANK';
      $ri++;
      $income[0][$ri]='TOTAL Income';
      $income[1][$ri]=$total_income;
      $re++;
      $expense[0][$re]='CUSTOM_ROW';
      $expense[1][$re]='BLANK';
      $re++;
      $expense[0][$re]='TOTAL Expense';
      $expense[1][$re]=$total_expense;   
      
      $net_income=$total_income-$total_expense;
       
      //layout setup
      $row_h=7;//ignored for html
      $align='L';//ignored for html
      $col_width=array(100,100);
      $col_type=array('','DBL2');
      $html_options=array();
      $output=array();
      
      if($options['format']=='HTML') {
        $html.='<div><h1>'.COMPANY_NAME.' Income statement for '.$period['name'].'</h1>';
        $html.=seriti_html::array_draw_table($income,$row_h,$col_width,$col_type,$align,$html_options,$output);
        $html.='<br/>';
        $html.=seriti_html::array_draw_table($expense,$row_h,$col_width,$col_type,$align,$html_options,$output);
        $html.='<br/>';
        $html.='<table class="table  table-striped table-bordered table-hover table-condensed">';
        $html.='<tr><td width="50%"><b>NET INCOME</b></td><td align="right"><b>'.number_format($net_income,2).'</b></td></tr>';
        $html.='</table>';
          
        $html.='</div>';
      }
      
      if($options['format']==='CSV') {
        $csv_data='';
        $doc_name=$company['name'].'_income_statement_'.$period['name'].'.csv';
        $doc_name=str_replace(' ','_',$doc_name);
        
        if(count($income!=0)) {
          $csv_data.=seriti_csv::array_dump_csv($income);
          $csv_data.="\r\n";
        }
        
        if(count($expense!=0)) {
          $csv_data.=seriti_csv::array_dump_csv($expense);
          $csv_data.="\r\n";
        }
        
        seriti_doc::output_doc($csv_data,$doc_name,'DOWNLOAD','csv');
        exit;
      } 
      
      if($options['format']=='PDF') {
        $pdf_dir=$seriti_config['path']['base'].$seriti_config['path']['files'];
        $pdf_name=$company['name'].'_income_statement_'.$period['name'].'.pdf';
        $pdf_name=str_replace(' ','_',$pdf_name);
                
        $pdf=new seriti_pdf('Portrait','mm','A4');
        $pdf->AliasNbPages();
          
        $pdf->setup_layout($conn);
        //change setup system setting if there is one
        //$pdf->h1_title=array(33,33,33,'B',10,'',8,20,'L','NO',33,33,33,'B',12,20,180);
        //$pdf->bg_image=$logo;
        
        //$pdf->footer_text=$footer_text;

        //NB footer must be set before this
        $pdf->AddPage();

        $row_h=5;
                     
        $pdf->SetY(40);
        $pdf->change_font('H1');
        $pdf->Cell(50,$row_h,'INCOME STATEMENT :',0,0,'R',0);
        $pdf->Cell(50,$row_h,$company['name'],0,0,'L',0);
        $pdf->Ln($row_h);
        $pdf->Cell(50,$row_h,'For period :',0,0,'R',0);
        $pdf->Cell(50,$row_h,$period['name'].' from '.$period['date_start'].' to '.$period['date_end'],0,0,'L',0);
        $pdf->Ln($row_h*2);
        
        
        //INCOME
        if(count($income!=0)) {
          $pdf->change_font('TEXT');
          $pdf->array_draw_table($income,$row_h,$col_width,$col_type,'L');
          $pdf->Ln($row_h);
        }
        
        //EXPENSES
        if(count($expense!=0)) {
          $pdf->change_font('TEXT');
          $pdf->array_draw_table($expense,$row_h,$col_width,$col_type,'L');
          $pdf->Ln($row_h);
        }
        
        $pdf->change_font('H1');
        $pdf->Cell(50,$row_h,'NET INCOME :',0,0,'R',0);
        $pdf->Cell(50,$row_h,number_format(($net_income),2),0,0,'L',0);
        $pdf->Ln($row_h);
        
        
        //finally create pdf file
        //$file_path=$pdf_dir.$pdf_name;
        //$pdf->Output($file_path,'F');   
        //or send to browser
        $pdf->Output($pdf_name,'D'); 
        exit; 
      }
    }
    
    return $html;     
  }  
  
  public static function check_transaction_period($conn,$company_id,$date,&$error_str)  {
    $error_str='';
    
    $date=seriti_mysql::escape_sql($conn,$date);
    
    $sql='SELECT name,status,date_start,date_end FROM '.TABLE_PREFIX.'period '.
         'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" AND '.
               'date_start <= "'.$date.'" AND date_end >= "'.$date.'" LIMIT 1 ';
    $period=seriti_mysql::read_sql_record($sql,$conn);           
    if($period==0) {
      $error_str.='NO period exists for company['.$company_id.'] that includes date['.$date.']!';
    } else {
      if($period['status']==='CLOSED') $error_str.='Period['.$period['name'].'] is CLOSED!';
    } 
    
    if($error_str=='') return true; else return false;	   
  }  
  
  //check all periods are in date sequence and status sequence correct
  public static function check_period_sequence($conn,$company_id,$period_id,&$error_str) {
    $error_str='';
    $date_options['include_first']=False;
    $output=array();
        
    $sql='SELECT period_id AS id,period_id,name,date_start,date_end,status,company_id,period_id_previous '.
         'FROM '.TABLE_PREFIX.'period WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" '.
         'ORDER BY date_start ';
    $periods=seriti_mysql::read_sql_array($sql,$conn);
    if($periods!=0) {
      $p=0;
      foreach($periods as $id=>$period) {
        $p++;
        if($p>1) {
          //check that previous period_id is correctly assigned
          if($period_prev['period_id']!==$period['period_id_previous']) {
            $error_str.='Period['.$period['name'].'] previous period ID['.$period['period_id_previous'].'] '.
                        'is NOT['.$period_prev['period_id'].']<br/>'; 
          }  
          //check that date intervals merge exactly 
          $days=seriti_date::calc_days($period_prev['date_end'],$period['date_start'],'MYSQL',$date_options);
          if($days!=1) {
            $error_str.='Period['.$period['name'].'] start date['.$period['date_start'].'] is NOT day after '.
                        'previous period['.$period_prev['name'].'] end date['.$period_prev['date_end'].']!';
          } 
          //check that no OPEN periods exist before CLOSED periods
          if($period_prev['status']=='OPEN' and $period['status']=='CLOSED') {
            $error_str.='Period['.$period['name'].'] status['.$period['status'].'] is INVALID '.
                        'as previous period['.$period_prev['name'].'] status['.$period_prev['status'].'] is NOT CLOSED!';
          }
        }  
        
        if($id==$period_id) {
          $output['current']=$period;
          if($p>1) $output['previous']=$period_prev; else $output['previous']=0;
        }  
        
        if($period_id==$period['period_id_previous']) $output['next']=$period; 
                
        $period_prev=$period;
      }
      
      if(!isset($output['next'])) $output['next']=0;
    } 
    
    if($error_str=='') return $output; else return false;	    
  }  
  
  public static function get_company($conn,$company_id) {
    $sql='SELECT name,description,status,date_start,date_end,'.
                'vat_apply,vat_rate,vat_account_id,ret_account_id,calc_timestamp '.
         'FROM '.TABLE_PREFIX.'company '.
         'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" ';
    $company=seriti_mysql::read_sql_record($sql,$conn);
    if($company==0) die('INVALID Company ID['.$company_id.']');
    
    return $company;
  }  
  
  public static function process_new_transactions($conn,$type_id,$company_id,&$message_str,&$error_str) {
    $error_tmp='';
    $error_str='';
     
    $company=self::get_company($conn,$company_id);
        
    //transaction options
    $options['vat_apply']=$company['vat_apply'];
    $options['vat_rate']=$company['vat_rate'];
    $options['vat_account_id']=$company['vat_account_id'];
        
    seriti_mysql::execute_sql('START TRANSACTION',$conn,$error_tmp);
        
    $sql='SELECT transact_id,description,date,status '.
         'FROM '.TABLE_PREFIX.'transact '.
         'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" AND status = "NEW" ';
    if($type_id!=='ALL') $sql.='AND type_id = "'.seriti_mysql::escape_sql($conn,$type_id).'"  ';
    $sql.= 'ORDER BY date';
    $transact=seriti_mysql::read_sql_array($sql,$conn);
    if($transact!=0) {
      $message_str.='found '.count($transact).' of '.$type_id.' type transactions to process!';
      foreach($transact as $transact_id=>$data) {
        self::process_transaction($conn,$transact_id,$company_id,$options,$error_tmp);
        if($error_tmp!='') {
          $error_str.='Could not process transaction['.$transact_id.'] on['.$data['date'].']'.
                      $data['description'].': '.$error_tmp.'<br/>';
        }  
      }  
          
    }	else {
      $error_str.='No NEW transactions found to process!';
    }    	
    
    if($error_str=='') {
      seriti_mysql::execute_sql('COMMIT',$conn,$error_tmp);
      return true; 
    } else {
      seriti_mysql::execute_sql('ROLLBACK',$conn,$error_tmp);
      return false;	
    }  
  }
  
  public static function process_transaction($conn,$transact_id,$company_id,$options=array(),&$error_str) {
    $error_tmp='';
    $error_str='';
        
    if(!isset($options['vat_apply'])) $options['vat_apply']=false;
    if($options['vat_apply']) {
      if(!isset($options['vat_rate'])) $error_str.='Transaction requires VAT rate!<br/>';  
      if(!isset($options['vat_account_id'])) $error_str.='Transaction requires VAT account ID!<br/>';  
    }    
      
    $sql='SELECT transact_id,type_id,status,date,debit_credit,account_id_primary,account_id,amount,vat_inclusive, '.
                'debit_accounts,credit_accounts '.
         'FROM '.TABLE_PREFIX.'transact '.
         'WHERE transact_id = "'.seriti_mysql::escape_sql($conn,$transact_id).'" ';
    $transact=seriti_mysql::read_sql_record($sql,$conn);
    if($transact==0) {
      $error_str.='Transaction ID['.$transact_id.'] is INVALID!';
    } else { 
      //by default unless a valid transaction type 
      $valid=false;
      
      self::check_transaction_period($conn,$company_id,$transact['date'],$error_tmp);
      if($error_tmp!='') $error_str.='Transaction cannot be processed as:'.$error_tmp.'<br/>';
    } 
    
    if($error_str=='') { 
      $update_entry_json=false;
      $debit_accounts=array();
      $credit_accounts=array();
      
      if($transact['type_id']==='CUSTOM' or $transact['type_id']==='CLOSE') {
        $valid=true;
        
        $debit_accounts=json_decode($transact['debit_accounts'],true);
        $credit_accounts=json_decode($transact['credit_accounts'],true);
        if(is_null($debit_accounts)) $error_str.='Cannot decode debit accounts['.$transact['debit_accounts'].']<br/>';
        if(is_null($credit_accounts)) $error_str.='Cannot decode credit accounts['.$transact['credit_accounts'].']<br/>';
        
        if($error_str!='') $valid=false;
      }
            
      if($transact['type_id']==='CASH' or $transact['type_id']==='CREDIT') {
        $valid=true;
        $update_entry_json=true;
                
        //check if vat needs to be extracted
        $vat_adjust=false;
        if($transact['vat_inclusive'] and $options['vat_apply']) {
          $vat_adjust=true;
          $amount=floatval($transact['amount']);
          $base_amount=round($amount/(1+$options['vat_rate']/100),2);
          $vat_amount=$amount-$base_amount;
        } 
        
        //receiving cash          
        if($transact['debit_credit']=='C') {
          $debit_accounts[$transact['account_id_primary']]=$transact['amount'];
         
          if($vat_adjust) {
            $credit_accounts[$transact['account_id']]=$base_amount;
            $credit_accounts[$options['vat_account_id']]=$vat_amount;
          } else {
            $credit_accounts[$transact['account_id']]=$transact['amount'];
          }    
        }
        
        //paying cash
        if($transact['debit_credit']=='D') {
          $credit_accounts[$transact['account_id_primary']]=$transact['amount'];
          
          if($vat_adjust) {
            $debit_accounts[$transact['account_id']]=$base_amount;
            $debit_accounts[$options['vat_account_id']]=$vat_amount;
          } else {
            $debit_accounts[$transact['account_id']]=$transact['amount'];
          }  
        }
      }  
      
      //need to check all transacted accounts are active and total debits = total credits
      //NB: also check that transaction type = EQUITY if any equity accounts involved
      if($valid) {
        $sql='SELECT account_id,name,type_id FROM '.TABLE_PREFIX.'account '.
             'WHERE company_id = "'.seriti_mysql::escape_sql($conn,$company_id).'" ';
        $accounts=seriti_mysql::read_sql_array($sql,$conn);
        
        $total_debit=0;
        $total_credit=0;
        
        foreach($credit_accounts as $account_id=>$amount) {
          if(!isset($accounts[$account_id])) {
            $error_str.='Credit Account['.$accounts[$account_id]['name'].'] is INACTIVE, you cannot transact in this account!<br/>';  
          } 
             
          $total_credit+=$amount;
        }
        $total_credit=round($total_credit,2);
        
        foreach($debit_accounts as $account_id=>$amount) {
          if(!isset($accounts[$account_id])) {
            $error_str.='Debit Account['.$accounts[$account_id]['name'].'] is INACTIVE, you cannot transact in this account!<br/>';  
          } 
            
          $total_debit+=$amount;
        }
        $total_debit=round($total_debit,2);
        
        if($total_debit!==$total_credit) $error_str.='Total Debits['.$total_debit.'] NOT equal to Total Credits['.$total_credit.']!<br/>';
        
        if($error_str!='') $valid=false;  
      }  
      
      
      //process entries and transaction update
      if($valid) {      
        //remove any entries that may exist for the transaction
        $sql='DELETE FROM '.TABLE_PREFIX.'entry WHERE transact_id = "'.$transact_id.'" ';
        seriti_mysql::execute_sql($sql,$conn,$error_tmp); 
        if($error_tmp!='') $error_str.='Could NOT delete entries for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
        
        //process ledger entries
        foreach($credit_accounts as $account_id=>$amount) {
          self::insert_entry($conn,$transact_id,'C',$account_id,$amount,$transact['date'],$error_tmp);
          if($error_tmp!='') $error_str.='Could NOT process credit entry for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
        } 
        foreach($debit_accounts as $account_id=>$amount) {
          self::insert_entry($conn,$transact_id,'D',$account_id,$amount,$transact['date'],$error_tmp);
          if($error_tmp!='') $error_str.='Could NOT process debit entry for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
        }  
        
        //update transaction
        $update=array();
        $where=array('transact_id'=>$transact_id);
        
        $update['status']='OK';
        $update['date_process']=date('Y-m-d H:i:s');
        if($update_entry_json) {
          $update['debit_accounts']=json_encode($debit_accounts);
          $update['credit_accounts']=json_encode($credit_accounts);
        }  
        seriti_mysql::update_record($conn,TABLE_PREFIX.'transact',$update,$where,$error_tmp);
        if($error_tmp!='') $error_str.='Could NOT udate transaction['.$transact_id.'] details:'.$error_tmp.'<br/>';
      }  
    }	   	
  
    
    if($error_str=='') return true; else return false;	
  }
  
  public static function insert_entry($conn,$transact_id,$debit_credit,$account_id,$amount,$date,&$error_str) {
    $error_str='';
    $data=array();
    
    $data['transact_id']=$transact_id;
    $data['debit_credit']=$debit_credit;
    $data['account_id']=$account_id;
    $data['amount']=$amount;
    $data['date']=$date;
            
    $entry_id=seriti_mysql::insert_record($conn,TABLE_PREFIX.'entry',$data,$error_str);
    if($error_str=='') return true; else return false;	
	}

  public static function setup_default_accounts($conn,$company_id,&$error_str) {
    $error_tmp='';
    $error_str='';
    
    //can get these from user saved deafults at some point in the future
    //NB: assumes "LIABILITY_CURRENT" with "VAT" in name is VAT provisional account
    //NB2: assumes only one "EQUITY_EARNINGS" acc and is retained earnings account
    $acc_list=array();
    $acc_list[]=array('ASSET_CURRENT','Cash on hand','1000');
    $acc_list[]=array('ASSET_CURRENT_BANK','Bank account','1100');
    $acc_list[]=array('ASSET_CURRENT_DUE','Accounts receiveable','1200');
    $acc_list[]=array('LIABILITY_CURRENT','VAT provisional','2000');
    $acc_list[]=array('LIABILITY_CURRENT_CARD','Credit card account','2100');
    $acc_list[]=array('LIABILITY_CURRENT_DUE','Accounts payable','2200');
    $acc_list[]=array('EQUITY_OWNER','Owners equity','3000');
    $acc_list[]=array('EQUITY_EARNINGS','Retained earnings','3100');
    $acc_list[]=array('INCOME_SALES','Sales revenue','4000');
    $acc_list[]=array('INCOME_OTHER','Interest income','4100');
    $acc_list[]=array('EXPENSE_SALES','Cost of sales','5000');
    $acc_list[]=array('EXPENSE_FIXED','Office rental','5100');
    $acc_list[]=array('EXPENSE_FIXED','Wages','5110');
    $acc_list[]=array('EXPENSE_FIXED','Office supplies','5120');
    $acc_list[]=array('EXPENSE_FIXED','Communication','5130');
    $acc_list[]=array('EXPENSE_FIXED','Internet service providers','5140');
    $acc_list[]=array('EXPENSE_FIXED','Transport costs','5150');
    $acc_list[]=array('EXPENSE_FIXED','Office administration','5160');
    $acc_list[]=array('EXPENSE_FIXED','Bank fees','5170');
    $acc_list[]=array('EXPENSE_FIXED','Entertainment','5180');
    $acc_list[]=array('EXPENSE_FIXED','Office security','5190');
    $acc_list[]=array('EXPENSE_FIXED','Employee wages PAYE','5200');
        
    
    $company_id=seriti_mysql::escape_sql($conn,$company_id);
    
    $sql='SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
         'WHERE company_id = "'.$company_id.'" ';
    $count=seriti_mysql::read_sql_value($sql,$conn);
    
    if($count!=0) {
      $error_str.='Cannot setup default accounts as ['.$count.'] accounts already exist! '.
                  'Can only setup default accounts where NO accounts exist for company!';  
    } else {
      $sql='INSERT INTO '.TABLE_PREFIX.'account (company_id,type_id,name,abbreviation,status) VALUES ';
      foreach($acc_list as $acc) {
        $sql.='("'.COMPANY_ID.'","'.$acc[0].'","'.$acc[1].'","'.$acc[2].'","OK"),';
      }  
      //remove trailing ","
      $sql=substr($sql,0,-1);
      seriti_mysql::execute_sql($sql,$conn,$error_tmp); 
      if($error_tmp!='') {
        $error_str='Could NOT create default accounts!';   
      } else {  
        //add specialised accounts to company setup
        if($error_str=='') {
          $sql='SELECT account_id FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.$company_id.'" AND type_id = "LIABILITY_CURRENT" AND name LIKE "%VAT%" LIMIT 1 ';
          $vat_account_id=seriti_mysql::read_sql_value($sql,$conn);
          
          $sql='SELECT account_id FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.$company_id.'" AND type_id = "EQUITY_EARNINGS" LIMIT 1 ';
          $ret_account_id=seriti_mysql::read_sql_value($sql,$conn);
           
          $sql='UPDATE '.TABLE_PREFIX.'company SET '.
                       'vat_account_id = "'.$vat_account_id.'", ret_account_id = "'.$ret_account_id.'" '.
               'WHERE company_id = "'.$company_id.'" ';
          seriti_mysql::execute_sql($sql,$conn,$error_tmp); 
          if($error_tmp!='') $error_str='Could NOT setup company VAT account!'; 
        }
      } 
    }  
        
    if($error_str=='') return true; else return false;	
  }	
  
  public static function check_company_accounts($conn,$company_id,&$error_str) {
    $company_id=seriti_mysql::escape_sql($conn,$company_id);
    
    $sql='SELECT name,vat_account_id,ret_account_id '.
         'FROM '.TABLE_PREFIX.'company '.
         'WHERE company_id = "'.$company_id.'" ';
    $company=seriti_mysql::read_sql_record($sql,$conn);
    if($company['vat_account_id']==0) $error_str.='Company vat account NOT setup!<br/>';
    if($company['ret_account_id']==0) $error_str.='Company retained income account  NOT setup!<br/>';
    
    if($company['vat_account_id']!=0) {
      $sql='SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
           'WHERE company_id = "'.$company_id.'" AND account_id = "'.$company['vat_account_id'].'" AND '.
                 'type_id LIKE "LIABILITY_CURRENT%" AND status <> "HIDE" ';
      $count=seriti_mysql::read_sql_value($sql,$conn);
      if($count==0) $error_str.='Company VAT account id['.$company['vat_account_id'].'] not a valid Current LIABILITY account!';
    }  
    
    if($company['ret_account_id']!=0) {
      $sql='SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
           'WHERE company_id = "'.$company_id.'" AND account_id = "'.$company['ret_account_id'].'" AND '.
                 'type_id = "EQUITY_EARNINGS" AND status <> "HIDE" ';
      $count=seriti_mysql::read_sql_value($sql,$conn);
      if($count==0) $error_str.='Company Retained Earnings account id['.$company['ret_account_id'].'] not a valid EQUITY account!';
    }  
        
    $sql='SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
         'WHERE company_id = "'.$company_id.'" AND type_id = "ASSET_CURRENT_BANK" ';
    $count=seriti_mysql::read_sql_value($sql,$conn);
    if($count==0) $error_str.='Company needs at least one ASSET account with type ASSET_CURRENT_BANK!';
     
    if($error_str=='') return true; else return false;	  
  }
  
  public static function get_account($conn,$account_id,&$error_str) {
    $error_str='';
    
    $sql='SELECT * FROM '.TABLE_PREFIX.'account '.
         'WHERE account_id = "'.seriti_mysql::escape_sql($conn,$account_id).'" ';
    $account=seriti_mysql::read_sql_record($sql,$conn);
    if($account==0) $error_str.='Invalid account ID['.$account_id.']';
    
    return $account;   
  }  
  
}
?>
