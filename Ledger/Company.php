<?php 
namespace App\Ledger;

use Seriti\Tools\Table;

use App\Ledger\TABLE_PREFIX;
use App\Ledger\ACC_TYPE;


class Company extends Table 
{
    
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Company','row_name_plural'=>'Companies','col_label'=>'name'];
        parent::setup($param);        

        $this->addForeignKey(array('table'=>TABLE_PREFIX.'account','col_id'=>'company_id','message'=>'Account'));
        $this->addForeignKey(array('table'=>TABLE_PREFIX.'transact','col_id'=>'company_id','message'=>'Transaction'));

        $this->addTableCol(array('id'=>'company_id','type'=>'INTEGER','title'=>'Company ID','key'=>true,'key_auto'=>true,'list'=>false));
        $this->addTableCol(array('id'=>'name','type'=>'STRING','title'=>'Company name'));
        $this->addTableCol(array('id'=>'description','type'=>'STRING','title'=>'Description','size'=>40,'required'=>false));
        $this->addTableCol(array('id'=>'date_start','type'=>'DATE','title'=>'Date started','new'=>date('Y-m-d')));
        $this->addTableCol(array('id'=>'vat_apply','type'=>'BOOLEAN','title'=>'VAT applicable'));
        $this->addTableCol(array('id'=>'vat_rate','type'=>'STRING','title'=>'VAT rate(%)','new'=>'15'));
        $this->addTableCol(array('id'=>'vat_account_id','type'=>'INTEGER','title'=>'VAT liability account','new'=>0,
                                 'join'=>'`name` FROM `'.TABLE_PREFIX.'account` WHERE `account_id`'));
        $this->addTableCol(array('id'=>'ret_account_id','type'=>'INTEGER','title'=>'Retained earnings account','new'=>0,
                                 'join'=>'`name` FROM `'.TABLE_PREFIX.'account` WHERE `account_id`'));
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status'));

        $this->addAction(array('type'=>'edit','text'=>'edit'));
        $this->addAction(array('type'=>'delete','text'=>'delete','pos'=>'R'));

        //NB: CANNOT HAVE ANY ACCOUNT_ID FIELDS IN SEARCH OPTIONS
        $this->addSearch(array('name','description','date_start','vat_apply','status'),array('rows'=>2));
          
        $this->addSelect('status','(SELECT "OK") UNION (SELECT "INACTIVE")');
    }

    protected function beforeProcess($company_id = 0)
    {
         
        if($company_id != 0) {
            $company_id = $this->db->escapeSql($company_id);
            $this->addSelect('vat_account_id','SELECT `account_id`,`name` FROM `'.TABLE_PREFIX.'account` '.
                                              'WHERE `company_id` = "'.$company_id.'" AND `type_id` = "LIABILITY_CURRENT" ORDER BY `name`');
            $this->addSelect('ret_account_id','SELECT `account_id`,`name` FROM `'.TABLE_PREFIX.'account` '.
                                              'WHERE `company_id` = "'.$company_id.'" AND `type_id` = "EQUITY_EARNINGS" ORDER BY `name`');
        }   
         
         
        
    }

}            
