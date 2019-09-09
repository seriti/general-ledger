<?php 
namespace App\Ledger;

use Seriti\Tools\Table;

use App\Ledger\COMPANY_ID;
use App\Ledger\TABLE_PREFIX;
use App\Ledger\ACC_TYPE;


class Account extends Table 
{
    //configure
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Account','col_label'=>'name'];
        parent::setup($param);        

        /*
        $this->addForeignKey(array('table'=>TABLE_PREFIX.'company','col_id'=>'vat_account_id','message'=>'Company VAT account'));
        $this->addForeignKey(array('table'=>TABLE_PREFIX.'company','col_id'=>'ret_account_id','message'=>'Company Retained Earnings account'));
        */
        $this->addForeignKey(array('table'=>TABLE_PREFIX.'entry','col_id'=>'account_id','message'=>'Transaction entry'));
        $this->addForeignKey(array('table'=>TABLE_PREFIX.'transact','col_id'=>'account_id','message'=>'Transactions exist for this account'));  

        $this->addTableCol(array('id'=>'account_id','type'=>'INTEGER','title'=>'Account ID','key'=>true,'key_auto'=>true,'list'=>true));
        $this->addTableCol(array('id'=>'type_id','type'=>'STRING','title'=>'Type'));
        $this->addTableCol(array('id'=>'name','type'=>'STRING','title'=>'Account name'));
        $this->addTableCol(array('id'=>'abbreviation','type'=>'STRING','title'=>'Abbreviation/ Code'));
        $this->addTableCol(array('id'=>'description','type'=>'STRING','title'=>'Description','size'=>40,'required'=>false));
        $this->addTableCol(array('id'=>'keywords','type'=>'TEXT','title'=>'Key words','required'=>false,'hint'=>'(keywords that occur on bank statements. separate with spaces)'));
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status'));

        $this->addSql('WHERE','T.company_id = "'.COMPANY_ID.'"');

        $this->addSortOrder('T.type_id, T.name','Type & Name','DEFAULT');

        $this->addAction(array('type'=>'check_box','text'=>''));
        $this->addAction(array('type'=>'edit','text'=>'edit'));
        $this->addAction(array('type'=>'delete','text'=>'delete','pos'=>'R'));
        $this->addAction(array('type'=>'popup','text'=>'Entries','url'=>'account_entry','mode'=>'view','width'=>700,'height'=>600)); 

        $this->addSearch(array('account_id','name','type_id','keywords','abbreviation','status'),array('rows'=>2));
            
        $this->addSelect('status','(SELECT "OK") UNION (SELECT "HIDE")');
        $this->addSelect('type_id',array('list'=>ACC_TYPE));
    }

    protected function afterUpdate($id,$edit_type,$form) {
        $error = '';
        if($edit_type === 'INSERT') {
            $sql = 'UPDATE '.$this->table.' SET company_id = "'.COMPANY_ID.'" '.
                   'WHERE account_id = "'.$this->db->escapeSql($id).'"';
            $this->db->executeSql($sql,$error);
        } 
    }

    protected function modifyRowValue($col_id,$data,&$value) {
        if($col_id === 'type_id') {
            if(isset(ACC_TYPE[$value])) {
                $value = ACC_TYPE[$value];
            } 
        }  
        
    } 
}            

?>
                                                
