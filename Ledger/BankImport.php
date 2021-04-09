<?php
namespace App\Ledger;

use Seriti\Tools\Date;
use Seriti\Tools\Form;
use Seriti\Tools\Doc;
use Seriti\Tools\Calc;
use Seriti\Tools\Secure;
use Seriti\Tools\Import;

use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_TEMP;
use Seriti\Tools\UPLOAD_DOCS;

use App\Ledger\Helpers;
use App\Ledger\COMPANY_ID;
use App\Ledger\TABLE_PREFIX;

//https://en.wikipedia.org/wiki/Debits_and_credits

class BankImport extends Import {

    protected $acc_keywords = [];
    protected $acc_types = [];
    protected $word_ignore = [];
    protected $assign_keywords = false; //this is set when $mode=='import'

    protected $upload_dir;
    
    //path to modified bank file to match internal representation
    protected $file_path_mod;
    protected $account_id_primary;
    protected $transact_type;
    protected $user_id;
    protected $ignore_errors = false;
    

    //do keyword extraction and update for account id
    //NB: legacy approach, could move to custom function called from ImportBankWizard using Data_array[]
    public function afterRowConfirmed($row) {
                            
        if($this->assign_keywords) {
            $error = '';
            $length_min = 2;
            //Remove all non-word chars
            $text = preg_replace('/[0-9]/','',strtoupper($row['description']));
            $text = explode(' ',$text);
            $text = array_map('trim',$text);
            
            $keywords_new = '';
            $keywords = $this->acc_keywords[$row['account_id']];
            if($keywords == '') {
                foreach($text as $word) {
                    if(strlen($word) > $length_min and !in_array($word,$this->word_ignore)) $keywords_new .= ' '.$word; 
                }  
                $keywords_new = trim($keywords_new);
            } else {
                $keywords = explode(' ',$keywords);
                $keywords = array_map('trim',$keywords);
                foreach($text as $word) {
                    if(strlen($word) > $length_min and !in_array($word,$keywords) and !in_array($word,$this->word_ignore)) {
                        $keywords_new .= ' '.$word; 
                    }  
                }  
            }  
            
            if($keywords_new != '') {
                $keywords = trim($this->acc_keywords[$row['account_id']].' '.$keywords_new);
                $this->acc_keywords[$row['account_id']] = $keywords;
                $sql = 'UPDATE '.TABLE_PREFIX.'account SET keywords = "'.$this->db->escapeSql($keywords).'" '.
                       'WHERE account_id = "'.$this->db->escapeSql($row['account_id']).'" ';
                $this->db->executeSql($sql,$error);      
            }  
        }  
    }  
    
    protected function beforeImportData(&$data,&$valid) {
        $where = ['account_id_primary'=>$this->account_id_primary,
                  'date'=>$data['date'],
                  'amount'=>$data['amount'],
                  'description'=>$data['description']];
        $rec = $this->db->getRecord($this->table,$where);
        if($rec !== 0 ) {
            $valid = false;
            $str = 'Transaction ID['.$rec['transact_id'].'] for amount['.$data['amount'].'] on['.$data['date'].'] with description['.$data['description'].'] already exists.';
            if($this->ignore_errors) {
                $this->addMessage($str.' IGNORED!');
            } else {
                $this->addError($str);
            }
        }    
    }                 
                    
