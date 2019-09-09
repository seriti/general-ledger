<?php
namespace App\Ledger;

use Psr\Container\ContainerInterface;

use App\Ledger\TransactCredit;

class TransactCreditController
{
    protected $container;
    

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }


    public function __invoke($request, $response, $args)
    {
        $transact = new TransactCredit($this->container->mysql,$this->container);
        
        $html = $transact->process();

        $template['html'] = $html;
        $template['title'] = MODULE_LOGO.COMPANY_NAME.': CREDIT Transaction ';
        //$template['javascript'] = $dashboard->getJavascript();

        return $this->container->view->render($response,'admin_popup.php',$template);
    }
}