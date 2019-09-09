<?php
use Seriti\Tools\Form;
use Seriti\Tools\Html;


$html = '';

$html .= '<div class="row">'.
         '<div class="col-lg-12">';

$html .= '<h1>Bank import completed.</h1>';

$html .= '<a href="bank_import"><button class="btn btn-primary">Restart wizard</button></a>';

$html .= '<p>See list of transactions below:</p>';
  
$html .= Html::arrayDumpHtml($data['import_data']);
        
$html .= '</div>'.
         '</div>';      
      
echo $html;          

//print_r($form);
//print_r($data);
?>
