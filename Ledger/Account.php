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
        if(CHART_SETUP) {
            $this->addTableCol(array('id'=>'chart_id','type'=>'INTEGER','title'=>'Report Chart',
                                     'join'=>'`title` FROM `'.TABLE_PREFIX.'chart` WHERE `id`'));
        }    
        $this->addTableCol(array('id'=>'name','type'=>'STRING','title'=>'Account name'));
        $this->addTableCol(array('id'=>'abbreviation','type'=>'STRING','title'=>'Abbreviation/ Code'));
        $this->addTableCol(array('id'=>'description','type'=>'STRING','title'=>'Description','size'=>40,'required'=>false));
        $this->addTableCol(array('id'=>'keywords','type'=>'TEXT','title'=>'Key words','required'=>false,'hint'=>'(keywords that occur on bank statements. separate with spaces)'));
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status'));

        $this->addSql('WHERE','T.`company_id` = "'.COMPANY_ID.'"');

        $this->addSortOrder('T.`type_id`, T.`name`','Type & Name','DEFAULT');

        $this->addAction(array('type'=>'check_box','text'=>''));
        $this->addAction(array('type'=>'edit','text'=>'edit'));
        $this->addAction(array('type'=>'delete','text'=>'delete','pos'=>'R'));
        $this->addAction(array('type'=>'popup','text'=>'Entries','url'=>'account_entry','mode'=>'view','width'=>700,'height'=>600)); 

        $this->addSearch(array('account_id','name','type_id','keywords','abbreviation','status'),array('rows'=>2));
            
        $this->addSelect('status','(SELECT "OK") UNION (SELECT "HIDE")');
        $this->addSelect('type_id',array('list'=>ACC_TYPE));
        
        if(CHART_SETUP) {
            $sql_chart = 'SELECT `id`,CONCAT(IF(`level` > 1,REPEAT("--",`level` - 1),""),`title`) FROM `'.TABLE_PREFIX.'chart`  ORDER BY `rank`';
            $this->addSelect('chart_id',$sql_chart);
        }    
    }

    protected function beforeUpdate($id,$context,&$data,&$error) 
    {
        
        //first part of account type must be ASSET,LIABILITY,INCOME,EXPENSE,EQUITY
        $arr = explode('_',$data['type_id']);
        $base_type_id = $arr[0];

        if(CHART_SETUP) {
            $sql = 'SELECT * FROM `'.TABLE_PREFIX.'chart` WHERE `id` = "'.$this->db->escapeSql($data['chart_id']).'" ';
            $chart = $this->db->readSqlRecord($sql);

            if($base_type_id !== $chart['type_id']) {
                $error .= 'Account type['.$data['type_id'].'] is not compatible with Report Chart type['.$chart['type_id'].'] ';
            } else {
                $sql = 'SELECT COUNT(*) FROM `'.TABLE_PREFIX.'chart` WHERE `id_parent` = "'.$this->db->escapeSql($data['chart_id']).'" ';
                $count_parent = $this->db->readSqlValue($sql);
                if($count_parent > 0) $error .= 'Assigned chart node['.$chart['title'].'] is not a terminal node(There are '.$count_parent.' child nodes) ';    
            }
        }    

        if($context === 'UPDATE' ) {
            $data_original = $this->get($id);

            $arr = explode('_',$data_original['type_id']);
            $base_type_id_original = $arr[0];

            if($base_type_id_original !== $base_type_id) {
                $error .= 'Account base type['.$base_type_id.'] cannot change from original['.$base_type_id_original.']. ';
            }
        } 
    }

    protected function afterUpdate($id,$edit_type,$form) {
        $error = '';
        if($edit_type === 'INSERT') {
            $sql = 'UPDATE `'.$this->table.'` SET `company_id` = "'.COMPANY_ID.'" '.
                   'WHERE `account_id` = "'.$this->db->escapeSql($id).'"';
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
