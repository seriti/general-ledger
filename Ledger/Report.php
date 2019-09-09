<?php
namespace App\Ledger;

use Seriti\Tools\Form;
use Seriti\Tools\Report AS ReportTool;

class Report extends ReportTool
{
     

    //configure
    public function setup() 
    {
        //$this->report_header = '';
        //$this->always_list_reports = true;

        $param = ['input'=>['select_period','select_format']];
        $this->addReport('INCOME_STATEMENT','Income statement',$param); 
        $this->addReport('BALANCE_SHEET','Balance sheet',$param); 
        
        $this->addInput('select_period','Select accounting period');
        $this->addInput('select_format','Select Report format');
    }

    protected function viewInput($id,$form = []) 
    {
        $html = '';
        
        if($id === 'select_period') {
            $param = [];
            $param['class'] = 'form-control input-medium';
            $sql = 'SELECT period_id,name FROM '.TABLE_PREFIX.'period '.
                   'WHERE company_id = "'.COMPANY_ID.'" '.
                   'ORDER BY date_start '; 
            if(isset($form['period_id'])) $period_id = $form['period_id']; else $period_id = '';
            $html .= Form::sqlList($sql,$this->db,'period_id',$period_id,$param);
        }

        if($id === 'select_format') {
            if(isset($form['format'])) $format = $form['format']; else $format = 'HTML';
            $html.= Form::radiobutton('format','PDF',$format).'&nbsp;<img src="/images/pdf_icon.gif">&nbsp;PDF document<br/>';
            $html.= Form::radiobutton('format','CSV',$format).'&nbsp;<img src="/images/excel_icon.gif">&nbsp;CSV/Excel document<br/>';
            $html.= Form::radiobutton('format','HTML',$format).'&nbsp;Show on page<br/>';
        }

        return $html;       
    }

    protected function processReport($id,$form = []) 
    {
        $html = '';
        $error = '';
        
        if($id === 'INCOME_STATEMENT') {
            $options = [];
            $options['format'] = $form['format'];
            $html .= Helpers::incomeStatement($this->db,COMPANY_ID,$form['period_id'],$options,$error);
            if($error !== '') $this->addError($error);
        }

        if($id === 'BALANCE_SHEET') {
            $options = [];
            $options['format'] = $form['format'];
            $html .= Helpers::balanceSheet($this->db,COMPANY_ID,$form['period_id'],$options,$error);
            if($error !== '') $this->addError($error);
        }

        return $html;
    }
}

?>