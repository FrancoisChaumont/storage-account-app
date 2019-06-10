<?php

// constants for this class
define('DROPBOX_CREDENTIALS_FILE', 'CFXStorageAccountApp_Dropbox.json');
define('DROPBOX_CLIENT_SECRET_PATH', ALL_CLIENT_SECRET_PATH . 'Dropbox_client_secret.json');
// if modifying these scopes, delete your previously saved credentials 
// at ~/.credentials/CFXStorageAccountApp_Dropbox.json

// prefix for files id (part of the id value retrieved from provider)
define('DROPBOX_FILE_ID_PREFIX', 'id:');

/**
 * class to handle Dropbox operations
 */
final class Dropbox implements iProvider {
/* constants */
    const DROPBOX_FOLDER_TAG = 'folder';
    const PROVIDER_NAME = "Dropbox";

/* member variables */
    private $_appKey;
    private $_appSecret;
    private $_authUri;
    private $_tokenUri;
    private $_accessToken;
    private $_refreshToken;
    private $_created;
    private $_expiresIn;
    private $_parents;
    private $_initialized;

/* member functions */
    public function isInitialized(): bool { return $this->_initialized; }

    /**
     * Add parents to array
     *
     * @param string $parentId file id of the parent
     * @return boolean
     */
    public function addParents(string $parentId): bool {
        $metadata = $this->getFileMetaData($parentId);
        if (!(isset($metadata->path_display)) || $metadata->{'.tag'} != self::DROPBOX_FOLDER_TAG) {
            return false;
        }
        
        $this->_parents[] = substr($metadata->path_display, 1);
        return true;
    }
    
    /**
     * reset array containing parents' name
     *
     * @return void
     */
    public function resetParents() { $this->_parents = array(); }

/* constructor */
    function __construct() {
        $this->_parents = array();
        $this->_initialized = $this->init();
    }    

/* methods */
    /**
     * Creates credentials if needed and attempt to get authorization
     *
     * @return boolean
     */
    private function init(): bool {
        try {
            // load previously authorized credentials from a file.
            $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH.DROPBOX_CREDENTIALS_FILE);
            if (file_exists($credentialsPath)) {
                $accessTokenJson = file_get_contents($credentialsPath);
            } else {
                // request authorization from the user.
                $authUrl = $this->createAuthUrl();
                print EOL . "You need to authorize the app to connect to your " . self::PROVIDER_NAME . " first." . EOL;
                printf(OPEN_AUTH_LINK_TXT, $authUrl);
                print ENTER_VERIFICATION_CODE_TXT;
                $authCode = trim(fgets(STDIN));

                // exchange authorization code for an access token.
                $accessTokenJson = $this->fetchAccessTokenWithAuthCode($authCode);
                if (getJsonValueFromKey($accessTokenJson, JSON_ACCESS_TOKEN_ERROR_KEY) == '') {
                    // store the credentials to disk.
                    if(!file_exists(dirname($credentialsPath))) {
                        mkdir(dirname($credentialsPath), 0700, true);
                    }
                    file_put_contents($credentialsPath, $accessTokenJson);
                    printf(CREDENTIALS_SAVED_TXT . EOL, $credentialsPath);
                }
                else {
                    return false;
                }
            }
            if (getJsonValueFromKey($accessTokenJson, JSON_ACCESS_TOKEN_ERROR_KEY) == '') {
                $this->_accessToken = getJsonValueFromKey($accessTokenJson, JSON_ACCESS_TOKEN_KEY);
                return true;
            }
            else {
                return false;
            }
        }
        catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Build and return the auth URL with the required parameters
     *
     * @return string the URL
     */
    private function createAuthUrl(): string {
        // load client secret from json file
        $clientSecrets = json_decode(file_get_contents(DROPBOX_CLIENT_SECRET_PATH), true);
        
        $this->_appKey = $clientSecrets['app_key'];
        $this->_appSecret = $clientSecrets['app_secret'];
        $this->_authUri = $clientSecrets['auth_uri'];
        $this->_tokenUri = $clientSecrets['token_uri'];

        // build auth URL
        $authUrl = $this->_authUri;
        $authUrl .= "?response_type=code";
        $authUrl .= "&client_id=" . $this->_appKey;

        return $authUrl;
    }

