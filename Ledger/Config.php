<?php 
namespace App\Ledger;

use Psr\Container\ContainerInterface;
use Seriti\Tools\BASE_URL;
use Seriti\Tools\SITE_NAME;

class Config
{
    
    protected $container;

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;

    }

    /**
     * Example middleware invokable class
     *
     * @param  \Psr\Http\Message\ServerRequestInterface $request  PSR7 request
     * @param  \Psr\Http\Message\ResponseInterface      $response PSR7 response
     * @param  callable                                 $next     Next middleware
     *
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function __invoke($request, $response, $next)
    {
        
        $module = $this->container->config->get('module','ledger');
        //$ledger = $this->container->config->get('module','ledger');
        $menu = $this->container->menu;
        $cache = $this->container->cache;
        $db = $this->container->mysql;

        $user_specific = true;
        $cache->setCache('Ledger',$user_specific);
        
        define('TABLE_PREFIX',$module['table_prefix']);
        define('MODULE_ID','LEDGER');
        define('MODULE_LOGO','<img src="'.BASE_URL.'images/accounts40.png"> ');
        define('MODULE_PAGE',URL_CLEAN_LAST);

        define('ACC_TYPE',['ASSET_CURRENT'=>'ASSETS: Current',
                           'ASSET_CURRENT_BANK'=>'ASSETS: Bank accounts',
                           'ASSET_CURRENT_DUE'=>'ASSETS: Accounts receiveable',
                           'ASSET_FIXED'=>'ASSETS: Fixed assets',
                           'ASSET_OTHER'=>'ASSETS: Long term assets',
                           'LIABILITY_CURRENT'=>'LIABILITY: Current liabilities',
                           'LIABILITY_CURRENT_CARD'=>'LIABILITY: Credit card',
                           'LIABILITY_CURRENT_DUE'=>'LIABILITY: Accounts payable',
                           'LIABILITY_FIXED'=>'LIABILITY: Fixed liabilities',
                           'LIABILITY_OTHER'=>'LIABILITY: Long term liabilities',
                           'EQUITY_OWNER'=>'EQUITY: Owners equity',
                           'EQUITY_EARNINGS'=>'EQUITY: Retained earnings',
                           'EQUITY_OTHER'=>'EQUITY: Other equity accounts',
                           'INCOME_SALES'=>'INCOME: Sales Revenue',
                           'INCOME_OTHER'=>'INCOME: Non-Sales Revenue',
                           'EXPENSE_SALES'=>'EXPENSE: Cost of sales',
                           'EXPENSE_FIXED'=>'EXPENSE: Fixed overheads',
                           'EXPENSE_OTHER'=>'EXPENSE: Other expenses']);

        $user_data = $cache->retrieveAll();
        $table_company = TABLE_PREFIX.'company';
        if(!isset($user_data['company_id'])) {
            //first run on setup fails if table does not exist
            if($db->checkTableExists($table_company)) {
                $sql = 'SELECT company_id FROM '.$table_company.' ORDER BY name LIMIT 1';
                $company_id = $db->readSqlValue($sql,0);
                if($company_id !== 0) {
                    $user_data['company_id'] = $company_id;
                    $cache->store('company_id',$company_id);  
                }   
            }  
        }   

        if(isset($user_data['company_id'])) {
            $sql = 'SELECT company_id,name,description,status FROM '.$table_company.' '.
                   'WHERE company_id = "'.$user_data['company_id'].'" ';    
            $company = $db->readSqlRecord($sql);
            define('COMPANY_ID',$user_data['company_id']);
            define('COMPANY_NAME',$company['name']);
        } else {
            define('COMPANY_ID',0);
            define('COMPANY_NAME','');
        }
                
        $submenu_html = $menu->buildNav($module['route_list'],MODULE_PAGE);
        $this->container->view->addAttribute('sub_menu',$submenu_html);
       
        $response = $next($request, $response);
        
        return $response;
    }
}