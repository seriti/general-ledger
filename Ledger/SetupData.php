<?php
namespace App\Ledger;

use Seriti\Tools\SetupModuleData;

class SetupData extends SetupModuledata
{

    public function setupSql()
    {
        $this->tables = ['company','account','transact','entry','period','balance'];

        $this->addCreateSql('company',
                            'CREATE TABLE `TABLE_NAME` (
                              `company_id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(255) NOT NULL,
                              `description` text NOT NULL,
                              `status` varchar(64) NOT NULL,
                              `date_start` date NOT NULL,
                              `date_end` date NOT NULL,
                              `vat_rate` decimal(8,2) NOT NULL,
                              `currency_id` varchar(4) NOT NULL,
                              `vat_apply` tinyint(4) NOT NULL,
                              `vat_account_id` int(11) NOT NULL,
                              `ret_account_id` int(11) NOT NULL,
                              `calc_timestamp` datetime NOT NULL,
                              PRIMARY KEY (`company_id`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

        $this->addCreateSql('account',
                            'CREATE TABLE `TABLE_NAME` (
                              `account_id` int(11) NOT NULL AUTO_INCREMENT,
                              `name` varchar(250) CHARACTER SET latin1 NOT NULL,
                              `keywords` text CHARACTER SET latin1 NOT NULL,
                              `description` varchar(250) NOT NULL,
                              `type_id` varchar(64) NOT NULL,
                              `abbreviation` varchar(64) NOT NULL,
                              `company_id` int(11) NOT NULL,
                              `status` varchar(64) NOT NULL,
                              PRIMARY KEY (`account_id`),
                              UNIQUE KEY `idx_account1` (`company_id`,`abbreviation`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

        $this->addCreateSql('transact',
                            'CREATE TABLE `TABLE_NAME` (
                              `transact_id` int(11) NOT NULL AUTO_INCREMENT,
                              `user_id` int(11) NOT NULL,
                              `date` datetime NOT NULL,
                              `amount` decimal(12,2) unsigned NOT NULL,
                              `description` varchar(255) NOT NULL,
                              `debit_accounts` text NOT NULL,
                              `credit_accounts` text NOT NULL,
                              `company_id` int(11) NOT NULL,
                              `status` varchar(64) NOT NULL,
                              `debit_credit` char(1) NOT NULL,
                              `account_id` int(11) NOT NULL,
                              `account_id_primary` int(11) NOT NULL,
                              `vat_inclusive` tinyint(4) NOT NULL,
                              `type_id` varchar(64) NOT NULL,
                              `currency_id` varchar(4) NOT NULL,
                              `date_create` date NOT NULL,
                              `date_process` datetime NOT NULL,
                              PRIMARY KEY (`transact_id`),
                              UNIQUE KEY `idx_transact1` (`account_id_primary`,`date`,`amount`,`description`),
                              KEY `fk_gl_transact_1` (`company_id`),
                              CONSTRAINT `fk_gl_transact_1` FOREIGN KEY (`company_id`) REFERENCES `TABLE_PREFIXcompany` (`company_id`) ON UPDATE NO ACTION
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

        $this->addCreateSql('entry',
                            'CREATE TABLE `TABLE_NAME` (
                              `entry_id` int(11) NOT NULL AUTO_INCREMENT,
                              `account_id` int(11) NOT NULL,
                              `debit_credit` char(1) NOT NULL,
                              `amount` decimal(12,2) unsigned NOT NULL,
                              `date` date NOT NULL,
                              `transact_id` int(11) NOT NULL,
                              PRIMARY KEY (`entry_id`),
                              KEY `idx_entry1` (`transact_id`),
                              KEY `idx_entry2` (`account_id`),
                              KEY `fk_gl_entry_1` (`account_id`),
                              KEY `fk_gl_entry_2` (`transact_id`),
                              CONSTRAINT `fk_gl_entry_1` FOREIGN KEY (`account_id`) REFERENCES `TABLE_PREFIXaccount` (`account_id`) ON UPDATE NO ACTION,
                              CONSTRAINT `fk_gl_entry_2` FOREIGN KEY (`transact_id`) REFERENCES `TABLE_PREFIXtransact` (`transact_id`) ON UPDATE NO ACTION
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

        $this->addCreateSql('period',
                            'CREATE TABLE `TABLE_NAME` (
                              `period_id` int(11) NOT NULL AUTO_INCREMENT,
                              `company_id` int(11) NOT NULL,
                              `date_start` date NOT NULL,
                              `date_end` date NOT NULL,
                              `status` varchar(64) NOT NULL,
                              `name` varchar(255) NOT NULL,
                              `period_id_previous` int(11) NOT NULL,
                              PRIMARY KEY (`period_id`)
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

        $this->addCreateSql('balance',
                            'CREATE TABLE `TABLE_NAME` (
                              `balance_id` int(11) NOT NULL AUTO_INCREMENT,
                              `period_id` int(11) NOT NULL,
                              `account_id` int(11) NOT NULL,
                              `account_balance` decimal(12,2) NOT NULL,
                              PRIMARY KEY (`balance_id`),
                              UNIQUE KEY `pk_balance_1` (`period_id`,`account_id`),
                              KEY `fk_gl_balance_1` (`account_id`),
                              KEY `fk_gl_balance_2` (`period_id`),
                              CONSTRAINT `fk_gl_balance_1` FOREIGN KEY (`account_id`) REFERENCES `TABLE_PREFIXaccount` (`account_id`) ON UPDATE NO ACTION,
                              CONSTRAINT `fk_gl_balance_2` FOREIGN KEY (`period_id`) REFERENCES `TABLE_PREFIXperiod` (`period_id`) ON UPDATE NO ACTION
                            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;'); 

           
        //initialisation
        $this->addInitialSql('INSERT INTO `TABLE_PREFIXcompany` (name,date_start,vat_rate,status) '.
                             'VALUES("My first company",CURDATE(),"15",OK")');
        

        //updates use time stamp in ['YYYY-MM-DD HH:MM'] format, must be unique and sequential
        //$this->addUpdateSql('YYYY-MM-DD HH:MM','Update TABLE_PREFIX--- SET --- "X"');
    }
}


  
?>
