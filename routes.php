<?php  
/*
NB: This is not stand alone code and is intended to be used within "seriti/slim3-skeleton" framework
The code snippet below is for use within an existing src/routes.php file within this framework
copy the "/ledger" group into the existing "/admin" group within existing "src/routes.php" file 
*/

$app->group('/admin', function () {

    $this->group('/ledger', function () {
        $this->any('/dashboard', \App\Ledger\DashboardController::class);
        $this->any('/account', \App\Ledger\AccountController::class);
        $this->any('/account_entry', \App\Ledger\AccountEntryController::class);
        $this->any('/transact', \App\Ledger\TransactController::class);
        $this->any('/transact_entry', \App\Ledger\TransactEntryController::class);
        $this->any('/transact_cash', \App\Ledger\TransactCashController::class);
        $this->any('/transact_credit', \App\Ledger\TransactCreditController::class);
        $this->any('/transact_custom', \App\Ledger\TransactCustomController::class);
        $this->any('/period', \App\Ledger\PeriodController::class);
        $this->any('/company', \App\Ledger\CompanyController::class);
        $this->any('/task', \App\Ledger\TaskController::class);
        $this->any('/report', \App\Ledger\ReportController::class);
        $this->post('/ajax', \App\Ledger\Ajax::class);
        $this->any('/bank_import', \App\Ledger\ImportBankWizardController::class);
        $this->get('/setup_data', \App\Ledger\SetupDataController::class);
    })->add(\App\Ledger\Config::class);

})->add(\App\User\ConfigAdmin::class);



