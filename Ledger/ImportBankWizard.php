<?php
namespace App\Ledger;

use Seriti\Tools\Wizard;
use Seriti\Tools\Date;
use Seriti\Tools\Form;
use Seriti\Tools\Doc;
use Seriti\Tools\Calc;
use Seriti\Tools\Import;

use Seriti\Tools\STORAGE;
use Seriti\Tools\BASE_UPLOAD;
use Seriti\Tools\UPLOAD_TEMP;
use Seriti\Tools\UPLOAD_DOCS;

use App\Ledger\BankImport;
use App\Ledger\Helpers;
use App\Ledger\COMPANY_ID;
use App\Ledger\TABLE_PREFIX;

class ImportBankWizard extends Wizard 
{
    //csv import object
    protected $import; 


    protected $upload_dir;
    //path to bank import file    
    protected $file_path; 
    //path to modified bank file to match internal representation
    protected $file_path_mod;
    protected $transact_type;

    //assign import class
    public function addImport(Import $import) {
        $this->import = $import;
    } 

    //configure
    public function setup($param = []) 
    {
        //var_dump($this->container);
        //exit; 

        $param = ['bread_crumbs'=>true,'strict_var'=>false];
        parent::setup($param);

        //define all wizard variables to be captured and stored for all wizard pages
        $this->addVariable(array('id'=>'import_type','type'=>'STRING','title'=>'Transaction Import type'));
        $this->addVariable(array('id'=>'account_id_primary','type'=>'INTEGER','title'=>'Primary import account'));
        $this->addVariable(array('id'=>'data_file','type'=>'FILE','title'=>'Import file'));
        $this->addVariable(array('id'=>'ignore_errors','type'=>'BOOLEAN','title'=>'Ignore errors in import file','new'=>false));
        $this->addVariable(array('id'=>'assign_keywords','type'=>'BOOLEAN','title'=>'Assign key words to account','new'=>false));
        
        //define pages and templates
        $this->addPage(1,'Select Bank import file and account','ledger/bank_wizard_start.php');
        $this->addPage(2,'Review import data','ledger/bank_wizard_review.php');
        $this->addPage(3,'Confirmation page','ledger/bank_wizard_final.php',['final'=>true]);

    }

    

    public function processPage() 
    {
        $error = '';
        $message = '';
        $error_tmp = '';

        //upload bank file and display allocations for review
        if($this->page_no == 1) {
            $import_type = $this->form['import_type'];
            $account_id_primary = $this->form['account_id_primary'];
            $ignore_errors = $this->form['ignore_errors'];

            //debug
            //echo '<br/>*************<br/>';
            //var_dump($this->form);
            //var_dump($this->container);
           // exit; 
            if($import_type === 'GENERIC_EXPENSE' or $import_type === 'GENERIC_INCOME') {
                $transact_type = 'CASH';
                $ignore_desc = '';
            }    
            
            if($import_type === 'BANK_SBSA') {
                $transact_type = 'CASH';
                $ignore_desc = '';
            }
              
            if($import_type === 'BANK_SBSA_CC') {
                $transact_type = 'CREDIT';
                //ignore credit card account payments as normally already included in current account import
                $ignore_desc = 'APO PAYMENT';
            }  

            $sql = 'SELECT `account_id`,`type_id`,`name`,`description` FROM `'.TABLE_PREFIX.'account` '.
                   'WHERE `account_id` = "'.$account_id_primary.'" ';
            $primary_account = $this->db->readSqlRecord($sql); 
            if($primary_account == 0) {
                $error .= 'Invalid primary account ID['.$account_id_primary.']<br/>';
            } else {  
                $this->data['primary_account'] = $primary_account;

                if($transact_type === 'CREDIT' and $primary_account['type_id'] !== 'LIABILITY_CURRENT_CARD') {
                    $error .= 'Credit card account['.$primary_account['name'].'] is not a LIABILITY account<br/>';
                }  
                if($transact_type === 'CASH' and $primary_account['type_id'] !== 'ASSET_CURRENT_BANK') {
                    $error .= 'Current account['.$primary_account['name'].'] is not an ASSET account<br/>';
                } 
            }


            if($error !== '') {
                $this->addError($error);
            } else {
                //configure CSV import object
                $param = [];
                $param['assign_keywords'] = $this->form['assign_keywords'];
                $param['user_id'] = $this->getContainer('user')->getId();
                $param['transact_type'] = $transact_type;
                $param['account_id_primary'] = $this->form['account_id_primary'];
                $param['ignore_errors'] = $this->form['ignore_errors'];
                
                //NB:Should only be one file
                $file = $this->form['data_file'][0];
                $param['file_path'] = $file['save_path'];
                $param['file_path_mod'] = BASE_UPLOAD.UPLOAD_TEMP.$file['save_name'];
                $this->import->Setup($param);

                //modify bank format to generic import format
                $this->import->modifyBankFile($import_type,$message,$error);
                if($message !== '') $this->addMessage($message);
                if($error !== '') $this->addError($error);

                //generate confirmation view after switching files and save to wizard data 
                if(!$this->errors_found) {
                    $this->import->useModifiedFile();
                    $param = [];
                    $this->data['transact_type'] = $transact_type;
                    $this->data['confirm_form'] = $this->import->viewConfirm('CSV',$param,$error);
                    if($error !== '') $this->addError($error);
                }    
            }    
            
        } 
                
        if($this->page_no == 2) {
            
            //configure CSV import object
            $param = [];
            $param['assign_keywords'] = $this->form['assign_keywords'];
            $param['user_id'] = $this->getContainer('user')->getId();
            $param['transact_type'] = $this->data['transact_type'];
            $param['account_id_primary'] = $this->form['account_id_primary'];
            $param['ignore_errors'] = $this->form['ignore_errors'];

            //NB:Should only be one file
            $file = $this->form['data_file'][0];
            //NB: file_path now set to modified file in case need to reference
            $param['file_path'] = BASE_UPLOAD.UPLOAD_TEMP.$file['save_name'];
            $param['file_path_mod'] = '';
            $this->import->Setup($param);

            $import_data = [];
            $data_type = 'CONFIRM_FORM'; //could use 'CSV' and modified file as above if no confirmation necesary
            $this->import->createDataArray($data_type,$import_data);

            $errors = $this->import->getErrors();
            if(count($errors) == 0) {
                $this->db->executeSql('START TRANSACTION',$error_tmp);
                if($error_tmp !== '') $this->addError('Could not START import transaction');

                $this->import->importDataArray($import_data);
                $errors = $this->import->getErrors();

                if(count($errors)) {
                    $this->db->executeSql('ROLLBACK',$error_tmp);
                    if($error_tmp !== '') $this->addError('Could not ROLLBACK transaction');
                } else {
                    $this->db->executeSql('COMMIT',$error_tmp);
                    if($error_tmp !== '') $this->addError('Could not COMMIT transaction');
                }
            }

            $messages = $this->import->getMessages();
            $this->messages = array_merge($this->messages,$messages);

            if(count($errors)) {
                $this->errors_found = true; 
                $this->errors = array_merge($this->errors,$errors);
            } else {
                Helpers::processNewTransactions($this->db,$this->data['transact_type'],COMPANY_ID,$message,$error);
                if($message !== '') $this->addMessage($message);
                if($error !== '') $this->addError($error);

                //for display on final page
                $this->data['import_data'] = $import_data;
            }
        }  
        
        //no processing required for final page
        if($this->page_no == 3) {
          
        } 
    }

}
