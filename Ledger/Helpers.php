<?php
namespace App\Ledger;

use Exception;
use Seriti\Tools\Calc;
use Seriti\Tools\Csv;
use Seriti\Tools\Html;
use Seriti\Tools\Pdf;
use Seriti\Tools\Doc;
use Seriti\Tools\Date;
use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_DOCS;
use Seriti\Tools\STORAGE;


class Helpers {
    
    public static function openPeriod($db,$company_id,$period_id,&$error) {
        $error = '';
        $error_tmp = '';
        
        $periods = self::checkPeriodSequence($db,$company_id,$period_id,$error_tmp);
        if($error_tmp !== '') {
            $error .= 'Period sequence error:'.$error_tmp;
        } else {
            if($periods['next'] != 0 and $periods['next']['status'] === 'CLOSED') {
                $error .= 'Next period['.$periods['next']['name'].'] is CLOSED! '.
                          'You will need to OPEN all subsequent CLOSED periods first, starting with the most recent one.';
            }  
            
        }    
        
        //NB: Not necessary to delete previous CLOSE transactions as when period closed again any additional required
        //CLOSE transactions will be generated. Alternatively you can manually delete existing CLOSE transactions before closing
                
        //finally update period status
        if($error === '') {
            $sql = 'UPDATE '.TABLE_PREFIX.'period SET status = "OPEN" '.
                     'WHERE company_id = "'.$db->escapeSql($company_id).'" AND '.
                           'period_id = "'.$db->escapeSql($period_id).'" ';
            $db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') $error .= 'Could not OPEN period: '.$error_tmp;
        }  
         
        if($error === '') return true; else return false;       
    } 
    
    public static function closePeriod($db,$company_id,$period_id,&$error_str) {
        $error = '';
        $error_tmp = '';
        
        //get company details
        $company = self::getCompany($db,$company_id);
        
        //retained income account id
        $ret_account_id = $company['ret_account_id'];
        
        $periods = self::checkPeriodSequence($db,$company_id,$period_id,$error_tmp);
        if($error_tmp !== '') {
            $error .= 'Period sequence error:'.$error_tmp;
        } else {
            if($periods['previous'] != 0 and $periods['previous']['status'] !== 'CLOSED') {
                $error .= 'Previous period['.$periods['previous']['name'].'] is NOT CLOSED! '.
                          'You will need to CLOSE all previous periods first, starting with the earliest one.';
            } 
            
            if($periods['current']['status'] === 'CLOSED') {
                $error .= 'Current Period['.$periods['current']['name'].'] is already CLOSED!<br/>';
            }   
            
        } 
        //echo "company ID $company_id, period ID $period_id";   
        //print_r($periods);
        //exit;
        
        
        //calculate latest balances
        if($error === '') {
            $options = [];
            self::calculateBalances($db,$company_id,$period_id,$options,$error_tmp);
            if($error_tmp !== '') $error .= 'Could not calculate balances: '.$error_tmp;
        }
        
        //generate INCOME account closing entries to company Retained income account
        if($error === '') {
            $transact = [];
            $transact['company_id'] = $company_id;
            //NB: CLOSE transactions must be excluded from income statment
            $transact['type_id'] = 'CLOSE';
            $transact['date_create'] = date('Y-m-d');
            $transact['date'] = $periods['current']['date_end'];
            //Primary account for display but not used with type_id = CUSTOM/CLOSE
            $transact['account_id'] = $ret_account_id;
            $transact['debit_credit'] = 'C';
            
            $transact['description'] = 'CLOSE period['.$periods['current']['name'].'] INCOME accounts';
            $debit_accounts = [];
            $credit_accounts = [];
            
            $sql = 'SELECT B.account_id,B.account_balance '.
                   'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
                   'WHERE B.period_id = "'.$db->escapeSql($period_id).'"  AND '.
                         'A.type_id LIKE "INCOME%" AND B.account_balance <> 0 ';
            $balance_close = $db->readSqlList($sql);  
            if($balance_close != 0) {
                $close_total = 0;
                foreach($balance_close as $account_id=>$balance) {
                    $close_total+=$balance;
                    
                    if($balance > 0) {
                        $debit_accounts[$account_id] = $balance;
                    } else {  
                        $credit_accounts[$account_id] = abs($balance);
                    }  
                }
                             
                //possible that close_total could be 0
                if($close_total > 0) $credit_accounts[$ret_account_id] = $close_total;
                if($close_total < 0) $debit_accounts[$ret_account_id] = abs($close_total);
                
                $transact['amount'] = abs($close_total);
                $transact['debit_accounts'] = json_encode($debit_accounts);
                $transact['credit_accounts'] = json_encode($credit_accounts);
                
                //create transaction record
                $transact_id = $db->insertRecord(TABLE_PREFIX.'transact',$transact,$error_tmp);
                if($error_tmp !== '') {
                    $error .= 'Could NOT create INCOME accounts closing transaction:'.$error_tmp; 
                } else {  
                    //process transaction ledger entries
                    $options = [];
                    self::processTransaction($db,$transact_id,$company_id,$options,$error_tmp);
                    if($error_tmp !== '') {
                        $error .= 'Could NOT process INCOME accounts closing transaction['.$transact_id.']: '.$error_tmp.'<br/>';
                    } 
                }
                
            }
        }
        
        //generate EXPENSE account closing entries to company Retained income account
        if($error === '') {  
            //Primary account for display but not used with type_id = CLOSE/CUSTOM
            $transact['account_id'] = $ret_account_id;
            $transact['debit_credit'] = 'D';
            
            //NB other trasnaction details unchanged.
            $transact['description'] = 'CLOSE period['.$periods['current']['name'].'] EXPENSE accounts';
            $debit_accounts = [];
            $credit_accounts = [];
            
            $sql = 'SELECT B.account_id,B.account_balance '.
                   'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
                   'WHERE B.period_id = "'.$db->escapeSql($period_id).'"  AND '.
                         'A.type_id LIKE "EXPENSE%" AND B.account_balance <> 0 ';
            $balance_close = $db->readSqlList($sql);  
            if($balance_close != 0) {
                $close_total = 0;
                foreach($balance_close as $account_id=>$balance) {
                    $close_total+=$balance;
                    
                    if($balance > 0) {
                        $credit_accounts[$account_id] = $balance;
                    } else {  
                        $debit_accounts[$account_id] = abs($balance);
                    }  
                }
                
                //possible that close_total could be 0
                if($close_total > 0) $debit_accounts[$ret_account_id] = $close_total;
                if($close_total < 0) $credit_accounts[$ret_account_id] = abs($close_total);
                
                $transact['amount'] = abs($close_total);
                $transact['debit_accounts'] = json_encode($debit_accounts);
                $transact['credit_accounts'] = json_encode($credit_accounts);
                
                //create transaction record
                $transact_id = $db->insertRecord(TABLE_PREFIX.'transact',$transact,$error_tmp);
                if($error_tmp !== '') {
                    $error .= 'Could NOT create EXPENSE accounts closing transaction!'; 
                } else {  
                    //process transaction ledger entries
                    $options = [];
                    self::processTransaction($db,$transact_id,$company_id,$options,$error_tmp);
                    if($error_tmp !== '') {
                        $error .= 'Could not process EXPENSE accounts closing transaction['.$transact_id.']: '.$error_tmp.'<br/>';
                    } 
                }
            }
        }  
        
        //calculate closing balances including closing transactions 
        if($error === '') {
            $options = [];
            self::calculateBalances($db,$company_id,$period_id,$options,$error_tmp);
            if($error_tmp !== '') $error .= 'Could not calculate closing balances: '.$error_tmp;
        }
        
        //finally update period status
        if($error === '') {
            $sql = 'UPDATE '.TABLE_PREFIX.'period SET status = "CLOSED" '.
                   'WHERE period_id = "'.$db->escapeSql($period_id).'" ';
            $db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') $error .= 'Could not OPEN period['.$period_id.']';
        }  
         
        if($error === '') return true; else return false;       
    }  
    
