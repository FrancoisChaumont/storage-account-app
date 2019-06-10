<?php 

define('APP_VERSION', '0.1');
define('EOL', PHP_EOL);

// json keys
define('JSON_ACCESS_TOKEN_KEY', 'access_token');
define('JSON_ACCESS_TOKEN_ERROR_KEY', 'error');

// paths
define('ALL_CLIENT_SECRET_PATH', __DIR__ . '/../client-secrets/');
define('CREDENTIALS_PATH', '~/.credentials/');
define('TEMP_FOLDER_PATH', __DIR__ . '/../temp');

// mimetypes
define('LOCAL_FOLDER_MIMETYPE', 'directory');
define('PDF_MIMETYPE', 'application/pdf');

// texts
define('NO_FILES_FOUND', 'No files found.');
define('FILE_LIST_HEADER', "Files on %s: (name | id | file/folder)\n");
define('OPEN_AUTH_LINK_TXT', "Open the following link in your browser:\n%s\n");
define('ENTER_VERIFICATION_CODE_TXT', "Enter verification code: ");
define('CREDENTIALS_SAVED_TXT', " > Credentials saved to %s\n");


require_once __DIR__ . "/functions.php";
require_once __DIR__ . "/Provider.interface.php";
require_once __DIR__ . "/GoogleDrive.class.php";
require_once __DIR__ . "/Dropbox.class.php";

?>