<?php 
namespace App\Ledger;

use App\Ledger\Helpers;
use Seriti\Tools\Form;
use Seriti\Tools\Task as SeritiTask;

class Task extends SeritiTask
{
    public function setup()
    {
        $param = array();
        $param['separator'] = true;

        $this->addBlock('COMPANY',1,1,'Manage Active Company');
        $this->addTask('COMPANY','EDIT_COMPANY','Edit '.COMPANY_NAME.' details');
        $this->addTask('COMPANY','IMPORT_BANK','Import CSV bank statement');
        $this->addTask('COMPANY','TRANSACT_NEW','process NEW transactions to ledger');
        $this->addTask('COMPANY','TRANSACT_NEW_DELETE','Delete NEW unprocessed transactions');
        $this->addTask('COMPANY','SETUP_PERIODS','Manage company periods');
        $this->addTask('COMPANY','CLOSE_PERIOD','CLOSE company period');
        $this->addTask('COMPANY','OPEN_PERIOD','OPEN company period');
        $this->addTask('COMPANY','ADD_PERIOD','ADD company period');
        $this->addTask('COMPANY','CALC_BALANCES','Re-calculate company balances for a period',$param);

        $this->addBlock('MANAGE',2,1,'All Companies');
        $this->addTask('MANAGE','CHANGE_COMPANY','Change active company');
        $this->addTask('MANAGE','ALL_COMPANIES','Manage ALL companies');
        $this->addTask('MANAGE','ADD_COMPANY','Add a new company');
        if(CHART_SETUP) {
            $this->addTask('MANAGE','ACCOUNT_CHART','Setup chart of accounts for reports');
        }    

        $this->addTask('MANAGE','SETUP_ACCOUNTS','Setup default accounts for a NEW company');
        $this->addTask('MANAGE','DELETE_ACCOUNTS','Delete unused accounts for a company');
    }

