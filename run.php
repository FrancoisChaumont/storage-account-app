<?php

require_once __DIR__ . "/vendor/autoload.php";
require_once __DIR__ . "/libs/_allLibs.php";

// prevent DateTime tz exception
date_default_timezone_set('America/Chicago');

// force the application to be run from the commande line
if (php_sapi_name() != 'cli') {
    throw new Exception('This application must be run on the command line.');
}

print "###############################################" . EOL;
print "Welcome to the CFX Storage Account App v" . APP_VERSION . "!" . EOL;
print "If you wish to exit, hit ENTER during any input" . EOL;
print "Sit tight and enjoy the ride..." . EOL;
print "###############################################" . EOL . EOL;

// choose the source provider
print "Choose the source provider (the one to copy from)" . EOL;
print "G = Google Drive" . EOL;
print "D = Dropbox" . EOL;
print "Enter the corresponding letter: ";
$provider = trim(fgets(STDIN));

// exit on ENTER
if (strlen($provider) == 0) { print "Bye."; exit; }

switch ($provider) {
    case 'G':
    case 'g': 
        $srcProvider = "Google Drive";
        print EOL . "You chose $srcProvider as source." . EOL;
        $src = new GoogleDrive();
        providerIsInit($src, $srcProvider);
        $destProvider = "Dropbox";
        $dest = new Dropbox();
        providerIsInit($dest, $destProvider);
        break;
    case 'D':
    case 'd': 
        $srcProvider = "Dropbox";
        print EOL . "You chose $srcProvider as source." . EOL;
        $src = new Dropbox(); 
        providerIsInit($src, $srcProvider);
        $destProvider = "Google Drive";
        $dest = new GoogleDrive();
        providerIsInit($dest, $destProvider);
        break;
    default: 
        print EOL . "Invalid choice. " . EOL . "Bye!" . EOL;
        exit;
}

// select file or folder to copy from source to destination
print "Now retrieving $srcProvider file list..." . EOL . EOL;
$src->displayFiles();
print EOL;
print "Select the file or a folder you wish to copy to $srcProvider from the list above" . EOL;
print "Copy/paste its id here: ";
$srcId = trim(fgets(STDIN));

// exit on ENTER
if (strlen($srcId) == 0) { print "Bye."; exit; }

// select folder to copy to destination
print EOL . "Now retrieving $destProvider file list..." . EOL . EOL;
$dest->displayFiles();
print EOL;
print "Select the folder on $destProvider you wish to copy into from the list above (0 for root)" . EOL;
print "Copy/paste its id here: ";
$destId = trim(fgets(STDIN));

// exit on ENTER
if (strlen($destId) == 0) { print "Bye."; exit; }

if ($destId != '0') { 
    $realParent = $dest->addParents($destId); 
    if (!$realParent) { 
        print "Destination folder invalid! Make sure you entered the correct id." . EOL;
        print "Exiting app...";
        exit;
    }
}

// dowloading from source
print EOL . "Downloading from $srcProvider..." . EOL;
$elmt = $src->download($srcId);
if ($elmt == '') { 
    print "Download error: make sure you entered a correct file id. Exiting app...";
    exit; 
}

// uploading to destination
print "Uploading to $destProvider..." . EOL;
$dest->upload($elmt);

print EOL . "Done." . EOL;




function providerIsInit($provider, $providerName) {
    if (!($provider->isInitialized())) { 
        print "$providerName could not be initialized properly. Exiting app...";
        exit;
    } 
}
?>