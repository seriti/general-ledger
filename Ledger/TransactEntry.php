<?php 
namespace App\Ledger;

use Seriti\Tools\Table;

use App\Ledger\TABLE_PREFIX;

class TransactEntry extends Table 
{
    //configure
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Entry','row_name_plural'=>'Entries','col_label'=>'amount','pop_up'=>true];
        parent::setup($param); 

        $this->setupMaster(array('table'=>TABLE_PREFIX.'transact','key'=>'transact_id','child_col'=>'transact_id', 
                                 'show_sql'=>'SELECT CONCAT("Transaction: ",`description`," = ",`amount`) FROM `'.TABLE_PREFIX.'transact` WHERE `transact_id` = "{KEY_VAL}" '));                        
           
        $this->modifyAccess(['read_only'=>true]);

        $this->addTableCol(array('id'=>'entry_id','type'=>'INTEGER','title'=>'Entry ID','key'=>true,'key_auto'=>true,'list'=>false));
        $this->addTableCol(array('id'=>'debit_credit','type'=>'String','title'=>'Debit Credit'));
        $this->addTableCol(array('id'=>'amount','type'=>'DECIMAL','title'=>'Amount'));
        $this->addTableCol(array('id'=>'account_id','type'=>'INTEGER','title'=>'Account',
                                 'join'=>'`name` FROM `'.TABLE_PREFIX.'account` WHERE `account_id`'));
        $this->addTableCol(array('id'=>'date','type'=>'DATE','title'=>'Date'));
    }
}

?>