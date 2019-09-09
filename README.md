# General Ledger module. 

## Designed for small business applications.

You can create multiple companies. Each companies has its own chart of accounts and accounting periods. 
Generate income statements and balance sheets. Import credit card and current account CSV dumps from your bank and 
allocate transactions to your accounts.

## Requires Seriti Slim 3 MySQL Framework skeleton

This module integrates seamlessly into [Seriti skeleton framework](https://github.com/seriti/slim3-skeleton).
You need to first install the skeleton framework and then download the source files for the module and follow these instructions.

It is possible to use this module independantly from the seriti skeleton but you will still need the [Seriti tools library](https://github.com/seriti/tools).
It is strongly recommended that you first install the seriti skeleton to see a working example of code use before using it within another application framework.
That said, if you are an experienced PHP programmer you will have no problem doing this and the required code footprint is very small.  

## Install the module

1.) Install Seriti Skeleton framework(see the framework readme for detailed instructions) : 
    "composer create-project seriti/slim3-skeleton [directory-for-app]". 
    Make sure that you have thsi working before you proceed.

2.) Download a copy of General ledger module source code directly from github and unzip,
or by using "git clone https://github.com/seriti/general-ledger" from command line.
Once you have a local copy of module code check that it has following structure:

/Ledger/(all module implementation classes are in this folder)
/setup_add.php
/routes.php
/templates/(all templates required in this folder)

3.) Copy the "Ledger" folder and all its contents into "[directory-for-app]/app" folder.

4.) Open the routes.php file and insert the "$this->group('/ledger', function (){}" route definition block
within the existing  "$app->group('/admin', function () {}" code block contained in existing skeleton "[directory-for-app]/src/routes.php" file.

5.) Open the setup_app.php file and  add the module config code snippet into bottom of skeleton "[directory-for-app]/src/setup_app.php" file.
Please check the "table_prefix" value to ensure that there will not be a clash with any existing tables in your database.

6.) Copy the contents of "templates" folder to "[directory-for-app]/templates/" folder

7.) Now in your browser goto URL:
Now goto URL:
"http://localhost:8000/admin/ledger/dashboard" if you are using php built in server
OR 
"http://www.yourdomain.com/admin/ledger/dashboard" if you have configured a domain on your server

Now click link at bottom of page "Setup Database": This will create all necessary database tables with table_prefix as defined above.
Thats it, you are good to go. Create a company, setup your chart of accounts, and first accounting period. 
capture some transactions and view income statement balance sheet. You can also import bank CSV dumps of credit card transactions but will need to modify or add code for the format of your bank's csv data.