    public function setup($param = []) { 

        $parent_param = ['file_path'=>$param['file_path'],'data_type'=>'CSV','audit'=>true];
        parent::setup($parent_param);

        //$this->file_path = $param['file_path'];
        $this->user_id = $param['user_id'];
        $this->file_path_mod = $param['file_path_mod'];
        $this->transact_type = $param['transact_type'];
        $this->account_id_primary = $param['account_id_primary'];
        $this->assign_keywords = $param['assign_keywords'];
        $this->ignore_errors = $param['ignore_errors'];

        //static values for every record
        $this->addColFixed(array('id'=>'user_id','type'=>'STRING','title'=>'User ID','value'=>$this->user_id));
        $this->addColFixed(array('id'=>'type_id','type'=>'STRING','title'=>'Type ID','value'=>$this->transact_type));
        $this->addColFixed(array('id'=>'date_create','type'=>'DATE','title'=>'Create Date','value'=>date('Y-m-d')));
        $this->addColFixed(array('id'=>'status','type'=>'STRING','title'=>'Status','value'=>'NEW'));
        $this->addColFixed(array('id'=>'company_id','type'=>'INTEGER','title'=>'Company ID','value'=>COMPANY_ID));
        $this->addColFixed(array('id'=>'account_id_primary','type'=>'INTEGER','title'=>'Primary account ID','value'=>$this->account_id_primary));
        
        $this->addImportCol(array('id'=>'transact_id','type'=>'IGNORE','title'=>'Transact ID','key'=>true,'key_auto'=>true));  
        $this->addImportCol(array('id'=>'date','type'=>'DATE','title'=>'Date','confirm'=>false));
        $this->addImportCol(array('id'=>'description','type'=>'TEXT','title'=>'Description','confirm'=>false));
        $this->addImportCol(array('id'=>'debit_credit','type'=>'STRING','title'=>'Debit Credit','size'=>3,'confirm'=>false));
        $this->addImportCol(array('id'=>'amount','type'=>'DECIMAL','title'=>'Amount','confirm'=>false));
        $this->addImportCol(array('id'=>'vat_inclusive','type'=>'BOOLEAN','title'=>'VAT Inclusive','confirm'=>true,'class'=>'none'));
        $this->addImportCol(array('id'=>'account_id','type'=>'INTEGER','title'=>'Account','confirm'=>true,));
             
        $sql = 'SELECT account_id,CONCAT(type_id,":",name) AS name '.
               'FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.COMPANY_ID.'" AND '.
                     '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%" OR type_id LIKE "LIABILITY%") '.
               'ORDER BY type_id, name';
        $this->addSelect('account_id',$sql);
      

        //keywords in transaction description to ignore
        $this->word_ignore = ['THE','CREDIT','DEBIT','CARD','BANK','(PTY)','LTD','PAYMENT','PURCHASE'];
  
        //keywords for ALL accounts used to guess account and update existing keywords if requested
        $sql = 'SELECT account_id,keywords FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.COMPANY_ID.'" AND '.
                    '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%" OR type_id LIKE "LIABILITY%") '.
               'ORDER BY type_id, name ';
        $this->acc_keywords = $this->db->readSqlList($sql); 

        $sql = 'SELECT account_id,type_id FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.COMPANY_ID.'" AND '.
                     '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%" OR type_id LIKE "LIABILITY%") '.
               'ORDER BY type_id,name ';
        $this->acc_types = $this->db->readSqlList($sql); 

        $sql = 'SELECT account_id,abbreviation FROM '.TABLE_PREFIX.'account '.
               'WHERE company_id = "'.COMPANY_ID.'" AND '.
                     '(type_id LIKE "INCOME%" OR type_id LIKE "EXPENSE%" OR type_id LIKE "LIABILITY%") '.
               'ORDER BY type_id,name ';
        $this->acc_codes = $this->db->readSqlList($sql); 
    } 

    public function useModifiedFile() 
    {
        $this->file_path = $this->file_path_mod;
    }

