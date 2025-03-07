<?php 
namespace App\Ledger;

use Seriti\Tools\Date;
use Seriti\Tools\Form;
use Seriti\Tools\Validate;
use Seriti\Tools\Secure;

use Seriti\Tools\DbInterface;
use Seriti\Tools\IconsClassesLinks;
use Seriti\Tools\MessageHelpers;
use Seriti\Tools\ContainerHelpers;

use App\Ledger\Helpers;
use App\Ledger\TABLE_PREFIX;
use App\Ledger\COMPANY_ID;
use App\Ledger\MODULE_LOGO;

use Psr\Container\ContainerInterface;

class TransactCash 
{
    use IconsClassesLinks;
    use MessageHelpers;
    use ContainerHelpers;

    protected $container;
    protected $container_allow = ['user'];

    protected $db;
    protected $debug = false;

    protected $mode = 'start';
    protected $errors = array();
    protected $errors_found = false; 
    protected $messages = array();

    protected $user_id;

    public function __construct(DbInterface $db, ContainerInterface $container) 
    {
        $this->db = $db;
        $this->container = $container;
               
        if(defined('\Seriti\Tools\DEBUG')) $this->debug = \Seriti\Tools\DEBUG;
    }

    public function process()
    {
        $error_str = '';
        $amount_min = 0.01;
        $amount_max = 10000000;

        $mode = 'new';
        if(isset($_GET['mode'])) $mode = Secure::clean('alpha',$_GET['mode']);

        $type_id = 'CASH';
        $company_id = COMPANY_ID;
        $company = Helpers::getCompany($this->db,$company_id);

        if($mode === 'new') {
            $date = date('Y-m-d');
            $time = '00:00';
            $description = '';
            $account_id_primary = '';
            $account_id = '';
            $amount = '';
            $direction = 'OUT';
        }

        if($mode === 'update') {
                        
            $amount = $_POST['amount'];
            Validate::number('Transaction amount',$amount_min,$amount_max,$amount,$error_str);
            if($error_str !== '') $this->addError($error_str);
            
            $direction = Secure::clean('basic',$_POST['direction']);
            if($direction !== 'IN' and $direction !== 'OUT') {
                $this->addError('Transaction must be IN or OUT of CASH account');        
            }       
            
            if(isset($_POST['vat_inclusive'])) $vat_inclusive = true; else $vat_inclusive = false;
            
            $account_id_primary = Secure::clean('integer',$_POST['account_id_primary']);
            $account_primary = Helpers::getAccount($this->db,$account_id_primary,$error_str);
            if($error_str !== '') $this->addError('CASH account: '.$error_str);
            
            $account_id = Secure::clean('integer',$_POST['account_id']);
            $account = Helpers::getAccount($this->db,$account_id,$error_str);
            if($error_str!='') $this->addError('Transact account: '.$error_str);
                    
            $date = $_POST['date'];
            Validate::date('Transaction date',$date,'YYYY-MM-DD',$error_str);
            if($error_str !== '') $this->addError($error_str);
            
            $time = '00:00';
            //$time=$_POST['time'];
            //Validate::time('Transaction time',$time,'HH:MM',$error_str);
            //if($error_str!='') $this->addError($error_str);
             
            $description = $_POST['description'];
            Validate::text('Description',0,64000,$description,$error_str);
            if($error_str !== '') $this->addError($error_str);
            
            //secondary validation and transaction settings
            if(!$this->errors_found) {
                if($account_primary['type_id'] !== 'ASSET_CURRENT_BANK') {
                    $this->addError('Your primary transaction account must have type = ASSET_CURRENT_BANK. '); 
                }
                
                //debit/credit secondary account
                //NB: Direction referes to primary CASH account, debit_credit to secondary account
                if($direction === 'OUT') {
                    $inc_dec = 'DECREASING'; //cash balance in account
                    $debit_credit = 'D'; 
                } else {
                    $inc_dec = 'INCREASING'; //cash balance in account
                    $debit_credit = 'C';
                }  
                
                $process_options = [];
                if($company['vat_apply']) {
                    $process_options['vat_apply'] = true;
                    $process_options['vat_rate'] = $company['vat_rate'];
                    $process_options['vat_account_id'] = $company['vat_account_id'];
                }    
            }
            
            //finally process transaction
            if(!$this->errors_found) {
                $transact = [];
                $transact['status'] = 'NEW';
                $transact['company_id'] = $company_id;
                $transact['type_id'] = $type_id;
                $transact['date_create'] = date('Y-m-d');
                $transact['date'] = $date.' '.$time;
                
                $transact['account_id_primary'] = $account_id_primary;
                $transact['account_id'] = $account_id;
                $transact['debit_credit'] = $debit_credit;
                $transact['amount'] = abs($amount);
                $transact['vat_inclusive'] = $vat_inclusive;
                
                $transact['description'] = $description;
                
                //basic check of transaction parameters
                Helpers::validateTransaction($this->db,$transact,$company_id,$process_options,$error_tmp);
                if($error_tmp !== '') {
                   $this->addError($error_tmp);
                } else { 
                    $transact_id = $this->db->insertRecord(TABLE_PREFIX.'transact',$transact,$error_tmp);
                    if($error_tmp !== '') {
                        if(stripos($error_tmp,'Duplicate entry') !== false) {
                            $error_tmp = 'Transaction account, date, amount, description repeated! Add a unique description to process if valid.';
                        }  
                        $this->addError('Could not add transaction:'.$error_tmp); 
                    } else {  
                        //process all transaction entries
                        Helpers::processTransaction($this->db,$transact_id,$company_id,$process_options,$error_tmp);
                        if($error_tmp !== '') {
                            $this->addError('Could NOT process transaction['.$transact_id.']: '.$error_tmp);

                        } 
                    }
                }    
            }
            
            //reset form parameters if necessary 
            if(!$this->errors_found) {
                $desc = $inc_dec.' <strong>'.$account_primary['name'].'</strong> ASSET by amount['.$amount.'] ';
                $this->addMessage('Successfully processed transaction: '.$desc); 
                $this->addMessage('Capture another transaction, or <a href="javascript:window.close()">Close</a>.'); 
                
                $description = '';
                //$account_id_primary = '';
                //$account_id = '';
                $amount = '';
                //$direction = 'OUT';
            }  
                
        }

        $html = '<a href="Javascript:onClick=window.close()">[close]</a>'; 
        
        $html .= '<div class="row"><div class="col-sm-12">'.$this->viewMessages().'</div></div>';
                    
        $html .= '<form method="post" action="?mode=update" name="transact_cash">';

        $html .= '<div class="row">';
        //NB: direction refers to IN/OUT of CASH account
        $group_name = 'direction';
        $param = [];
        $html .= '<div class="col-sm-4"><label>'.
                  Form::radiobutton($group_name,'IN',$direction,$param).'INCREASING '.
                  Form::radiobutton($group_name,'OUT',$direction,$param).'DECREASING '.
                  'Account</label></div>';

        $sql = 'SELECT `account_id`,CONCAT(SUBSTR(`type_id`,1,1),": ",`name`) FROM `'.TABLE_PREFIX.'account` '.
               'WHERE `company_id` = "'.$company_id.'" AND `type_id` = "ASSET_CURRENT_BANK" '.
               'ORDER BY `name`';
        $param = [];
        $param['class'] = 'form-control';
        $html .= '<div class="col-sm-8">'.
                 Form::sqlList($sql,$this->db,'account_id_primary',$account_id_primary,$param).
                 '</div>';
        $html .= '</div>';
        

        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Transaction date</label>
                    </div>';
        $param = [];
        $param['class'] = 'form-control bootstrap_date input-small';
        $html .= '<div class="col-sm-8">'.
                 Form::textInput('date',$date,$param).
                 '</div>';
        $html .= '</div>';


        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Transaction amount</label>
                    </div>';
        $param = [];
        $param['class'] = 'form-control';   
        $html .= '<div class="col-sm-8">'.
                 Form::textInput('amount',$amount,$param).
                 '</div>';
        $html .= '</div>';

        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Counterparty account</label>
                    </div>';
        $sql = 'SELECT `account_id`,CONCAT(SUBSTR(`type_id`,1,1),": ",`name`) FROM `'.TABLE_PREFIX.'account` '.
               'WHERE `company_id` = "'.$company_id.'" AND (`type_id` LIKE "EXPENSE%" OR `type_id` LIKE "INCOME%") AND `status` <> "HIDE" '.
               'ORDER BY `type_id`,`name`';
        $param = [];
        $param['class'] = 'form-control';
        $html .= '<div class="col-sm-8">'.
                 Form::sqlList($sql,$this->db,'account_id',$account_id,$param);
                 if($company['vat_apply']) {
                     $html .= Form::checkBox('vat_inclusive',true,$vat_inclusive,$param); 
                 }  
        $html .= '</div>'.
                 '</div>';

        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Transaction description</label>
                    </div>';
        $html .= '<div class="col-sm-8">'.
                 Form::textAreaInput('description',$description,'30','2',$param).
                 '</div>'.
                 '</div>';

        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Process transaction</label>
                    </div>
                    <div class="col-sm-8">
                         <INPUT TYPE="submit" class="btn btn-primary" NAME="submit" VALUE="Submit transaction">
                    </div>
                  </div>';
         
        $html .= '</form>'; 

        return $html;
    }
}