    /**
     * Fetch the OAuth access token using the auth code
     *
     * @param string $authCode
     * @return string
     */
    private function fetchAccessTokenWithAuthCode(string $authCode): string {
        // set parameters
        $params = array( 
            "code" => $authCode, 
            "client_id" => $this->_appKey, 
            "client_secret" => $this->_appSecret,
            "grant_type" => "authorization_code");

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->_tokenUri);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    /**
     * List all files and folders (non-trashed) located on the Dropbox account.
     * Recall itself if the whole list can't be retrieved at once (flag has_more in the response)
     *
     * @param string $cursor 
     * @return boolean
     */
    public function displayFiles(string $cursor = null): bool {
        if ($cursor) {
            $endPoint = "https://api.dropboxapi.com/2/files/list_folder/continue";

            $params = json_encode(array(
                'cursor' => $cursor
            ));
        }
        else {
            $endPoint = "https://api.dropboxapi.com/2/files/list_folder";

            $params = json_encode(array(
                'path' => '',
                'recursive' => true,
                'include_deleted' => false,
                'include_mounted_folders' => true
            ));
        }

        $headers = array(
            "Content-Type: application/json"
        );

        $fileList = $this->postRequest($endPoint, $headers, $params, true);

        if (!$fileList) {
            if (!$cursor) { print NO_FILES_FOUND."\n"; }
            return false;
        }
        else {
            if (!$cursor) { printf(FILE_LIST_HEADER, self::PROVIDER_NAME); }

            foreach ($fileList->entries as $file) {
                printf("%s | %s | %s\n", $file->name, $this->stripIdPrefix($file->id), $file->{'.tag'});
            }
            if ($fileList->has_more) {
                $this->displayFiles($fileList->cursor);
            }
            return true;
        }
    }

    /**
     * Strip a given id of its prefix (see constant DROPBOX_FILE_ID_PREFIX)
     *
     * @param string $prefixedId
     * @return string
     */
    private function stripIdPrefix(string $prefixedId): string {
        $idPrefixLen = strlen(DROPBOX_FILE_ID_PREFIX);
        return substr($prefixedId, $idPrefixLen);
    }

    /**
     * Create a folder and return its name. Folder is uploaded into a specific folder if specified
     *
     * @param string $folderName
     * @return string
     */
    private function createFolder(string $folderName): string {
        if (sizeof($this->_parents)) { $preSlash = '/'; }
        else { $preSlash = ''; }

        $parent = $preSlash . implode('/', $this->_parents) . '/' . $folderName;

        $endPoint = "https://api.dropboxapi.com/2/files/create_folder_v2";
        $params = json_encode(array(
            'path' => $parent,
            'autorename' => true
        ));
        $headers = array(
            "Content-Type: application/json"
        );

        $response = $this->postRequest($endPoint, $headers, $params, true);
        $folderName = $response->metadata->name;

        return $folderName;
    }

    /**
     * Upload a file and return its id. File is uploaded into a specific folder if specified
     *
     * @param string $fileName file name
     * @param string $fileLocalPath file local path
     * @return string
     */
    private function uploadFile(string $fileName, string $fileLocalPath): string {
        if ($fileLocalPath == '') { 
            $fileLocalPath = TEMP_FOLDER_PATH . '/'; 
            $slash = '';
        }
        else { 
            $fileLocalPath .= '/';
            $slash = '/';
        }
        $filePathAndName = $fileLocalPath . $fileName;
        $content = file_get_contents($filePathAndName);
        $mimetype = mime_content_type($filePathAndName);
        $path = $slash . implode('/', $this->_parents) . '/' . $fileName;

        $endPoint = "https://content.dropboxapi.com/2/files/upload";
        $params = json_encode(array(
            'path' => $path,
            'mode' => 'add',
            'autorename' => true,
            'mute' => true
        ));
        $headers = array(
            "Content-Type: application/octet-stream",
            "Dropbox-API-Arg: $params"
        );
        
        $response = $this->postRequest($endPoint, $headers, $content, true);
        return $response->name;
    }

