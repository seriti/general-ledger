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

class TransactCustom 
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
    protected $debit_count = 0;
    protected $credit_count = 0;
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

        $page_title = MODULE_LOGO.': CUSTOM Transaction';

        $this->mode = 'new';
        if(isset($_GET['mode'])) $this->mode = Secure::clean('alpha',$_GET['mode']);

        $type_id = 'CUSTOM';
        $company_id = COMPANY_ID;
        Helpers::getCompany($this->db,$company_id);

        if($this->mode === 'new') {
            $date = date('Y-m-d');
            $time = '00:00';
            $description = '';
            $amount = '';
            
            $this->debit_count = 0;
            $this->credit_count = 0;
        }

        if($this->mode === 'update') {
            $time = '00:00';
            
            $date = $_POST['date'];
            Validate::date('Transaction date',$date,'YYYY-MM-DD',$error_str);
            if($error_str !== '') $this->addError($error_str);
            
            $description = $_POST['description'];
            Validate::text('Description',0,64000,$description,$error_str);
            if($error_str !== '') $this->addError($error_str);
            
            $this->debit_count = $_POST['debit_count'];
            if(!is_numeric($this->debit_count)) $this->addError('Invalid account debit counter!'); 
            
            $this->credit_count = $_POST['credit_count'];
            if(!is_numeric($this->credit_count)) $this->addError('Invalid account credit counter!'); 
            
            //get account list for validation and messages
            $sql = 'SELECT `account_id`,`name` FROM `'.TABLE_PREFIX.'account` '.
                   'WHERE `company_id` = "'.$company_id.'" AND `status` <> "HIDE" '.
                   'ORDER BY `name`';
            $accounts = $this->db->readSqlList($sql);  
                        
            if(!$this->errors_found) {
                //validate all debit accounts
                $debit_accounts = [];
                $debit_total = 0.00;
                for($i = 1; $i <= $this->debit_count; $i++) {
                    $type = 'DEBIT';
                    $name_acc = 'debit_acc_'.$i;
                    $name_amount = 'debit_'.$i;
                    if(isset($_POST[$name_acc])) {
                        $acc_id = $_POST[$name_acc];
                        $amount = abs($_POST[$name_amount]);
                        if(!is_numeric($acc_id) or !isset($accounts[$acc_id])) {
                            $this->addError('Invalid '.$type.'account ID['.$acc_id.']');
                        } else {
                            $acc_desc = $type.' Account['.$accounts[$acc_id].'] invalid amount['.$amount.']';
                            Validate::number($acc_desc,$amount_min,$amount_max,$amount,$error_str);
                            if($error_str !== '') $this->addError($error_str);
                        }  
                        
                        if(isset($debit_accounts[$acc_id])) $debit_accounts[$acc_id] += $amount; else $debit_accounts[$acc_id] = $amount;
                        $debit_total += $amount;
                    }
                }
                //NB:$_POST['debit count'] includes gaps from deletes and also allows same account to be added twice
                $this->debit_count = count($debit_accounts);
                if($this->debit_count == 0) $this->addError('NO DEBIT accounts specified!');
                                
                //validate all credit accounts
                $credit_accounts = [];
                $credit_total = 0.00;
                for($i = 1; $i <= $this->credit_count; $i++) {
                    $type = 'CREDIT';
                    $name_acc = 'credit_acc_'.$i;
                    $name_amount = 'credit_'.$i;
                    if(isset($_POST[$name_acc])) {
                        $acc_id = $_POST[$name_acc];
                        $amount = abs($_POST[$name_amount]);
                        if(!is_numeric($acc_id) or !isset($accounts[$acc_id])) {
                            $this->addError('Invalid '.$type.'account ID['.$acc_id.']');
                        } else {
                            $acc_desc = $type.' Account['.$accounts[$acc_id].'] invalid amount['.$amount.']';
                            Validate::number($acc_desc,$amount_min,$amount_max,$amount,$error_str);
                            if($error_str !== '') $this->addError($error_str);
                        }  
                        
                        if(isset($credit_accounts[$acc_id])) $credit_accounts[$acc_id] += $amount; else $credit_accounts[$acc_id] = $amount; 
                        $credit_total += $amount;
                    }
                }
                //NB:$_POST['credit count'] includes gaps from deletes and also allows same account to be added twice
                $this->credit_count = count($credit_accounts);
                if($this->credit_count == 0) $this->addError('NO CREDIT accounts specified!');
                
                if($credit_total !== $debit_total) {
                    $this->addError('DEBIT total['.$debit_total.'] NOT = CREDIT total['.$credit_total.']'); 
                } else {
                    $transact_amount = $credit_total;
                }   
                
            }  
            
            //finally process transaction
            if(!$this->errors_found) {
                $transact = array();
                $transact['status'] = 'NEW';
                $transact['company_id'] = $company_id;
                $transact['type_id'] = $type_id;
                $transact['date_create'] = date('Y-m-d');
                $transact['date'] = $date.' '.$time;
                
                //for foreign key restrictions but not used with type_id = CUSTOM
                $transact['account_id'] = '0';
                //again not relevant for custom transactions with multiple debits/credits
                $transact['debit_credit'] = '';
                        
                $transact['amount'] = abs($transact_amount); 
                $transact['vat_inclusive'] = false; //always for custom entries
                $transact['debit_accounts'] = json_encode($debit_accounts);
                $transact['credit_accounts'] = json_encode($credit_accounts);
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
                $this->mode = 'new';
                $this->addMessage('Successfully processed transaction!'); 
                $this->addMessage('Capture another transaction, or <a href="javascript:window.close()">Close</a>.'); 
                
                $this->debit_count = 0;
                $this->credit_count = 0;
                $description = '';
                $amount = '';
            }  
        }

        $html = '<a href="Javascript:onClick=window.close()">[close]</a>'; 
        
        $html .= '<div class="row"><div class="col-sm-12">'.$this->viewMessages().'</div></div>';
                    
        $html .= '<form method="post" action="?mode=update" name="transact_custom">';

        $param = [];
        $param['class'] = 'form-control bootstrap_date input-small';
        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Transaction date</label>
                    </div>
                    <div class="col-sm-8">'.Form::textInput('date',$date,$param).'</div>
                </div>';  
        
        $param = [];
        $param['class'] = 'form-control';  
        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Transaction description</label>
                    </div>
                    <div class="col-sm-8">'.Form::textAreaInput('description',$description,'30','2',$param).'</div>
                </div>'; 
            
        $html .= '<div class="row"><div class="col-sm-12">
                    <table width="100%">
                      <tr>
                      <td width="48%" valign="top">
                        <h1>DEBIT accounts: <a href="javascript:add_account(\'debit\')">[add]</a></h1>
                        <table id="table_debit" class="table  table-striped table-bordered table-hover table-condensed">
                        <tr><th width="70%">Account</th><th width="30%">Amount</th><th></th></tr>';

                        if($this->mode === 'update') {
                            $sql = 'SELECT `account_id`,CONCAT(SUBSTR(`type_id`,1,1),": ",`name`) FROM `'.TABLE_PREFIX.'account` '.
                                   'WHERE `company_id` = "'.$company_id.'" AND `status` <> "HIDE" '.
                                   'ORDER BY `type_id`,`name`';
                            $param = [];
                            $param['class'] = 'form-control';
                            
                            $i = 0;
                            foreach($debit_accounts as $acc_id => $amount) {
                                $i++;
                                $name_acc = 'debit_acc_'.$i;
                                $name_amount = 'debit_'.$i;
                                
                                $html .= '<tr>'.
                                         '<td>'.Form::sqlList($sql,$this->db,$name_acc,$acc_id,$param).'</td>'.
                                         '<td>'.Form::textInput($name_amount,$amount,$param).'</td>'.
                                         '<td><a href="#" onclick="delete_row(this)"><img src="/images/cross.png"></a></td>'.
                                         '</tr>';
                            }  
                             
                        }  
                        
        $html .=       '</table>
                      </td>
                      <td>&nbsp;</td>
                      <td width="48%" valign="top">
                        <h1>CREDIT accounts: <a href="javascript:add_account(\'credit\')">[add]</a></h1>
                        <table id="table_credit" class="table  table-striped table-bordered table-hover table-condensed">
                        <tr><th width="70%">Account</th><th width="30%">Amount</th><th></th></tr>';
            
                        if($this->mode === 'update') {
                            $sql = 'SELECT `account_id`,CONCAT(SUBSTR(`type_id`,1,1),": ",`name`) FROM `'.TABLE_PREFIX.'account` '.
                                   'WHERE `company_id` = "'.$company_id.'" AND `status` <> "HIDE" '.
                                   'ORDER BY `type_id`,`name`';
                            $param = [];
                            $param['class'] = 'form-control';
                            
                            $i = 0;
                            foreach($credit_accounts as $acc_id => $amount) {
                                $i++;
                                $name_acc = 'credit_acc_'.$i;
                                $name_amount = 'credit_'.$i;
                                
                                $html .= '<tr>'.
                                         '<td>'.Form::sqlList($sql,$this->db,$name_acc,$acc_id,$param).'</td>'.
                                         '<td>'.Form::textInput($name_amount,$amount,$param).'</td>'.
                                         '<td><a href="#" onclick="delete_row(this)"><img src="/images/cross.png"></a></td>'.
                                         '</tr>';
                            }  
                             
                        }  
            
        $html .=       '</table>
                      </td>
            
                    </tr>
                  </table>
                </div></div>';

        $html .= '<div class="row">
                    <div class="col-sm-4">
                        <label>Process transaction</label>
                    </div>
                    <div class="col-sm-8">
                         <INPUT TYPE="hidden" NAME="debit_count" ID="debit_count" VALUE="'.$this->debit_count.'">
                         <INPUT TYPE="hidden" NAME="credit_count" ID="credit_count" VALUE="'.$this->credit_count.'">
                         <INPUT TYPE="submit" class="btn btn-primary" NAME="submit" VALUE="Submit transaction">
                    </div>
                  </div>';
         
        $html .= '</form>'; 

        return $html;

    }

    public function getJavascript()
    {
        $js = '<script language="javascript">';

        $sql = 'SELECT `account_id`,CONCAT(SUBSTR(`type_id`,1,1),": ",`name`) FROM `'.TABLE_PREFIX.'account` '.
               'WHERE `company_id` = "'.COMPANY_ID.'" AND `status` <> "HIDE" '.
               'ORDER BY `type_id`,`name`';
        $param = [];
        $param['class'] = 'form-control';
        $account_id = 0;
        $html_acc = Form::sqlList($sql,$this->db,'account_id',$account_id,$param);

        $js .= 'var html_acc = \''.$html_acc.'\';'.
               'var html_amount = \'<input type="text" id="amount_id" name="amount_id" class="form-control">\';';
        
        $js .= 'var debit_count = '.$this->debit_count.' ;';
        $js .= 'var credit_count = '.$this->credit_count.' ;';
        $js .= 'var state = 0;';

        $js .= '
        function add_account(debit_credit) {
            //alert(debit_credit); 
            
            var input_debit_count = document.getElementById(\'debit_count\');
            var input_credit_count = document.getElementById(\'credit_count\');
            
            var html_acc_select = html_acc;
            var html_acc_amount = html_amount;
            var html_acc_delete = \'<a href="#" onclick="delete_row(this)"><img src="/images/cross.png"></a>\';
            
            var account_name = \'\'; 
            var amount_name = \'\';  
            if(debit_credit == \'debit\') {
                debit_count++;
                amount_name = \'debit_\'+debit_count;
                account_name = \'debit_acc_\'+debit_count;
                
                input_debit_count.value = debit_count;
            }  
            if(debit_credit == \'credit\') {
                credit_count++;
                amount_name = \'credit_\'+credit_count;
                account_name = \'credit_acc_\'+credit_count;
                input_credit_count.value = credit_count;
            }  
            
            html_acc_select = html_acc_select.replace(/account_id/g,account_name);
            html_acc_amount = html_acc_amount.replace(/amount_id/g,amount_name); 
                
            var table_id = \'table_\'+debit_credit;
            var table = document.getElementById(table_id);
            var row = table.insertRow();
                
            row.innerHTML = \'<td>\'+html_acc_select+\'</td><td>\'+html_acc_amount+\'</td><td>\'+html_acc_delete+\'</td>\';
            
        }';  

        $js .= '
        function delete_row(link) {
            //$(btn).closest("tr").remove();
            var row = link.parentNode.parentNode;
            row.parentNode.removeChild(row);
        }';

        if($this->mode === 'new') {
            $js .= '
            //initialise with a single debit and credit
            $(document).ready(function() {
                add_account(\'debit\');
                add_account(\'credit\'); 
            });';

        }

        $js .= '</script>';

        return $js;
    }

}