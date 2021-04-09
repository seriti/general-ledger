<?php
use Seriti\Tools\Form;
use Seriti\Tools\Html;

$list_param = ['class'=>'form-control'];
//$list_param['onchange']='display_options();';
$file_param = ['class'=>'btn btn-primary'];
$check_param = ['class'=>'form-control'];

$import_options = ['GENERIC_EXPENSE'=>'Generic EXPENSE CSV file',
                   'GENERIC_INCOME'=>'Generic INCOME CSV file',
                   'BANK_SBSA'=>'Standard Bank Current Account CSV dump',
                   'BANK_SBSA_CC'=>'Standard Bank Credit Card CSV dump'];


$html = '';

$html .= '<div class="row">'.
         '<div class="col-lg-6">';

$html .= '<div class="row"><div class="col-lg-12">'.
         '1.) Select the Bank file that you wish to import:<br/>'.
         Form::arrayList($import_options,'import_type',$form['import_type'],true,$list_param).
         '</div></div>';

$sql = 'SELECT account_id, CONCAT(type_id,":",name) FROM '.TABLE_PREFIX.'account '.
       'WHERE company_id = "'.COMPANY_ID.'" AND '.
            '(type_id = "ASSET_CURRENT_BANK" OR type_id = "LIABILITY_CURRENT_CARD") '.
       'ORDER BY type_id,name ';

$html .= '<div class="row"><div class="col-lg-12">'.
         '2.) Select the primary account that you wish to import data for:<br/>'.
         Form::sqlList($sql,$this->db,'account_id_primary',$form['account_id_primary'],$list_param).
         '</div></div>';

$html .= '<div class="row"><div class="col-lg-12">'.
         '3.) Select the data file you wish to import (*.txt or *.csv ONLY):<br/>'.
         Form::fileInput('data_file',$form['data_file'],$file_param).
         '</div></div>';  

$html .= '<div class="row"><div class="col-lg-12">'.
         '4.) Do you want to ignore any errors and import valid data?:<br/>'.
         Form::checkBox('ignore_errors',true,$form['ignore_errors'],$check_param).
         '</div></div>';

$html .= '<div class="row"><div class="col-lg-12">'.
         '5.) Upload data file and review data before processing...<br/>'.
         '<input type="submit" class="btn btn-primary" id="import_button" value="Upload & Review" onclick="link_download("import_button");">'.
         '</div></div>';

$html .= '</div>'.
         '<div class="col-lg-6">';        
                        
$html .= '<div class="row"><div class="col-lg-12">'.
         '<p><b>NB1:</b> Your CSV text file must be correctly formatted to import correctly!<br/>'.
            'Generic format: Date(YYYY-MM-DD),Amount(XXX.XX),Description,Account code(optional)<br/>'.
            'Note that Comma Separated Values and not Semi-colon. Decimal separator is period(.) and enclosure is double quote(")</p>'.
         '<p><b>NB2:</b> All banks have different CSV structure. If your bank is not supported please contact us!</p>'.
         '<p><b>NB3:</b> You will be able to review all data and allocate to individual accounts after Upload.!</p>'.
         '</div></div>';       
        
$html .= '</div>'.
         '</div>';      
      
echo $html;          

//print_r($form);
//print_r($data);
?>
