<?php
namespace App\Ledger;

use Psr\Container\ContainerInterface;
use App\Ledger\Account;

class AccountController
{
    protected $container;
    
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function __invoke($request, $response, $args)
    {
        $table_name = TABLE_PREFIX.'account'; 
        $table = new Account($this->container->mysql,$this->container,$table_name);

        $table->setup();
        $html = $table->processTable();
        
        $template['html'] = $html;
        $template['title'] = MODULE_LOGO.COMPANY_NAME.': All Accounts';
        
        return $this->container->view->render($response,'admin.php',$template);
    }
}