    public static function calculateBalances($db,$company_id,$period_id,$options = [],&$error) {
        $error = '';
        
        //escape SQL variables
        $period_id = $db->escapeSql($period_id);
        $company_id = $db->escapeSql($company_id);
        
        //get period record
        $sql = 'SELECT period_id,name,date_start,date_end,period_id_previous,company_id,status '.
               'FROM '.TABLE_PREFIX.'period WHERE period_id = "'.$period_id.'" ';
        $period = $db->readSqlRecord($sql); 
        if($period == 0) $error .= 'INVALID balance period ID['.$period_id.']<br/>';
        
        //basic validation
        if($error === '') {
            if($period['company_id'] != $company_id) {
                $error .= 'Period['.$period_id.'] company['.$period['company_id'].'] not same as balance company['.$company_id.']<br/>';
            } 
        }
        
        //get all accounts including hidden ones just in case there are lurking transactions/balances somewhere
        if($error === '') {  
            $sql = 'SELECT account_id,name,type_id,description '.
                   'FROM '.TABLE_PREFIX.'account '.
                   'WHERE company_id = "'.$company_id.'" '.
                   'ORDER BY type_id ';  
            $accounts = $db->readSqlArray($sql);   
            if($accounts == 0) $error .= 'NO accounts exist for Company['.$company_id.']!<br/>';   
        } 
        
        //get opening balances
        if($error === '') {
            if($period['period_id_previous'] != 0) {
                $sql = 'SELECT account_id,account_balance FROM '.TABLE_PREFIX.'balance '.
                       'WHERE period_id = "'.$period['period_id_previous'].'" ';
                $balance_open = $db->readSqlList($sql);  
                if($balance_open == 0) {
                    $error .= 'Previous Period ID['.$period['period_id_previous'].'] has NO balances!';   
                    //NB: can allow in some cases? will need to implement via $options then reset $balance_open = [];
                }    
            } else {
                $balance_open = [];
            }
        }
        
        //process entries and generate closing balances
        if($error === '') {
            $balance_close = [];
            
            foreach($accounts as $account_id => $account) {
                $debit_total = 0;
                $credit_total = 0;
                
                $sql = 'SELECT debit_credit,SUM(amount) FROM '.TABLE_PREFIX.'entry '.
                       'WHERE account_id = "'.$account_id.'" AND '.
                             'date >= "'.$period['date_start'].'" AND date <= "'.$period['date_end'].'" '.
                       'GROUP BY debit_credit ';
                $entry = $db->readSqlList($sql); 
                if($entry != 0) {
                    if(isset($entry['D'])) $debit_total = $entry['D'];
                    if(isset($entry['C'])) $credit_total = $entry['C'];
                }
            
                if(substr($account['type_id'],0,5) === 'ASSET' or substr($account['type_id'],0,7) === 'EXPENSE') {
                    //DEBIT balances
                    $balance_close[$account_id] = $debit_total-$credit_total; 
                } else {
                    //CREDIT balances
                    $balance_close[$account_id] = $credit_total-$debit_total; 
                }  
                
                //bring in any opening balances
                if(isset($balance_open[$account_id])) {
                    $balance_close[$account_id] = $balance_open[$account_id]+$balance_close[$account_id];
                } 
                
                //remove any empty balances
                if($balance_close[$account_id] == 0) unset($balance_close[$account_id]);
            }
            
        } 
        
        //save balances to db
        if($error === '') {
            //remove all old balance records
            $sql = 'DELETE FROM '.TABLE_PREFIX.'balance '.
                   'WHERE period_id = "'.$period_id.'" ';
            $db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') $error .= 'Could NOT delete closing balances for period['.$period_id.']';
            //create new records
            foreach($balance_close as $account_id => $balance) {
                $sql = 'INSERT INTO '.TABLE_PREFIX.'balance (period_id,account_id,account_balance ) '.
                       'VALUES("'.$period_id.'","'.$account_id.'","'.$balance.'")';
                $db->executeSql($sql,$error_tmp);
                if($error_tmp !== '') $error .= 'Could NOT insert closing balances for account['.$account_id.']';
            }
        } 
        
        //save balance time stamp to Company
        if($error === '') {
            $sql = 'UPDATE '.TABLE_PREFIX.'company SET calc_timestamp = NOW() '.
                     'WHERE company_id = "'.$company_id.'" ';
            $db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') $error .= 'Could NOT update company timestamp!';     
        }  
    
        if($error === '') return true; else return false;     
    }
    
    public static function setupReportPeriod($db,$report,$company_id,$period_id,&$error) {
        $error = '';
        $error_tmp = '';
        
        
        $sql = 'SELECT name,status,date_start,date_end,company_id '.
               'FROM '.TABLE_PREFIX.'period WHERE period_id = "'.$db->escapeSql($period_id).'" ';
        $period = $db->readSqlRecord($sql);
        if($period == 0) {
            $error .= 'INVALID period['.$period_id.']';
        } else {
            if($period['company_id'] != $company_id) $error .= 'Period company['.$period['company_id'].'] NOT VALID!'; 
        }    
        
        if($report === 'BALANCE') {
            //check if any transactions processed since last calculateBalances()
            /* disabled as calc_timestamp needs to be moved to gl_period table
            $sql_company='(SELECT calc_timestamp FROM '.TABLE_PREFIX.'company '.
                                        'WHERE company_id = "'.$db->escapeSql($company_id).'")';
            $sql_transact='(SELECT MAX(date_process) FROM '.TABLE_PREFIX.'transact '.
                                         'WHERE company_id = "'.$db->escapeSql($company_id).'")';
            //returns transact timestamp - balance calc timestamp
            $sql = 'SELECT TIMESTAMPDIFF(SECOND,'.$sql_company.','.$sql_transact.')';
            $seconds=$db->readSqlValue($sql);
            if($seconds==null) $seconds=100;
            */
            
            //force recalc for now
            $seconds = 100;
                     
            //calculate balances if period not closed AND last calculation out of date
            if($error === '') {
                if($period['status'] !== 'CLOSED' and $seconds > 0) {
                    $options = [];
                    self::calculateBalances($db,$company_id,$period_id,$options,$error_tmp);
                    if($error_tmp !== '') {
                        $error .= 'Could not generate trial balance for period['.$period['name'].']:'.$error_tmp;
                    }  
                }   
            }
        }  
        
        if($error === '') return $period; else return false;    
    }
    
