<?php 
namespace App\Ledger;

use Seriti\Tools\Table;

use App\Ledger\Helpers;
use App\Ledger\COMPANY_ID;
use App\Ledger\TABLE_PREFIX;

class Transact extends Table 
{
    //configure
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Transaction','col_label'=>'description'];
        parent::setup($param);  

        $this->modifyAccess(['edit'=>false,'add'=>false]); 

        //adds these values to any new transactions
        $this->addColFixed(['id'=>'company_id','value'=>COMPANY_ID]);
        $this->addColFixed(['id'=>'date_create','value'=>date('Y-m-d')]);

        $this->addTableCol(array('id'=>'transact_id','type'=>'INTEGER','title'=>'Transaction ID','key'=>true,'key_auto'=>true,'list'=>true));
        $this->addTableCol(array('id'=>'type_id','type'=>'STRING','title'=>'Type'));
        $this->addTableCol(array('id'=>'date_create','type'=>'DATE','title'=>'Create Date','edit'=>false));
        $this->addTableCol(array('id'=>'date','type'=>'DATETIME','title'=>'Transact Date','new'=>date('Y-m-d').' 12:00:00'));
        $this->addTableCol(array('id'=>'amount','type'=>'DECIMAL','title'=>'Amount'));
        $this->addTableCol(array('id'=>'vat_inclusive','type'=>'BOOLEAN','title'=>'VAT inclusive'));
        $this->addTableCol(array('id'=>'description','type'=>'STRING','title'=>'Description','required'=>false));
        //$this->addTableCol(array('id'=>'debit_accounts','type'=>'STRING','title'=>'DEBIT accounts','required'=>false,'edit'=>false));
        //$this->addTableCol(array('id'=>'credit_accounts','type'=>'STRING','title'=>'CREDIT accounts','required'=>false,'edit'=>false));
        $this->addTableCol(array('id'=>'account_id_primary','type'=>'INTEGER','title'=>'Primary Account',
                                 'join'=>'`name` FROM `'.TABLE_PREFIX.'account` WHERE `account_id`'));
        $this->addTableCol(array('id'=>'debit_credit','type'=>'STRING','title'=>'Debit/Credit'));
        $this->addTableCol(array('id'=>'account_id','type'=>'INTEGER','title'=>'Counterparty Account',
                                 'join'=>'`name` FROM `'.TABLE_PREFIX.'account` WHERE `account_id`'));
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status'));

        $this->addSortOrder('T.`date_create` DESC , T.`date` DESC , T.`transact_id` DESC ','Create Date, Transaction Date, most recent first','DEFAULT');

        $this->addSql('WHERE','T.`company_id` = "'.COMPANY_ID.'"');

        $this->addAction(array('type'=>'edit','text'=>'edit'));
        $this->addAction(array('type'=>'delete','text'=>'delete','pos'=>'R'));
        $this->addAction(array('type'=>'popup','text'=>'Entries','url'=>'transact_entry','mode'=>'view','width'=>600,'height'=>300)); 

        $this->addSearch(array('transact_id','date','amount','description',//'debit_accounts','credit_accounts',
                               'account_id_primary','account_id','type_id'),array('rows'=>2));

        $this->addSearchAggregate(['sql'=>'SUM(T.amount)','title'=>'Total Amount']);
            
        $this->addSelect('status','(SELECT "NEW") UNION (SELECT "OK")');
        $this->addSelect('type_id','(SELECT "CASH") UNION (SELECT "CREDIT") UNION (SELECT "CUSTOM")  UNION (SELECT "CLOSE")'); 
        $this->addSelect('debit_credit','(SELECT "D","Debit Counterparty") UNION (SELECT "C","Credit Counterparty")'); 
        $this->addSelect('account_id','SELECT `account_id`,CONCAT(`type_id`,":",`name`) AS `name` FROM `'.TABLE_PREFIX.'account` WHERE `company_id` = "'.COMPANY_ID.'" ORDER BY `type_id`,`name`'); 
        $this->addSelect('account_id_primary','SELECT `account_id`,CONCAT(`type_id`,":",`name`) AS `name` FROM `'.TABLE_PREFIX.'account` WHERE `company_id` = "'.COMPANY_ID.'" ORDER BY `type_id`,`name`'); 


    }

    protected function beforeDelete($id,&$error_str) 
    {
        $error_tmp = '';
        
        $sql = 'SELECT * FROM `'.$this->table.'` '.
               'WHERE `transact_id` = "'.$this->db->escapeSql($id).'" ';
        $transact = $this->db->readSqlRecord($sql);
        Helpers::checkTransactionPeriod($this->db,COMPANY_ID,$transact['date'],$error_tmp); 
        if($error_tmp !== '') {
            $error_str .= 'Cannot delete transaction: '.$error_tmp;
        } else {
            //remove all transaction entries
            $sql = 'DELETE FROM `'.TABLE_PREFIX.'entry` '.
                   'WHERE `transact_id` ="'.$this->db->escapeSql($id).'" ';
            $this->db->executeSql($sql,$error_tmp);
            if($error_tmp !== '') {
                $error_str .= 'Could NOT remove transaction['.$id.'] ENTRIES!';
                if($this->debug) $error_str .= $error_tmp;
            }
        }    
    }
    
    protected function beforeUpdate($id,$edit_type,&$form,&$error_str) 
    {
        $error_tmp = '';
        
        Helpers::checkTransactionPeriod($this->db,COMPANY_ID,$form['date'],$error_tmp); 
        if($error_tmp != '') $error_str .= 'Cannot update transaction: '.$error_tmp;
    }
     
}
?>