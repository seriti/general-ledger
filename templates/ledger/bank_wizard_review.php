<?php
use Seriti\Tools\Form;
use Seriti\Tools\Html;

$list_param = ['class'=>'form-control'];
//$list_param['onchange']='display_options();';
$file_param = ['class'=>'btn btn-primary'];
$check_param = ['class'=>'form-control'];

$html = '';

$html .= '<div class="row">'.
         '<div class="col-lg-12">';

$html .= '<input type="submit" class="btn btn-primary" id="submit_button" '.
           'value="Submit Transactions for selected accouts" onclick="link_download("submit_button");">';

$html .= '<p>Assign description keywords to accounts for future recognition: '.Form::checkBox('assign_keywords','1',$form['assign_keywords']).'</p>';
  
$html .= '<p><strong>PRIMARY ACCOUNT: '.$data['primary_account']['name'].'</strong></p>';

$html .= $data['confirm_form'];
        
$html .= '</div>'.
         '</div>';      
      
echo $html;          

//print_r($form);
//print_r($data);
?>
