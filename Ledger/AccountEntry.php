<?php 
namespace App\Ledger;

use Seriti\Tools\Table;

use App\Ledger\TABLE_PREFIX;
use App\Ledger\MODULE_LOGO;
use App\Ledger\COMPANY_NAME;

class AccountEntry extends Table 
{
    //configure
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Entry','row_name_plural'=>'Entries','col_label'=>'amount','pop_up'=>true];
        parent::setup($param); 

        $this->setupMaster(array('table'=>TABLE_PREFIX.'account','key'=>'account_id','child_col'=>'account_id', 
                         'show_sql'=>'SELECT CONCAT("Account: ",`name`) FROM `'.TABLE_PREFIX.'account` WHERE `account_id` = "{KEY_VAL}" '));                        

        $this->modifyAccess(['read_only'=>true]);

        $this->addTableCol(array('id'=>'entry_id','type'=>'INTEGER','title'=>'Entry ID','key'=>true,'key_auto'=>true,'list'=>false));
        $this->addTableCol(array('id'=>'debit_credit','type'=>'String','title'=>'Debit Credit'));
        $this->addTableCol(array('id'=>'amount','type'=>'DECIMAL','title'=>'Amount'));
        $this->addTableCol(array('id'=>'transact_id','type'=>'INTEGER','title'=>'Transaction',
                                 'join'=>'`description` FROM `'.TABLE_PREFIX.'transact` WHERE `transact_id`'));
        $this->addTableCol(array('id'=>'date','type'=>'DATE','title'=>'Date'));

        $this->addSortOrder('T.`date` DESC','Transaction Date, most recent first','DEFAULT');

        $this->addSql('JOIN','LEFT JOIN `'.TABLE_PREFIX.'transact` AS TR ON(T.`transact_id` = TR.`transact_id`)');

        $this->addSearch(array('debit_credit','amount','date'),array('rows'=>2));
        $this->addSearchXtra('TR.`description`','Transaction');


    }
}
