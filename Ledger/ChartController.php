<?php
namespace App\Ledger;

use App\Ledger\Chart;
use Psr\Container\ContainerInterface;

class ChartController
{
    protected $container;
    

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }


    public function __invoke($request, $response, $args)
    {
        
        
        if($this->container->user->getAccessLevel() !== 'GOD') {
            $template['html'] = '<h1>Insufficient access rights!</h1>';
        } else {  
                    
            $table = TABLE_PREFIX.'chart';

            $tree = new Chart($this->container->mysql,$this->container,$table);

            $param = ['row_name'=>'Account chart','col_label'=>'title'];
            $tree->setup($param);
            $html = $tree->processTree();
            
            $template['html'] = $html;
            $template['title'] = MODULE_LOGO.'Account chart';
            
            //$template['javascript'] = $tree->getJavascript();
        }    
        
        return $this->container->view->render($response,'admin.php',$template);
    }
}