    /**
     * Upload a file or a folder and its content from the local temp folder to Dropbox
     *
     * @param string $elementName file or folder name
     * @param string $elementLocalPath file or folder path
     * @return boolean
     */
    public function upload(string $elementName, string $elementLocalPath = ''): bool {
        if ($elementLocalPath == '') { $prefix = TEMP_FOLDER_PATH . '/'; }
        else { $prefix = $elementLocalPath . '/'; }
        $elmtPathAndName = $prefix . $elementName;

        try {
            if (mime_content_type($elmtPathAndName) == LOCAL_FOLDER_MIMETYPE) {
                $folderName = $this->createFolder($elementName);

                $this->_parents[] = $folderName;

                // upload files and folders for the next folder found
                foreach (glob($elmtPathAndName . '/*') as $elmtName) {
                    $this->upload(basename($elmtName), dirname($elmtName));
                }

                // remove parent id from array to jump back to parent directory
                array_pop($this->_parents);
            }
            else {
                // upload a single file
                $this->uploadFile($elementName, $elementLocalPath);
            }
        }
        catch (Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * List files and folders on Dropbox at a specific location
     *
     * @param string $folderId folder id
     * @param string $cursor cursor to continue listing if needed
     * @return void
     */
    public function listFiles(string $folderId, string $cursor = null) {
        if ($cursor) {
            $endPoint = "https://api.dropboxapi.com/2/files/list_folder/continue";
            $params = json_encode(array(
                'cursor' => $cursor
            ));
        }
        else {
            $endPoint = "https://api.dropboxapi.com/2/files/list_folder";
            $params = json_encode(array(
                'path' => DROPBOX_FILE_ID_PREFIX . $folderId,
                'recursive' => false,
                'include_deleted' => false,
                'include_mounted_folders' => true
            ));
        }

        $headers = array(
            "Content-Type: application/json"
        );

        $fileList = $this->postRequest($endPoint, $headers, $params, true);

        return $fileList;
    }

    /**
     * Download a file or a folder and its content to the local temp folder from Dropbox
     *
     * @param string $elementId file or folder id
     * @param string $elementLocalPath file or folder path
     * @return string
     */
    public function download(string $elementId, string $elementLocalPath = ''): string {
        // empty temp folder
        if ($elementLocalPath == '') { emptyDirectory(TEMP_FOLDER_PATH); }

        try {
            // retrieve file meta data
            $fileMetadata = $this->getFileMetaData($elementId);
            $elmtName = $fileMetadata->name;
            $elmtPathAndName = $elementLocalPath . '/' . $elmtName;

            if ($fileMetadata->{'.tag'} == self::DROPBOX_FOLDER_TAG) {
                // create folder
                mkdir(TEMP_FOLDER_PATH . '/' . $elmtPathAndName);
                $elementLocalPath = $elmtPathAndName;

                // navigate through all files and folders underneath this remote folder
                $cursor = null;
                do {
                    $list = $this->listFiles($elementId, $cursor);
                    foreach ($list->entries as $file) {
                        $fileId = $this->stripIdPrefix($file->id);
                        $this->download($fileId, $elementLocalPath);
                    }
                    $cursor = $list->cursor;
                } while ($cursor = $list->has_more);

                // navigate up to the parent folder
                $elementLocalPath = dirname($elementLocalPath);
            }
            else {
                $endPoint = "https://content.dropboxapi.com/2/files/download";
                $params = json_encode(array(
                    'path' => DROPBOX_FILE_ID_PREFIX . $elementId
                ));
                $headers = array(
                    "Content-Type: ",
                    "Dropbox-API-Arg: $params"
                );
                
                // retrieve file content
                $content = $this->postRequest($endPoint, $headers, '', false);
                
                $newFile = fopen(TEMP_FOLDER_PATH . '/' . $elmtPathAndName, "w");
                fwrite($newFile, $content);
                fclose($newFile);
            }

            return $elmtName;
        }
        catch (Exception $e) {
            return '';
        }
    }

    /**
     * Return a json object containing a file or folder metadata
     *
     * @param string $elementId file or folder id
     * @return stdClass
     */
    private function getFileMetaData(string $elementId) {
        $endPoint = "https://api.dropboxapi.com/2/files/get_metadata";
        $params = json_encode(array(
            'path' => DROPBOX_FILE_ID_PREFIX . $elementId,
            'include_deleted' => false
        ));
        $headers = array(
            "Content-Type: application/json"
        );

        $response = $this->postRequest($endPoint, $headers, $params, true);

        return $response;
    }

    /**
     * Perform an http POST request
     * Returns either an stdClass object (json object) or a raw response
     *
     * @param string $endPoint user endpoint (url)
     * @param array $headers http headers
     * @param string $data data/content
     * @param boolean $json to return a json object
     * @return mixed
     */
    private function postRequest(string $endPoint, array $headers, string $data = '', bool $json = true) {
        $ch = curl_init($endPoint);
        array_push($headers, "Authorization: Bearer " . $this->_accessToken);
        curl_setopt($ch, CURLOPT_POST, TRUE);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        
        $response = curl_exec($ch);
        curl_close($ch);

        if ($json) { return json_decode($response, false); }
        else { return $response; }
    }
}

?>