<?php
namespace App\Ledger;

use Seriti\Tools\Tree;
//use Seriti\Tools\Crypt;
//use Seriti\Tools\Form;
//use Seriti\Tools\Secure;
//use Seriti\Tools\Audit;

use App\Ledger\ACC_TYPE;

class Chart extends Tree
{
     
    public function setup($param = []) 
    {
        parent::setup($param); 

        $this->addTreeCol(array('id'=>'type_id','type'=>'STRING','title'=>'Account type'));

        $this->addSelect('type_id',array('list'=>CHART_TYPE));

    }

    protected function viewNodeName($data) 
    {
        if($data[$this->tree_cols['level']] == 1) $prefix = $data['type_id'].': '; else $prefix = '';

        $name = $prefix.$data[$this->tree_cols['title']];

        return $name;
    }

    protected function beforeDelete($id,&$error) 
    {
        $sql = 'SELECT COUNT(*) FROM `'.TABLE_PREFIX.'account` WHERE `chart_id` = "'.$this->db->escapeSql($id).'" ';
        $count = $this->db->readSqlValue($sql,0);
        if($count != 0) $error .= 'You cannot delete chart account as there are ['.$count.'] linked transactional accounts.';
    }


    protected function beforeNodeUpdate($id,$edit_type,&$form,&$error) {
        $parent_id = $form[$this->tree_cols['parent']];
        $data_parent = $this->get($parent_id);

        if($edit_type === 'UPDATE' ) {
            $data_original = $this->get($id);
            $data_original_parent = $this->get($data_original[$this->tree_cols['parent']]);

            if($data_original['type_id'] !== $form['type_id']) {
                $error .= 'Chart account type['.$form['type_id'].'] cannot change from original['.$data_original['type_id'].'].';
            }

            if($data_parent['type_id'] !== $data_original_parent['type_id']) {
                $error .= 'Chart account parent type['.$data_parent['type_id'].'] cannot change from original['.$data_original_parent['type_id'].'].';
            }

        } 

        if($form[$this->tree_cols['parent']] != 0) {
            if($data_parent['type_id'] !== $form['type_id']) {
                $error .= 'Chart account type['.$form['type_id'].'] is not same as parent type['.$data_parent['type_id'].']';
            }
        }

        

    }
}