    public function modifyBankFile($import_type,&$message = '',&$error = '') 
    {
        //internal transaction representation
        $header = ['Transact ID','Date','Description','Debit Credit','Amount','VAT Inclusive','Account'];
    
        //echo 'WTF file path: '.$this->file_path.'<br/>';
        //echo 'WTF file path mod: '.$this->file_path_mod.'<br/>';
        //exit;

        $handle_read = fopen($this->file_path,'r');
        $handle_write = fopen($this->file_path_mod,'w');
        
        //write first line of modified csv file
        fputcsv($handle_write,$header); 
        
        $company = Helpers::getCompany($this->db,COMPANY_ID);
        if($company['vat_apply']) {
            $vat_inclusive = true;
            $vat_acc_id = $company['vat_account_id'];
        } else {
            $vat_inclusive = false;
            $vat_acc_id = 0;
        }    

        //get default type accounts where no valid guess found
        $def_acc_id_expense = 0;
        $def_acc_id_income = 0;
        $def_acc_id_liability = 0;
        foreach($this->acc_types as $acc_id => $type) { 
            if($def_acc_id_expense === 0 and $type === 'EXPENSE_SALES') $def_acc_id_expense = $acc_id;
            if($def_acc_id_income === 0 and $type === 'INCOME_SALES') $def_acc_id_income = $acc_id;
            if($def_acc_id_liability === 0 and $type[0] === 'L' and $acc_id != $vat_acc_id) $def_acc_id_liability = $acc_id;
        } 
        //echo "EXPENSE $def_acc_id_expense INCOME $def_acc_id_income LIABILITY $def_acc_id_liability <br/>";
        //exit;

        $message .= $company['name'].': CSV transaction import.<br/>';
                        
        if($import_type === 'BANK_SBSA' or $import_type === 'BANK_SBSA_CC') {
            $i=0;
            $v=0;
            while(($line = fgetcsv($handle_read,0,",")) !== FALSE) {
                $i++;
                $valid = false;
                
                //need at least three csv values in line to be valid
                if(count($line) > 3) {
                    if($line[0] === 'HIST') {
                        $valid = true;  
                    } else {
                        if($line[2] === 'ACC-NO') $message .= 'SBSA Account No: '.$line[1].'<br/>';
                        if($line[2] === 'OPEN') $message .= 'Opening balance: '.$line[3].'<br/>';
                        if($line[2] === 'CLOSE') $message .= 'Closing balance: '.$line[3].'<br/>';
                    }    
                }  
                        
                if($valid) {
                    $amount = floatval($line[3]);          
                    if($amount < 0.00) {
                        $debit_credit = 'D';
                    } else {
                        $debit_credit = 'C';
                    } 
                    
                    $date_str = substr($line[1],0,4).'-'.substr($line[1],4,2).'-'.substr($line[1],6,2);
                    $description = trim(strtoupper($line[4]).' '.strtoupper($line[5]));
                    
                    //NB: ignore credit card account settling transaction "APO payment"
                    //this transaction is processed on current account import and to do again for credit card statement would duplicate
                    if($this->transact_type === 'CREDIT' and $debit_credit === 'C' and stripos($description,$ignore_desc) !== false) {
                        $valid = false;
                    }   
                }
                
                if($valid) { 
                    $v++; 
                    $use_acc_id = false;
                    $amount = abs($amount); //NB: amount is ALLWAYS postive in ledger transactions
                    
                    if($debit_credit === 'C') $use_acc_id = $def_acc_id_income; else $use_acc_id = $def_acc_id_expense;
                    
                    // Remove all non-word chars
                    $text = preg_replace('/[0-9]/','',$description);
                    $text = explode(' ',$text);
                    $text = array_map('trim',$text);
                    
                    //make guess at account id
                    $max_score = 0;
                    foreach($this->acc_keywords as $acc_id => $keywords) {
                        //initialise $use_acc_id in case of zero scores
                        if($use_acc_id === false) $use_acc_id = $acc_id;
                        $score = 0;
                        foreach($text as $word) {
                            if(stripos($keywords,$word) !== false) $score++;
                        } 
                        if($score > $max_score) {
                            $use_acc_id = $acc_id;
                            $max_score = $score; 
                        }  
                    }
                    
                    //check that Debit is not assigned to a INCOME account as this will rarely be valid in comparison to incorrect guesses
                    if($debit_credit === 'D' and $this->acc_types[$use_acc_id][0] === 'I') {
                        $use_acc_id = $def_acc_id_expense;
                    }

                    //cannot have a vat inclusive transaction with vat account as counterparty
                    if($vat_inclusive and $use_acc_id == $vat_acc_id) {
                        $use_acc_id = $def_acc_id_liability;
                    }  
                                                   
                    $line_mod = [];
                    $line_mod[] = '0';
                    $line_mod[] = $date_str;
                    $line_mod[] = $description;
                    $line_mod[] = $debit_credit;
                    $line_mod[] = number_format($amount,2);
                    $line_mod[] = $vat_inclusive;
                    $line_mod[] = $use_acc_id;
                    
                    fputcsv($handle_write,$line_mod);
    
                }  
            }
        }   
        

        if($import_type === 'GENERIC_EXPENSE' or $import_type === 'GENERIC_INCOME') {
            $i=0;
            $v=0;
            while(($line = fgetcsv($handle_read,0,",")) !== FALSE) {
                $error_tmp = '';
                $error_line = '';

                $i++;
                $valid = false;
                
                /*
                [0] - date YYYY-MM-DD
                [1] - amount
                [2] - description
                [3] - account code
                */

                //need at least three csv values in line to be valid
                if(count($line) > 2) {
                    $date_str = Date::convertAnyDate($line[0],'YMD','YYYY-MM-DD',$error_tmp);
                    if($error_tmp !== '') $error_line .= 'Invalid date['.$error_tmp.'] ';
                    
                    $amount = Calc::floatVal($line[1]);
                    if($amount === 0) $error_line .= 'Zero amount ';

                    $description = Secure::clean('string',$line[2]);
                    
                    if(isset($line[3])) {
                        $account_code = Secure::clean('string',$line[3]);    
                    } else {
                        $account_code = '';
                    }
                    
                    if($error_line !== '') {
                        $error .=  $error_line.' in line['.$i.']. ';
                    } else {
                        $valid = true; 
                    }
                }  
                                
                if($valid) { 
                    $v++; 
                    $use_acc_id = false;

                    //NB: $debit_credit refers to secondary/selected account $use_acc_id
                    if($import_type === 'GENERIC_EXPENSE') {
                        if($amount > 0.00) $debit_credit = 'D'; else $debit_credit = 'C'; //Debit = increase
                        $def_acc_id = $def_acc_id_expense;
                    }  
                    if($import_type === 'GENERIC_INCOME') {
                        if($amount > 0.00) $debit_credit = 'C'; else $debit_credit = 'D'; //Credit = increase
                        $def_acc_id = $def_acc_id_income;
                    }

                    $amount = abs($amount); //NB: amount is ALLWAYS postive in ledger transactions

                    if($account_code !== '') {
                        //returns false if not found
                        $use_acc_id = array_search($account_code,$this->acc_codes);
                    }
                    
                    //only search on keywords if no recogniseable code given
                    if($use_acc_id === false) {
                        //Remove all non-word chars for keyword search
                        $text = preg_replace('/[0-9]/','',$description);
                        $text = explode(' ',$text);
                        $text = array_map('trim',$text);
                        
                        //make guess at account id
                        $max_score = 0;
                        foreach($this->acc_keywords as $acc_id => $keywords) {
                            //initialise $use_acc_id in case of zero scores
                            if($use_acc_id === false) $use_acc_id = $acc_id;
                            $score = 0;
                            foreach($text as $word) {
                                if(stripos($keywords,$word) !== false) $score++;
                            } 
                            if($score > $max_score) {
                                $use_acc_id = $acc_id;
                                $max_score = $score; 
                            }  
                        }    
                    }
                                        
                    //INCOME secondary account cannot be an Expense account
                    if($import_type === 'GENERIC_INCOME' and $this->acc_types[$use_acc_id][0] === 'E') {
                        $use_acc_id = $def_acc_id;
                    }
                    //EXPENSE secondary account cannot be an Income or Liability account
                    if($import_type === 'GENERIC_EXPENSE' and $this->acc_types[$use_acc_id][0] !== 'E') {
                        $use_acc_id = $def_acc_id;
                    }

                    //cannot have a vat inclusive transaction with vat account as counterparty
                    if($vat_inclusive and $use_acc_id == $vat_acc_id) {
                        $use_acc_id = $def_acc_id;
                    }  
                             
                               
                    $line_mod = [];
                    $line_mod[] = '0';
                    $line_mod[] = $date_str;
                    $line_mod[] = $description;
                    $line_mod[] = $debit_credit;
                    $line_mod[] = number_format($amount,2);
                    $line_mod[] = $vat_inclusive;
                    $line_mod[] = $use_acc_id;
                    
                    fputcsv($handle_write,$line_mod);
    
                }  
            }
        }
        //close bank file and converted/modified file
        fclose($handle_read);
        fclose($handle_write);

        //check if any valid lines found
        if($v === 0) $error = 'No valid data found in transaction import file. Check file is for transaction format you selected?';
    }
    
}