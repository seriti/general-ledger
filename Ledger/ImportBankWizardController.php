<?php
namespace App\Ledger;

use Psr\Container\ContainerInterface;

use App\Ledger\BankImport;
use App\Ledger\ImportBankWizard;

use Seriti\Tools\Template;
use Seriti\Tools\BASE_TEMPLATE;

class ImportBankWizardController
{
    protected $container;
    

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }


    public function __invoke($request, $response, $args)
    {
        $cache = $this->container->cache;
        $user_specific = true;
        $cache->setCache('Import_bank_wizard',$user_specific);

        $wizard_template = new Template(BASE_TEMPLATE);


        $table = TABLE_PREFIX.'transact';
        $import = New BankImport($this->container->mysql,$this->container,$table);
        
        $wizard = new ImportBankWizard($this->container->mysql,$this->container,$cache,$wizard_template);
        
        $wizard->setup();
        $wizard->addImport($import);

        $html = $wizard->process();

        $template['html'] = $html;
        $template['title'] = MODULE_LOGO.COMPANY_NAME.': Bank import wizard ';
        //$template['javascript'] = $dashboard->getJavascript();

        return $this->container->view->render($response,'admin.php',$template);
    }
}