    public static function balanceSheet($db,$company_id,$period_id,$options=[],&$error) {
        $error_tmp = '';
        $error = '';
        $html = '';
        
        $curr_symbol = 'R';   
        
        //get company details
        $company = self::getCompany($db,$company_id);
        
        if(!isset($options['format'])) $options['format'] = 'HTML';
        
        $period = self::setupReportPeriod($db,'BALANCE',$company_id,$period_id,$error_tmp);
        if($error_tmp !== '') $error .= 'Period ERROR: '.$error_tmp;
             
        //get period balances
        if($error === '') {
            $sql = 'SELECT B.account_id,B.account_balance,A.type_id,A.name,A.description '.
                   'FROM '.TABLE_PREFIX.'balance AS B JOIN '.TABLE_PREFIX.'account AS A ON(B.account_id = A.account_id) '.
                   'WHERE B.period_id = "'.$db->escapeSql($period_id).'" '.
                   'ORDER BY A.type_id ';
            $balances = $db->readSqlArray($sql);  
            if($balances == 0) $error .= 'NO balances found for period['.$period['name'].']!';   
        } 
        
        //generate balance sheet
        if($error === '') {
            $data = [];
            $income = 0.00;
            $expenses = 0.00;
            $open_pl = 0.00;
            
            //setup empty balance sheet account arrays
            foreach(ACC_TYPE as $type_id=>$name) {
                if(substr($type_id,0,6) !== 'INCOME' and substr($type_id,0,7) !== 'EXPENSE') { 
                    $data[$type_id] = []; 
                }  
            }  
                        
            //organise balances into type_id arrays
            foreach($balances as $account_id=>$balance) {
                $data[$balance['type_id']][$account_id] = $balance;
                
                //NB: should be zero if period CLOSED
                if(substr($balance['type_id'],0,6) === 'INCOME') $income+=floatval($balance['account_balance']);
                if(substr($balance['type_id'],0,7) === 'EXPENSE') $expenses+=floatval($balance['account_balance']);
            } 
            $open_pl = $income-$expenses;
            
            //prepare account arrays for html or pdf      
            $total_asset = 0.00;
            $assets = [];
            $r = 0;
            $assets[0][$r] = 'ASSETS:';
            $assets[1][$r] = '';
            //get all asset balances
            foreach($data as $type_id=>$data_arr) {
                if((substr($type_id,0,5) === 'ASSET') and count($data_arr)) {
                    $rt = 0;
                    $total = 0;
                    foreach($data_arr as $account_id=>$balance) {
                        $r++;
                        $rt++;
                        $assets[0][$r] = $balance['name'];
                        $assets[1][$r] = $balance['account_balance'];
                        $total+=floatval($balance['account_balance']);
                    } 
                    $total_asset+=$total;
                    if($rt > 1) {
                        $r++;
                        $assets[0][$r] = 'CUSTOM_ROW';
                        $assets[1][$r] = 'BLANK';
                        $r++;
                        $assets[0][$r] = 'Total '.ACC_TYPE[$type_id].':';
                        $assets[1][$r] = $total;
                    }  
                } 
            }
            //show total assets
            if($total_asset != 0) {
                $r++;
                $assets[0][$r] = 'CUSTOM_ROW';
                $assets[1][$r] = 'BLANK';
                $r++;
                $assets[0][$r] = 'Total ASSETS:';
                $assets[1][$r] = $total_asset; 
            } else {
                $assets[1][$r] = 'No asset balances'; 
            }   
            
            
            $total_liability = 0.00;
            $liability = [];
            $r = 0;
            $liability[0][$r] = 'LIABILITIES:';
            $liability[1][$r] = '';
            //get all liability balances
            foreach($data as $type_id=>$data_arr) {
                if((substr($type_id,0,9) === 'LIABILITY') and count($data_arr)) {
                    $rt = 0;
                    $total = 0;
                    foreach($data_arr as $account_id=>$balance) {
                        $r++;
                        $rt++;
                        $liability[0][$r] = $balance['name'];
                        $liability[1][$r] = $balance['account_balance'];
                        $total+=floatval($balance['account_balance']);
                    } 
                    $total_liability+=$total;
                    if($rt > 1) {
                        $r++;
                        $liability[0][$r] = 'CUSTOM_ROW';
                        $liability[1][$r] = 'BLANK';
                        $r++;
                        $liability[0][$r] = 'Total '.ACC_TYPE[$type_id].':';
                        $liability[1][$r] = $total;
                    }  
                } 
            }
            //show total liabilities
            if($total_liability!=0) {
                $r++;
                $liability[0][$r] = 'CUSTOM_ROW';
                $liability[1][$r] = 'BLANK';
                $r++;
                $liability[0][$r] = 'Total LIABILITIES:';
                $liability[1][$r] = $total_liability; 
            } else {
                $liability[1][$r] = 'No liability balances'; 
            }
            
            $total_equity = 0.00;
            $equity = [];
            $r = 0;
            $equity[0][$r] = 'EQUITY:';
            $equity[1][$r] = '';
            //get all asset balances
            foreach($data as $type_id=>$data_arr) {
                if((substr($type_id,0,6) === 'EQUITY') and count($data_arr)) {
                    $rt = 0;
                    $total = 0;
                    foreach($data_arr as $account_id=>$balance) {
                        $r++;
                        $rt++;
                        $equity[0][$r] = $balance['name'];
                        $equity[1][$r] = $balance['account_balance'];
                        $total+=floatval($balance['account_balance']);
                    } 
                    $total_equity+=$total;
                    if($rt > 1) {
                        $r++;
                        $equity[0][$r] = 'CUSTOM_ROW';
                        $equity[1][$r] = 'BLANK';
                        $r++;
                        $equity[0][$r] = 'Total '.ACC_TYPE[$type_id].':';
                        $equity[1][$r] = $total;
                    } 
                     
                } 
            }
            
            //insert any current PL for non closed periods
            if($open_pl != 0.00) {
                $r++;
                $equity[0][$r] = 'CUSTOM_ROW';
                $equity[1][$r] = 'BLANK';
                $r++;
                $equity[0][$r] = 'OPEN P&L(Income-Expenses)';
                $equity[1][$r] = $open_pl;
                $total_equity+=$open_pl;
            }
            
            //show total assets
            if($total_equity != 0) {
                $r++;
                $equity[0][$r] = 'CUSTOM_ROW';
                $equity[1][$r] = 'BLANK';
                $r++;
                $equity[0][$r] = 'Total EQUITY:';
                $equity[1][$r] = $total_equity; 
            } else {
                $equity[1][$r] = 'No equity balances'; 
            }  
            
            //left and right balance sheet totals
            $left_total = $total_asset; 
            $right_total = $total_equity+$total_liability;
            
            //layout setup
            $row_h = 7;//ignored for html
            $align = 'L';//ignored for html
            $col_width = array(100,100);
            $col_type = array('','DBL2');
            $html_options = [];
            $output = [];
                                    
            if($options['format']=='HTML') {
                $left_col = Html::arrayDrawTable($assets,$row_h,$col_width,$col_type,$align,$html_options,$output);

                $right_col = Html::arrayDrawTable($liability,$row_h,$col_width,$col_type,$align,$html_options,$output);
                $right_col .= '<br/>'; 
                $right_col .=  Html::arrayDrawTable($equity,$row_h,$col_width,$col_type,$align,$html_options,$output);
                
                $html = '<table class="table  table-striped table-bordered table-hover table-condensed">'.
                        '<tr valign="top"><td>'.$left_col.'</td><td valign="top">'.$right_col.'</td></tr>'.
                        '<tr><td align="right">Balance: '.number_format($left_total,2).'</td>'.
                                '<td align="right">Balance: '.number_format($right_total,2).'</td></tr>'.
                        '</table>';
            }   
            
            if($options['format'] === 'CSV') {
                $csv_data = '';
                $doc_name = $company['name'].'_balance_sheet_'.$period['name'].'.csv';
                $doc_name = str_replace(' ','_',$doc_name);
                
                if(count($assets != 0)) {
                    $csv_data .=  Csv::arrayDumpCsv($assets);
                    $csv_data .= "\r\n";
                }
                
                if(count($liability!=0)) {
                    $csv_data .=  Csv::arrayDumpCsv($liability);
                    $csv_data .= "\r\n";
                }
                
                if(count($equity!=0)) {
                    $csv_data.= Csv::arrayDumpCsv($equity);
                    $csv_data.= "\r\n";
                }
                
                
                Doc::outputDoc($csv_data,$doc_name,'DOWNLOAD','csv');
                exit;
            } 
            
            if($options['format'] === 'PDF') {
                $pdf_dir = BASE_UPLOAD.UPLOAD_DOCS;
                $pdf_name = $company['name'].'_balance_sheet_'.$period['name'].'.pdf';
                $pdf_name = str_replace(' ','_',$pdf_name);
                                
                $pdf = new Pdf('Portrait','mm','A4');
                $pdf->AliasNbPages();
                    
                $pdf->setupLayout(['db'=>$db]);
                //change setup system setting if there is one
                //$pdf->h1_title=array(33,33,33,'B',10,'',8,20,'L','NO',33,33,33,'B',12,20,180);
                //$pdf->bg_image=$logo;
                
                //$pdf->footer_text=$footer_text;

                //NB footer must be set before this
                $pdf->AddPage();

                $row_h = 5;
                                         
                $pdf->SetY(40);
                $pdf->changeFont('H1');
                $pdf->Cell(50,$row_h,'Balance Sheet :',0,0,'R',0);
                $pdf->Cell(50,$row_h,$company['name'],0,0,'L',0);
                $pdf->Ln($row_h);
                $pdf->Cell(50,$row_h,'For period :',0,0,'R',0);
                $pdf->Cell(50,$row_h,$period['name'].' from '.$period['date_start'].' to '.$period['date_end'],0,0,'L',0);
                $pdf->Ln($row_h*2);
                
                
                //ASSETS
                if(count($assets!=0)) {
                    $pdf->changeFont('TEXT');
                    $pdf->arrayDrawTable($assets,$row_h,$col_width,$col_type,'L');
                    $pdf->Ln($row_h);
                }
                
                //LIABILITIES
                if(count($liability!=0)) {
                    $pdf->changeFont('TEXT');
                    $pdf->arrayDrawTable($liability,$row_h,$col_width,$col_type,'L');
                    $pdf->Ln($row_h);
                }
                
                //EQUITY
                if(count($equity!=0)) {
                    $pdf->changeFont('TEXT');
                    $pdf->arrayDrawTable($equity,$row_h,$col_width,$col_type,'L');
                    $pdf->Ln($row_h);
                }
                
                $pdf->changeFont('H1');
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
    
    public static function incomeStatement($db,$company_id,$period_id,$options=[],&$error) {
        $error_tmp = '';
        $error = '';
        $html = '';
        
        if(!isset($options['format'])) $options['format'] = 'HTML';
        if(!isset($options['zero_balances'])) $options['zero_balances'] = false;
        
        //get company details
        $company = self::getCompany($db,$company_id);
        
        $period = self::setupReportPeriod($db,'INCOME',$company_id,$period_id,$error_tmp);
        if($error_tmp !== '') $error .= 'Period ERROR: '.$error_tmp;
        
        //********************
        
        //escape SQL variables
        $period_id = $db->escapeSql($period_id);
        $company_id = $db->escapeSql($company_id);
        
        //get all INCOME & EXPENSE accounts including hidden ones just in case there are lurking transactions somewhere
        if($error === '') {  
            $sql = 'SELECT account_id,name,type_id,description '.
                   'FROM '.TABLE_PREFIX.'account '.
                   'WHERE company_id = "'.$company_id.'" AND '.
                        '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%") ';
            $accounts = $db->readSqlArray($sql);   
            if($accounts == 0) $error .= 'NO income or expense accounts exist for Company['.$company_id.']!<br/>';   
        } 
        
        //process entries and generate balances excluding CLOSE transactions if any for period
        //NB cannot use balances as for a CLOSED period they will be zero
        if($error === '') {
            $balances = [];
            
            foreach($accounts as $account_id=>$account) {
                $debit_total = 0;
                $credit_total = 0;
                
                $sql = 'SELECT E.debit_credit,SUM(E.amount) '.
                       'FROM '.TABLE_PREFIX.'entry AS E JOIN '.TABLE_PREFIX.'transact AS T ON(E.transact_id = T.transact_id) '.
                       'WHERE E.account_id = "'.$account_id.'" AND T.type_id <> "CLOSE" AND '.
                             'E.date >= "'.$period['date_start'].'" AND E.date <= "'.$period['date_end'].'" '.
                       'GROUP BY E.debit_credit ';
                $entry = $db->readSqlList($sql); 
                if($entry != 0) {
                    if(isset($entry['D'])) $debit_total = $entry['D'];
                    if(isset($entry['C'])) $credit_total = $entry['C'];
                }
            
                if(substr($account['type_id'],0,7) === 'EXPENSE') {
                    //DEBIT balances
                    $balances[$account_id] = $debit_total-$credit_total; 
                } 
                
                if(substr($account['type_id'],0,6) === 'INCOME')  {
                    //CREDIT balances
                    $balances[$account_id] = $credit_total-$debit_total; 
                }  
            }
            
        }
        
        //generate income statement
        if($error === '') {
            $income = [];
            $expense = [];
            $total_income = 0.00;
            $total_expense = 0.00;
            $net_income = 0.00;
            
            //determine which account balances to show(ie exclude zero accounts for now)
            $ri = 0;
            $income[0][$ri] = 'INCOME:';
            $income[1][$ri] = '';
            $re = 0;
            $expense[0][$re] = 'EXPENSE:';
            $expense[1][$re] = '';
            foreach($accounts as $account_id=>$account) {
                if(substr($account['type_id'],0,6) === 'INCOME' and $balances[$account_id] != 0) {
                    $ri++;
                    $income[0][$ri] = $account['name'];
                    $income[1][$ri] = $balances[$account_id];
                    //$income[$account_id] = $balances[$account_id];
                    $total_income+=$balances[$account_id];
                } 
                     
                if(substr($account['type_id'],0,7) === 'EXPENSE' and $balances[$account_id] != 0) {
                    $re++;
                    $expense[0][$re] = $account['name'];
                    $expense[1][$re] = $balances[$account_id];
                    //$expense[$account_id] = $balances[$account_id];
                    $total_expense+=$balances[$account_id];
                } 
            } 
            
            //add totals
            $ri++;
            $income[0][$ri] = 'CUSTOM_ROW';
            $income[1][$ri] = 'BLANK';
            $ri++;
            $income[0][$ri] = 'TOTAL Income';
            $income[1][$ri] = $total_income;
            $re++;
            $expense[0][$re] = 'CUSTOM_ROW';
            $expense[1][$re] = 'BLANK';
            $re++;
            $expense[0][$re] = 'TOTAL Expense';
            $expense[1][$re] = $total_expense;   
            
            $net_income = $total_income-$total_expense;
             
            //layout setup
            $row_h = 7;//ignored for html
            $align = 'L';//ignored for html
            $col_width = array(100,100);
            $col_type = array('','DBL2');
            $html_options = [];
            $output = [];
            
            if($options['format'] === 'HTML') {
                $html .= '<div><h1>'.COMPANY_NAME.' Income statement for '.$period['name'].'</h1>';
                $html .=  Html::arrayDrawTable($income,$row_h,$col_width,$col_type,$align,$html_options,$output);
                $html .= '<br/>';
                $html .=  Html::arrayDrawTable($expense,$row_h,$col_width,$col_type,$align,$html_options,$output);
                $html .= '<br/>';
                $html .= '<table class="table  table-striped table-bordered table-hover table-condensed">';
                $html .= '<tr><td width="50%"><b>NET INCOME</b></td><td align="right"><b>'.number_format($net_income,2).'</b></td></tr>';
                $html .= '</table>';
                    
                $html .= '</div>';
            }
            
            if($options['format'] === 'CSV') {
                $csv_data = ''; 
                $doc_name = $company['name'].'_income_statement_'.$period['name'].'.csv';
                $doc_name = str_replace(' ','_',$doc_name);
                
                if(count($income != 0)) {
                    $csv_data .= Csv::arrayDumpCsv($income);
                    $csv_data .= "\r\n";
                }
                
                if(count($expense != 0)) {
                    $csv_data .= Csv::arrayDumpCsv($expense);
                    $csv_data .= "\r\n";
                }
                
                Doc::outputDoc($csv_data,$doc_name,'DOWNLOAD','csv');
                exit;
            } 
            
            if($options['format'] === 'PDF') {
                $pdf_dir = BASE_UPLOAD.UPLOAD_DOCS;
                $pdf_name = $company['name'].'_income_statement_'.$period['name'].'.pdf';
                $pdf_name = str_replace(' ','_',$pdf_name);
                                
                $pdf = new Pdf('Portrait','mm','A4');
                $pdf->AliasNbPages();
                    
                $pdf->setupLayout(['db'=>$db]);
                //change setup system setting if there is one
                //$pdf->h1_title=array(33,33,33,'B',10,'',8,20,'L','NO',33,33,33,'B',12,20,180);
                //$pdf->bg_image=$logo;
                
                //$pdf->footer_text=$footer_text;

                //NB footer must be set before this
                $pdf->AddPage();

                $row_h = 5;
                                         
                $pdf->SetY(40);
                $pdf->changeFont('H1');
                $pdf->Cell(50,$row_h,'Income statement :',0,0,'R',0);
                $pdf->Cell(50,$row_h,$company['name'],0,0,'L',0);
                $pdf->Ln($row_h);
                $pdf->Cell(50,$row_h,'For period :',0,0,'R',0);
                $pdf->Cell(50,$row_h,$period['name'].' from '.$period['date_start'].' to '.$period['date_end'],0,0,'L',0);
                $pdf->Ln($row_h*2);
                
                
                //INCOME
                if(count($income != 0)) {
                    $pdf->changeFont('TEXT');
                    $pdf->arrayDrawTable($income,$row_h,$col_width,$col_type,'L');
                    $pdf->Ln($row_h);
                }
                
                //EXPENSES
                if(count($expense != 0)) {
                    $pdf->changeFont('TEXT');
                    $pdf->arrayDrawTable($expense,$row_h,$col_width,$col_type,'L');
                    $pdf->Ln($row_h);
                }
                
                $pdf->changeFont('H1');
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
    
    public static function checkTransactionPeriod($db,$company_id,$date,&$error)  {
        $error = '';
        
        $date = $db->escapeSql($date);
        
        $sql = 'SELECT name,status,date_start,date_end FROM '.TABLE_PREFIX.'period '.
               'WHERE company_id = "'.$db->escapeSql($company_id).'" AND '.
                     'date_start <= "'.$date.'" AND date_end >= "'.$date.'" LIMIT 1 ';
        $period = $db->readSqlRecord($sql);           
        if($period == 0) {
            $error .= 'NO period exists for company['.$company_id.'] that includes date['.$date.']!';
        } else {
            if($period['status'] === 'CLOSED') $error .= 'Period['.$period['name'].'] is CLOSED!';
        } 
        
        if($error === '') return true; else return false;     
    }  
    
    //check all periods are in date sequence and status sequence correct
    public static function checkPeriodSequence($db,$company_id,$period_id,&$error) {
        $error = '';
        $date_options['include_first'] = False;
        $output = [];
                
        $sql = 'SELECT period_id AS id,period_id,name,date_start,date_end,status,company_id,period_id_previous '.
               'FROM '.TABLE_PREFIX.'period WHERE company_id = "'.$db->escapeSql($company_id).'" '.
               'ORDER BY date_start ';
        $periods = $db->readSqlArray($sql);
        if($periods != 0) {
            $p = 0;
            foreach($periods as $id=>$period) {
                $p++;
                if($p > 1) {
                    //check that previous period_id is correctly assigned
                    if($period_prev['period_id'] !== $period['period_id_previous']) {
                        $error .= 'Period['.$period['name'].'] previous period ID['.$period['period_id_previous'].'] '.
                                                'is NOT['.$period_prev['period_id'].']<br/>'; 
                    }  
                    //check that date intervals merge exactly 
                    $days = Date::calcDays($period_prev['date_end'],$period['date_start'],'MYSQL',$date_options);
                    if($days != 1) {
                        $error .= 'Period['.$period['name'].'] start date['.$period['date_start'].'] is NOT day after '.
                                                'previous period['.$period_prev['name'].'] end date['.$period_prev['date_end'].']!';
                    } 
                    //check that no OPEN periods exist before CLOSED periods
                    if($period_prev['status'] === 'OPEN' and $period['status'] === 'CLOSED') {
                        $error .= 'Period['.$period['name'].'] status['.$period['status'].'] is INVALID '.
                                                'as previous period['.$period_prev['name'].'] status['.$period_prev['status'].'] is NOT CLOSED!';
                    }
                }  
                
                if($id == $period_id) {
                    $output['current'] = $period;
                    if($p > 1) $output['previous'] = $period_prev; else $output['previous']=0;
                }  
                
                if($period_id == $period['period_id_previous']) $output['next'] = $period; 
                                
                $period_prev = $period;
            }
            
            if(!isset($output['next'])) $output['next'] = 0;
        } 
        
        if($error === '') return $output; else return false;       
    }  
    
    public static function addPeriod($db,$company_id,$tax_year,&$error) {
        $error = '';

        $date_start = ($tax_year-1).'-03-01';
        $date_end = $tax_year.'-02-01';
        $days = Date::daysInMonth($date_end);
        $date_end = $tax_year.'-02-'.$days;
    
        $sql = 'SELECT MIN(date_start) AS first_date, MAX(date_end) AS last_date  '.
               'FROM '.TABLE_PREFIX.'period '.
               'WHERE company_id = "'.$db->escapeSql($company_id).'" ';
        $range = $db->readSqlRecord($sql);

        $period_id_previous = 0;
        $update_period_id = 0;
        $period_status = 'OPEN';
        if($range != 0){
            $sql = 'SELECT period_id,status  '.
                   'FROM '.TABLE_PREFIX.'period '.
                   'WHERE company_id = "'.$db->escapeSql($company_id).'" '.
                   'ORDER BY date_start ';
            $periods = $db->readSqlList($sql);
            $period_keys = array_keys($periods);


            if(Date::dateInRange($range['first_date'],$range['last_date'],$date_start) or 
               Date::dateInRange($range['first_date'],$range['last_date'],$date_end)) {
                $error .= 'Your period from['.$date_start.'] to ['.$date_end.'] '.
                          'Cannot overlap existing company periods starting['.$range['first_date'].'] and ending['.$range['last_date'].'] ';
            } else {
                if(Date::calcDays($date_start,$range['first_date']) > 0) {
                    //before current periods, need to update period_id_prev
                    $update_period_id = array_shift($period_keys);
                    $period_status = $periods[$update_period_id];
                } else {
                    //after current periods
                    $period_id_previous = array_pop($period_keys);
                }
            }
        } 

        if($error === '') {
            $data = [];
            $data['company_id'] = $company_id;
            $data['date_start'] = $date_start;
            $data['date_end'] = $date_end;
            $data['name'] = 'Tax Year '.$tax_year;
            $data['status'] = $period_status;
            $data['period_id_previous'] = $period_id_previous;

            $db->insertRecord(TABLE_PREFIX.'period',$data,$error_tmp);
            if($error_tmp !== '') $error .= 'Could not create new period record';

        }        
    }  

    public static function getCompany($db,$company_id) {
        $sql = 'SELECT name,description,status,date_start,date_end,'.
                      'vat_apply,vat_rate,vat_account_id,ret_account_id,calc_timestamp '.
               'FROM '.TABLE_PREFIX.'company '.
               'WHERE company_id = "'.$db->escapeSql($company_id).'" ';
        $company = $db->readSqlRecord($sql);
        if($company == 0) throw new Exception('LEDGER_HELPER_ERROR: INVALID Company ID['.$company_id.']');
        
        return $company;
    }  
    
    //Status = NEW transactions are by definition UNprocessed and have no side effects yet.
    public static function deleteNewTransactions($db,$type_id,$company_id,&$message,&$error) {
        $error_tmp = '';
        $error = '';
        $message = '';
                        
        $sql = 'DELETE FROM '.TABLE_PREFIX.'transact '.
               'WHERE company_id = "'.$db->escapeSql($company_id).'" AND status = "NEW" ';
        if($type_id !== 'ALL') $sql .= 'AND type_id = "'.$db->escapeSql($type_id).'"  ';
        $no = $db->executeSql($sql,$error_tmp);
        if($error_tmp !== '') $error .= 'Could not delete NEW transactions.';
                    
        
        if($error === '') {
            $message = 'Successfully deleted '.$no.' status = NEW transactions';
            return true; 
        } else {
            return false;   
        }  
    }

    public static function processNewTransactions($db,$type_id,$company_id,&$message,&$error) {
        $error_tmp = '';
        $error = '';
         
        $company = self::getCompany($db,$company_id);
                
        //transaction options
        $options['vat_apply'] = $company['vat_apply'];
        $options['vat_rate'] = $company['vat_rate'];
        $options['vat_account_id'] = $company['vat_account_id'];
                
        $db->executeSql('START TRANSACTION',$error_tmp);
                
        $sql = 'SELECT transact_id,description,date,status '.
               'FROM '.TABLE_PREFIX.'transact '.
               'WHERE company_id = "'.$db->escapeSql($company_id).'" AND status = "NEW" ';
        if($type_id !== 'ALL') $sql .= 'AND type_id = "'.$db->escapeSql($type_id).'"  ';
        $sql .= 'ORDER BY date';
        $transact = $db->readSqlArray($sql);
        if($transact != 0) {
            $message .= 'found '.count($transact).' of '.$type_id.' type transactions to process!';
            foreach($transact as $transact_id=>$data) {
                self::processTransaction($db,$transact_id,$company_id,$options,$error_tmp);
                if($error_tmp !== '') {
                    $error .= 'Could not process transaction['.$transact_id.'] on['.$data['date'].']'.
                               $data['description'].': '.$error_tmp.'<br/>';
                }  
            }  
                    
        }   else {
            $error .= 'No NEW transactions found to process!';
        }       
        
        if($error === '') {
            $db->executeSql('COMMIT',$error_tmp);
            return true; 
        } else {
            $db->executeSql('ROLLBACK',$error_tmp);
            return false;   
        }  
    }
    
    //validate MANUAL transactions before transaction inserted, NOT full validation! 
    public static function validateTransaction($db,$transact = [],$company_id,$options=[],&$error) {
        $error = '';
        $error_tmp = '';
        
        if(!isset($options['vat_apply'])) $options['vat_apply'] = false;
        if($options['vat_apply']) {
            if(!isset($options['vat_rate'])) $error .= 'Transaction requires VAT rate!<br/>';  
            if(!isset($options['vat_account_id'])) $error .= 'Transaction requires VAT account ID!<br/>';  
        }

        self::checkTransactionPeriod($db,$company_id,$transact['date'],$error_tmp);
        if($error_tmp !== '') $error .= 'Transaction cannot be processed as:'.$error_tmp.'<br/>';

        if($error === '') return true; else return false;
    }

    public static function processTransaction($db,$transact_id,$company_id,$options=[],&$error) {
        $error_tmp = '';
        $error = '';
                
        if(!isset($options['vat_apply'])) $options['vat_apply'] = false;
        if($options['vat_apply']) {
            if(!isset($options['vat_rate'])) $error .= 'Transaction requires VAT rate!<br/>';  
            if(!isset($options['vat_account_id'])) $error .= 'Transaction requires VAT account ID!<br/>';  
        }    
            
        $sql = 'SELECT transact_id,type_id,status,date,debit_credit,account_id_primary,account_id, '.
                      'amount,vat_inclusive,debit_accounts,credit_accounts '.
               'FROM '.TABLE_PREFIX.'transact '.
               'WHERE transact_id = "'.$db->escapeSql($transact_id).'" ';
        $transact = $db->readSqlRecord($sql);
        if($transact == 0) {
            $error .= 'Transaction ID['.$transact_id.'] is INVALID!';
        } else { 
            //by default unless a valid transaction type 
            $valid = false;
            
            self::checkTransactionPeriod($db,$company_id,$transact['date'],$error_tmp);
            if($error_tmp !== '') $error .= 'Transaction cannot be processed as:'.$error_tmp.'<br/>';
        } 
        
        if($error === '') { 
            $update_entry_json = false;
            $debit_accounts = [];
            $credit_accounts = [];
            
            if($transact['type_id'] === 'CUSTOM' or $transact['type_id'] === 'CLOSE') {
                $valid = true;
                
                $debit_accounts = json_decode($transact['debit_accounts'],true);
                $credit_accounts = json_decode($transact['credit_accounts'],true);
                if(is_null($debit_accounts)) $error .= 'Cannot decode debit accounts['.$transact['debit_accounts'].']<br/>';
                if(is_null($credit_accounts)) $error .= 'Cannot decode credit accounts['.$transact['credit_accounts'].']<br/>';
                
                if($error !== '') $valid = false;
            }
                        
            if($transact['type_id'] === 'CASH' or $transact['type_id'] === 'CREDIT') {
                $valid = true;
                $update_entry_json = true;
                                
                //check if vat needs to be extracted
                $vat_adjust = false;
                if($transact['vat_inclusive'] and $options['vat_apply']) {
                    $vat_adjust = true;
                    $amount = floatval($transact['amount']);
                    $base_amount = round($amount/(1+$options['vat_rate']/100),2);
                    $vat_amount = $amount-$base_amount;

                    if($transact['account_id'] == $options['vat_account_id']) {
                        $valid = false;
                        $error = 'Transaction account['.$transact['account_id'].'] cannot be same as VAT account for a VAT inclusive transaction!';
                    }
                } 
                
                //receiving cash          
                if($transact['debit_credit'] === 'C') {
                    $debit_accounts[$transact['account_id_primary']] = $transact['amount'];
                 
                    if($vat_adjust) {
                        $credit_accounts[$transact['account_id']] = $base_amount;
                        $credit_accounts[$options['vat_account_id']] = $vat_amount;
                    } else {
                        $credit_accounts[$transact['account_id']] = $transact['amount'];
                    }    
                }
                
                //paying cash
                if($transact['debit_credit'] === 'D') {
                    $credit_accounts[$transact['account_id_primary']] = $transact['amount'];
                    
                    if($vat_adjust) {
                        $debit_accounts[$transact['account_id']] = $base_amount;
                        $debit_accounts[$options['vat_account_id']] = $vat_amount;
                    } else {
                        $debit_accounts[$transact['account_id']] = $transact['amount'];
                    }  
                }
            }  
            

            //need to check all transacted accounts are active and total debits = total credits
            //NB: also check that transaction type = EQUITY if any equity accounts involved
            if($valid) {
                $sql = 'SELECT account_id,name,type_id FROM '.TABLE_PREFIX.'account '.
                       'WHERE company_id = "'.$db->escapeSql($company_id).'" ';
                $accounts = $db->readSqlArray($sql);
                
                $total_debit = 0;
                $total_credit = 0;
                
                foreach($credit_accounts as $account_id=>$amount) {
                    if(!isset($accounts[$account_id])) {
                        $error .= 'Credit Account['.$accounts[$account_id]['name'].'] is INACTIVE, you cannot transact in this account!<br/>';  
                    } 
                         
                    $total_credit+=$amount;
                }
                $total_credit = round($total_credit,2);
                
                foreach($debit_accounts as $account_id=>$amount) {
                    if(!isset($accounts[$account_id])) {
                        $error .= 'Debit Account['.$accounts[$account_id]['name'].'] is INACTIVE, you cannot transact in this account!<br/>';  
                    } 
                        
                    $total_debit+=$amount;
                }
                $total_debit = round($total_debit,2);
                
                if($total_debit !== $total_credit) $error .= 'Total Debits['.$total_debit.'] NOT equal to Total Credits['.$total_credit.']!<br/>';
                
                if($error !== '') $valid = false;  
            }  
            
            
            //process entries and transaction update
            if($valid) {      
                //remove any entries that may exist for the transaction
                $sql = 'DELETE FROM '.TABLE_PREFIX.'entry WHERE transact_id = "'.$transact_id.'" ';
                $db->executeSql($sql,$error_tmp); 
                if($error_tmp !== '') $error .= 'Could NOT delete entries for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
                
                //process ledger entries
                foreach($credit_accounts as $account_id=>$amount) {
                    self::insertEntry($db,$transact_id,'C',$account_id,$amount,$transact['date'],$error_tmp);
                    if($error_tmp !== '') $error .= 'Could NOT process credit entry for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
                } 
                foreach($debit_accounts as $account_id=>$amount) {
                    self::insertEntry($db,$transact_id,'D',$account_id,$amount,$transact['date'],$error_tmp);
                    if($error_tmp !== '') $error .= 'Could NOT process debit entry for transaction['.$transact_id.'] :'.$error_tmp.'<br/>';
                }  
                
                //update transaction
                $update = [];
                $where = array('transact_id'=>$transact_id);
                
                $update['status'] = 'OK';
                $update['date_process'] = date('Y-m-d H:i:s');
                if($update_entry_json) {
                    $update['debit_accounts'] = json_encode($debit_accounts);
                    $update['credit_accounts'] = json_encode($credit_accounts);
                }  
                $db->updateRecord(TABLE_PREFIX.'transact',$update,$where,$error_tmp);
                if($error_tmp !== '') $error .= 'Could NOT udate transaction['.$transact_id.'] details:'.$error_tmp.'<br/>';
            }  
        }       
    
        
        if($error === '') return true; else return false;  
    }
    
    public static function insertEntry($db,$transact_id,$debit_credit,$account_id,$amount,$date,&$error) {
        $error = '';
        $data = [];
        
        $data['transact_id'] = $transact_id;
        $data['debit_credit'] = $debit_credit;
        $data['account_id'] = $account_id;
        $data['amount'] = $amount;
        $data['date'] = $date;
                        
        $entry_id = $db->insertRecord(TABLE_PREFIX.'entry',$data,$error);
        if($error === '') return true; else return false;  
    }

    public static function deleteUnusedAccounts($db,$company_id,&$accounts = [],&$error) {
        $error_tmp = '';
        $error = '';
        $message = '';

        $company = self::getCompany($db,$company_id);

        $company_id = $db->escapeSql($company_id);

        $sql = 'SELECT A.account_id,A.name,(SELECT COUNT(*) FROM '.TABLE_PREFIX.'entry AS E WHERE E.account_id = A.account_id) AS entry_count '.
               'FROM '.TABLE_PREFIX.'account AS A WHERE A.company_id = "'.$company_id.'" ';
        $account_list = $db->readSqlArray($sql);
        if($account_list != 0) {
            foreach($account_list as $acc_id=>$account) {
                if($account['entry_count'] == 0) {
                    if($acc_id != $company['vat_account_id'] and $acc_id != $company['ret_account_id']) {
                        $sql = 'DELETE FROM '.TABLE_PREFIX.'account WHERE account_id = "'.$acc_id.'" ';
                        $db->executeSql($sql,$error_tmp);
                        if($error_tmp === '') {
                            $accounts[] = $account;
                        } else {
                            $error .= 'Could not delete: '.$account['name'].'['.$acc_id.']<br/>'; 
                        }
                    }    
                } 
            } 
        }
        
        if($error === '') return true; else return false;
    }    

    public static function setupDefaultAccounts($db,$company_id,&$error) {
        $error_tmp = '';
        $error = '';

        //can get these from user saved deafults at some point in the future
        //NB: assumes "LIABILITY_CURRENT" with "VAT" in name is VAT provisional account
        //NB2: assumes only one "EQUITY_EARNINGS" acc and is retained earnings account
        $acc_list = [];
        $acc_list[] = array('ASSET_CURRENT','Cash on hand','1000');
        $acc_list[] = array('ASSET_CURRENT_BANK','Bank account','1100');
        $acc_list[] = array('ASSET_CURRENT_DUE','Accounts receiveable','1200');
        $acc_list[] = array('LIABILITY_CURRENT','VAT provisional','2000');
        $acc_list[] = array('LIABILITY_CURRENT_CARD','Credit card account','2100');
        $acc_list[] = array('LIABILITY_CURRENT_DUE','Accounts payable','2200');
        $acc_list[] = array('EQUITY_OWNER','Owners equity','3000');
        $acc_list[] = array('EQUITY_EARNINGS','Retained earnings','3100');
        $acc_list[] = array('INCOME_SALES','Sales revenue','4000');
        $acc_list[] = array('INCOME_OTHER','Interest income','4100');
        $acc_list[] = array('EXPENSE_SALES','Cost of sales','5000');
        $acc_list[] = array('EXPENSE_FIXED','Office rental','5100');
        $acc_list[] = array('EXPENSE_FIXED','Wages','5110');
        $acc_list[] = array('EXPENSE_FIXED','Office supplies','5120');
        $acc_list[] = array('EXPENSE_FIXED','Communication','5130');
        $acc_list[] = array('EXPENSE_FIXED','Internet service providers','5140');
        $acc_list[] = array('EXPENSE_FIXED','Transport costs','5150');
        $acc_list[] = array('EXPENSE_FIXED','Office administration','5160');
        $acc_list[] = array('EXPENSE_FIXED','Bank fees','5170');
        $acc_list[] = array('EXPENSE_FIXED','Entertainment','5180');
        $acc_list[] = array('EXPENSE_FIXED','Office security','5190');
        $acc_list[] = array('EXPENSE_FIXED','Employee wages PAYE','5200');
                
        
        $company_id = $db->escapeSql($company_id);
        
        $sql = 'SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.$company_id.'" ';
        $count = $db->readSqlValue($sql);
        
        if($count != 0) {
            $error .= 'Cannot setup default accounts as ['.$count.'] accounts already exist! '.
                      'Can only setup default accounts where NO accounts exist for company!';  
        } else {
            $sql = 'INSERT INTO '.TABLE_PREFIX.'account (company_id,type_id,name,abbreviation,status) VALUES ';
            foreach($acc_list as $acc) {
                $sql .= '("'.$company_id.'","'.$acc[0].'","'.$acc[1].'","'.$acc[2].'","OK"),';
            }  
            //remove trailing ","
            $sql = substr($sql,0,-1);
            $db->executeSql($sql,$error_tmp); 
            if($error_tmp !== '') {
                $error = 'Could NOT create default accounts!';   
            } else {  
                //add specialised accounts to company setup
                if($error === '') {
                    $sql = 'SELECT account_id FROM '.TABLE_PREFIX.'account '.
                           'WHERE company_id = "'.$company_id.'" AND type_id = "LIABILITY_CURRENT" AND name LIKE "%VAT%" LIMIT 1 ';
                    $vat_account_id = $db->readSqlValue($sql);
                    
                    $sql = 'SELECT account_id FROM '.TABLE_PREFIX.'account '.
                           'WHERE company_id = "'.$company_id.'" AND type_id = "EQUITY_EARNINGS" LIMIT 1 ';
                    $ret_account_id = $db->readSqlValue($sql);
                     
                    $sql = 'UPDATE '.TABLE_PREFIX.'company SET '.
                           'vat_account_id = "'.$vat_account_id.'", ret_account_id = "'.$ret_account_id.'" '.
                           'WHERE company_id = "'.$company_id.'" ';
                    $db->executeSql($sql,$error_tmp); 
                    if($error_tmp !== '') $error = 'Could NOT setup company VAT account!'; 
                }
            } 
        }  
                
        if($error === '') return true; else return false;  
    }   
    
    public static function checkCompanyAccounts($db,$company_id,&$error) {
        $company_id = $db->escapeSql($company_id);
        
        $sql = 'SELECT name,vat_account_id,ret_account_id '.
               'FROM '.TABLE_PREFIX.'company '.
               'WHERE company_id = "'.$company_id.'" ';
        $company = $db->readSqlRecord($sql);
        if($company['vat_account_id'] == 0) $error .= 'Company vat account NOT setup!<br/>';
        if($company['ret_account_id'] == 0) $error .= 'Company retained income account  NOT setup!<br/>';
        
        if($company['vat_account_id'] != 0) {
            $sql = 'SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
                   'WHERE company_id = "'.$company_id.'" AND account_id = "'.$company['vat_account_id'].'" AND '.
                         'type_id LIKE "LIABILITY_CURRENT%" AND status <> "HIDE" ';
            $count = $db->readSqlValue($sql);
            if($count == 0) $error .= 'Company VAT account id['.$company['vat_account_id'].'] not a valid Current LIABILITY account!';
        }  
        
        if($company['ret_account_id'] != 0) {
            $sql = 'SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
                   'WHERE company_id = "'.$company_id.'" AND account_id = "'.$company['ret_account_id'].'" AND '.
                         'type_id = "EQUITY_EARNINGS" AND status <> "HIDE" ';
            $count = $db->readSqlValue($sql);
            if($count == 0) $error .= 'Company Retained Earnings account id['.$company['ret_account_id'].'] not a valid EQUITY account!';
        }  
                
        $sql = 'SELECT COUNT(*) FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.$company_id.'" AND type_id = "ASSET_CURRENT_BANK" ';
        $count = $db->readSqlValue($sql);
        if($count == 0) $error .= 'Company needs at least one ASSET account with type ASSET_CURRENT_BANK!';
         
        if($error === '') return true; else return false;    
    }
    
    public static function getAccount($db,$account_id,&$error) {
        $error = '';
        
        $sql = 'SELECT * FROM '.TABLE_PREFIX.'account '.
               'WHERE account_id = "'.$db->escapeSql($account_id).'" ';
        $account = $db->readSqlRecord($sql);
        if($account == 0) $error .= 'Invalid account ID['.$account_id.']';
        
        return $account;   
    }  
    
}
?>
