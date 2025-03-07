<?php 
namespace App\Ledger;

use Seriti\Tools\Table;
use Seriti\Tools\Date;

use App\Ledger\COMPANY_ID;
use App\Ledger\TABLE_PREFIX;
use App\Ledger\ACC_TYPE;
use App\Ledger\Helpers;


class Period extends Table 
{
    //configure
    public function setup($param = []) 
    {
        $param = ['row_name'=>'Period','col_label'=>'name'];
        parent::setup($param);        

        $this->addForeignKey(array('table'=>TABLE_PREFIX.'balance','col_id'=>'period_id','message'=>'Account Balances'));

        $this->addTableCol(array('id'=>'period_id','type'=>'INTEGER','title'=>'Period ID','key'=>true,'key_auto'=>true,'list'=>true));
        $this->addTableCol(array('id'=>'name','type'=>'STRING','title'=>'Period name'));
        $this->addTableCol(array('id'=>'date_start','type'=>'DATE','title'=>'Date START'));
        $this->addTableCol(array('id'=>'date_end','type'=>'DATE','title'=>'Date END'));
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status'));
        $this->addTableCol(array('id'=>'period_id_previous','type'=>'STRING','title'=>'Previous period',
                                 'join'=>'`name` FROM `'.$this->table.'` WHERE `period_id`'));
        //NB: changing period status is handled in system tasks
        $this->addTableCol(array('id'=>'status','type'=>'STRING','title'=>'Status','edit'=>false));

        $this->addSortOrder('T.date_start ','Start date','DEFAULT');

        $this->addSql('WHERE','T.`company_id` = "'.COMPANY_ID.'"');

        $this->addAction(array('type'=>'edit','text'=>'edit'));
        $this->addAction(array('type'=>'delete','text'=>'delete','pos'=>'R'));

        $this->addSearch(array('name','date_start','status'),array('rows'=>2));
          
        $this->addSelect('status','(SELECT "OPEN") UNION (SELECT "CLOSED")');
        $this->addSelect('period_id_previous',
                         ['xtra'=>[0=>'NONE'],
                          'sql'=>'SELECT `period_id`,`name` FROM `'.$this->table.'` WHERE `company_id` = "'.COMPANY_ID.'" ORDER BY `date_start`']);
    }

    protected function beforeDelete($id,&$error_str) {
        $sql = 'SELECT * FROM `'.$this->table.'` '.
               'WHERE `period_id` = "'.$this->db->escapeSql($id).'" ';
        $period = $this->db->readSqlRecord($sql); 
         
        $sql = 'SELECT COUNT(*) FROM `'.TABLE_PREFIX.'transact` '.
               'WHERE `company_id` = "'.COMPANY_ID.'" AND '.
                     '`date` >= "'.$period['date_start'].'" AND `date` <= "'.$period['date_end'].'" ';
                   
        $count = $this->db->readSqlValue($sql,0); 
        if($count != 0) $error_str .= 'You cannot delete as there are '.$count.' transactions within period dates!';
    }
      
    protected function beforeUpdate($id,$edit_type,&$form,&$error_str) {
        $error_str = '';
        $date_options['include_first'] = False;
        $days = 0;
        
        //check periods dates in sequence
        $days = Date::calcDays($form['date_start'],$form['date_end'],'MYSQL',$date_options);
        if($days < 10) $error_str .= 'Period end datemust be at least 10 days after start date'; 
        
        //check period consecutive after previous
        if($form['period_id_previous'] != 0) {
            $sql = 'SELECT * FROM `'.$this->table.'` '.
                   'WHERE `period_id` = "'.$this->db->escapeSql($form['period_id_previous']).'" ';
            $prev = $this->db->readSqlRecord($sql);
            if($prev == 0) {
                $error_str .= 'Previous period ID['.$form['period_id_previous'].'] is INVALID!';
            } else {
                $days = Date::calcDays($prev['date_end'],$form['date_start'],'MYSQL',$date_options);
                if($days != 1) {
                    $error_str .= 'This period start date['.$form['date_start'].'] is NOT day after '.
                                  'previous period['.$prev['name'].'] end date['.$prev['date_end'].']!';
                }
            }    
        } 
    }
      
    protected function afterUpdate($id,$edit_type,$form) {
        $error_tmp = '';
        $error_str = '';
        
        if($edit_type === 'INSERT') {
            $sql='UPDATE `'.$this->table.'` SET `company_id` = "'.COMPANY_ID.'", `status` = "OPEN" '.
                 'WHERE `period_id` = "'.$this->db->escapeSql($id).'"';
            $this->db->executeSql($sql,$error_str);
        }  
            
        Helpers::checkPeriodSequence($this->db,COMPANY_ID,$id,$error_tmp);
        if($error_tmp !== '') {
            $error_str .= 'Period sequence INVALID: '.$error_tmp;
            $this->setCache('errors',$error_str);
        }
        
    } 
}            