    function processTask($id,$param = []) {
        $error = '';
        $message = '';
        $n = 0;
        
        if($id === 'TRANSACT_NEW') {
            Helpers::processNewTransactions($this->db,'ALL',COMPANY_ID,$message,$error);
            if($error !== '') $this->addError($error);
            if($message !== '') $this->addMessage($message);
        }

        if($id === 'TRANSACT_NEW_DELETE') {
            Helpers::deleteNewTransactions($this->db,'ALL',COMPANY_ID,$message,$error);
            if($error !== '') $this->addError($error);
            if($message !== '') $this->addMessage($message);
        }
        
        if($id === 'IMPORT_BANK') {
            $location = 'bank_import';
            header('location: '.$location);
            exit;
        }
        
        if($id === 'EDIT_COMPANY') {
            $location = 'company?mode=edit&id='.COMPANY_ID;
            header('location: '.$location);
            exit;
        }
        
        if($id === 'ADD_COMPANY') {
            $location = 'company?mode=add';
            header('location: '.$location);
            exit;
        }
        
        if($id === 'SETUP_PERIODS') {
            $location = 'period';
            header('location: '.$location);
            exit;
        }
        
        if($id === 'ALL_COMPANIES') {
            $location = 'company';
            header('location: '.$location);
            exit;
        }

        if($id === 'ACCOUNT_CHART') {
            $location = 'chart';
            header('location: '.$location);
            exit;
        }

        //setup default accounts for a NEW company
        if($id === 'SETUP_ACCOUNTS') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['company_id'])) $param['company_id'] = '';
        
            if($param['process'] === 'setup') {
                Helpers::setupDefaultAccounts($this->db,$param['company_id'],$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY setup company accounts!');
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `company_id`,`name` FROM `'.TABLE_PREFIX.'company` ORDER BY `name`';
                $list_param = [];
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-small';
                $html .= 'Please select Company ID that you wish to setup default accounts for.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="setup"><br/>'.
                         'Select Company: '.Form::sqlList($sql,$this->db,'company_id',$param['company_id'],$list_param).
                         '<input type="submit" name="submit" value="SETUP ACCOUNTS" class="'.$this->classes['button'].'">'.
                         '</form>';

                //display form in message box       
                $this->addMessage($html);      
            }  
        }

        if($id === 'DELETE_ACCOUNTS') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['company_id'])) $param['company_id'] = '';
        
            if($param['process'] === 'setup') {
                $accounts = [];
                Helpers::deleteUnusedAccounts($this->db,$param['company_id'],$accounts,$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY deleted '.count($accounts).' company accounts without transactions!');
                    foreach($accounts as $account) {
                        $this->addMessage('Account: '.$account['name'].' DELETED');   
                    }
                    
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `company_id`,`name` FROM `'.TABLE_PREFIX.'company` ORDER BY `name`';
                $list_param = [];
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-small';
                $html .= 'Please select Company ID that you wish to setup default accounts for.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="setup"><br/>'.
                         'Select Company: '.Form::sqlList($sql,$this->db,'company_id',$param['company_id'],$list_param).
                         '<input type="submit" name="submit" value="DELETE ACCOUNTS" class="'.$this->classes['button'].'">'.
                         '</form>';

                //display form in message box       
                $this->addMessage($html);      
            }  
        }
        
        if($id === 'CHANGE_COMPANY') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['company_id'])) $param['company_id'] = '';
        
            if($param['process'] === 'change') {
                $cache = $this->getContainer('cache');  
                $company_id = $param['company_id']; 
                $cache->store('company_id',$company_id);      
        
                $location = 'dashboard';
                header('location: '.$location);
                exit;             
            } else {
                $sql = 'SELECT `company_id`,`name` FROM `'.TABLE_PREFIX.'company` ORDER BY `name`';
                $list_param = array();
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-small';
                $html .= 'Please select Company that you wish to work on.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="change"><br/>'.
                         'Select Company: '.Form::sqlList($sql,$this->db,'company_id',$param['company_id'],$list_param).
                         '<input type="submit" name="submit" value="CHANGE ACTIVE" class="'.$this->classes['button'].'">'.
                         '</form>'; 
                //display form in message box       
                $this->addMessage($html);      
            }  
        } 
        
        //CLOSE an accounting period for active company
        if($id === 'CLOSE_PERIOD') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['period_id'])) $param['period_id'] = '';
        
            if($param['process'] === 'close') {
                Helpers::closePeriod($this->db,COMPANY_ID,$param['period_id'],$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY closed period!');
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `period_id`,CONCAT(`name`," (from: ",`date_start`," To: ",`date_end`,")") FROM `'.TABLE_PREFIX.'period` '.
                       'WHERE `company_id` = "'.COMPANY_ID.'" AND `status` = "OPEN" '.
                       'ORDER BY `date_start`';
                $list_param = array();
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-medium';
                $html .= 'Please select Company Period that you wish to CLOSE.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="close"><br/>'.
                         'Select Period: '.Form::sqlList($sql,$this->db,'period_id',$param['period_id'],$list_param).
                         '<input type="submit" name="submit" value="CLOSE PERIOD" class="'.$this->classes['button'].'">'.
                         '</form>'; 
                //display form in message box       
                $this->addMessage($html);      
            }  
        }
        
        //OPEN an accounting period for active company
        if($id === 'OPEN_PERIOD') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['period_id'])) $param['period_id'] = '';
        
            if($param['process'] === 'open') {
                Helpers::openPeriod($this->db,COMPANY_ID,$param['period_id'],$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY opened period!');
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `period_id`,CONCAT(`name`," (from: ",`date_start`," To: ",`date_end`,")") FROM `'.TABLE_PREFIX.'period` '.
                       'WHERE `company_id` = "'.COMPANY_ID.'" AND `status` = "CLOSED" '.
                       'ORDER BY `date_start`';
                $list_param = array();
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-medium';
                $html .= 'Please select Company Period that you wish to OPEN.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="open"><br/>'.
                         'Select Period: '.Form::sqlList($sql,$this->db,'period_id',$param['period_id'],$list_param).
                         '<input type="submit" name="submit" value="OPEN PERIOD" class="'.$this->classes['button'].'">'.
                         '</form>'; 
                //display form in message box       
                $this->addMessage($html);      
            }  
        }

        //INSERT an accounting period for active company
        if($id === 'ADD_PERIOD') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['year'])) $param['year'] = date['Y'];

            $past_years = 10;
            $future_years = 1;
        
            if($param['process'] === 'add') {
                Helpers::addPeriod($this->db,COMPANY_ID,$param['year'],$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY created period['.$param['year'].']!');
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `period_id`,CONCAT(`name`," (from: ",`date_start`," To: ",`date_end`,")") FROM `'.TABLE_PREFIX.'period` '.
                       'WHERE `company_id` = "'.COMPANY_ID.'" AND `status` = "CLOSED" '.
                       'ORDER BY `date_start`';
                $list_param = array();
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-medium';
                $html .= 'Please select Company Period that you wish to ADD.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="add"><br/>'.
                         'Select Period: '.Form::yearsList($param['year'],$past_years,$future_years,'year',$list_param).
                         '<input type="submit" name="submit" value="CREATE PERIOD" class="'.$this->classes['button'].'">'.
                         '</form>'; 
                //display form in message box       
                $this->addMessage($html);      
            }  
        }
        
        if($id === 'CALC_BALANCES') {
            if(!isset($param['process'])) $param['process'] = false;  
            if(!isset($param['period_id'])) $param['period_id'] = '';
        
            if($param['process'] === 'calculate') {
                $options = array();
                Helpers::calculateBalances($this->db,COMPANY_ID,$param['period_id'],$options,$error);
                if($error === '') {
                    $this->addMessage('SUCCESSFULY calculated period balances!');
                } else {
                    $this->addError($error);   
                }     
            } else {
                $sql = 'SELECT `period_id`,CONCAT(`name`," (from: ",`date_start`," To: ",`date_end`,")") FROM `'.TABLE_PREFIX.'period` '.
                       'WHERE `company_id` = "'.COMPANY_ID.'" AND `status` = "OPEN" '.
                       'ORDER BY `date_start`';
                $list_param = array();
                $list_param['class'] = 'form-control input-large';
            
                $html = '';
                $class = 'form-control input-medium';
                $html .= 'Please select OPEN Company Period that you wish to Calculate balances for.<br/>'.
                         '<form method="post" action="?mode=task&id='.$id.'" enctype="multipart/form-data">'.
                         '<input type="hidden" name="process" value="calculate"><br/>'.
                         'Select Period: '.Form::sqlList($sql,$this->db,'period_id',$param['period_id'],$list_param).
                         '<input type="submit" name="submit" value="CALCULATE BALANCES" class="'.$this->classes['button'].'">'.
                         '</form>'; 
                //display form in message box       
                $this->addMessage($html);      
            }  
        }
            
    }
}
