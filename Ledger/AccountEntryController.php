<?php
namespace App\Ledger;

use Psr\Container\ContainerInterface;

use App\Ledger\AccountEntry;
use App\Ledger\TABLE_PREFIX;
use App\Ledger\MODULE_LOGO;
use App\Ledger\COMPANY_NAME;


class AccountEntryController
{
    protected $container;
    
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function __invoke($request, $response, $args)
    {
        $table_name = TABLE_PREFIX.'entry'; 
        $table = new AccountEntry($this->container->mysql,$this->container,$table_name);

        $table->setup();
        $html = $table->processTable();
        
        $template['html'] = $html;
        $template['title'] = MODULE_LOGO.COMPANY_NAME.': Account entries';
        
        return $this->container->view->render($response,'admin_popup.php',$template);
    }
}