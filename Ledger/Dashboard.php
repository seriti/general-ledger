<?php
namespace App\Ledger;

use Seriti\Tools\Dashboard AS DashboardTool;

class Dashboard extends DashboardTool
{
    
    public function setup($param = []) 
    {
        $this->col_count = 2;  

        $login_user = $this->getContainer('user'); 

        //(block_id,col,row,title)
        $this->addBlock('TRANSACT',1,1,'Capture transactions');
        $this->addItem('TRANSACT','Add a CASH transaction',['link'=>"javascript:open_popup('transact_cash',400,600)"]);
        $this->addItem('TRANSACT','Add a CREDIT transaction',['link'=>"javascript:open_popup('transact_credit',400,600)"]);
        $this->addItem('TRANSACT','Add a CUSTOM transaction',['link'=>"javascript:open_popup('transact_custom',800,600)"]);
        $this->addItem('TRANSACT','Import transactions from bank CSV file.',['link'=>"task?mode=task&id=IMPORT_BANK"]);

        $this->addBlock('SYSTEM',1,2,'System');
        $this->addItem('SYSTEM','All Tasks',['link'=>"task"]);
        $this->addItem('SYSTEM','Change Company',['link'=>"task?mode=task&id=CHANGE_COMPANY"]);
                
        if($login_user->getAccessLevel() === 'GOD') {
            $this->addBlock('CONFIG',1,3,'Module Configuration');
            $this->addItem('CONFIG','Setup Database',['link'=>'setup_data','icon'=>'setup']);
        }    
        
    }

}
