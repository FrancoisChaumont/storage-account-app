<?php

// constants for this class
define('GOOGLE_APPLICATION_NAME', 'CFX Storage Account App');
define('GOOGLE_CREDENTIALS_FILE', 'CFXStorageAccountApp_Google.json');
define('GOOGLE_CLIENT_SECRET_PATH', ALL_CLIENT_SECRET_PATH . 'Google_client_secret.json');
// if modifying these scopes, delete your previously saved credentials 
// at ~/.credentials/CFXStorageAccountApp_Google.json

// scope for OAuth 2.0
define('SCOPES', implode(' ', array(
    Google_Service_Drive::DRIVE)
));

/**
 * Class to handle Google Drive operations
 */
final class GoogleDrive implements iProvider {
/* constants */
    // Google mimetype for folders 
    const GOOGLE_FOLDER_MIMETYPE = 'application/vnd.google-apps.folder';
    const GOOGLE_DOCS_MIMETYPE = 'application/vnd.google-apps';
    const PROVIDER_NAME = "Google Drive";
    
/* member variables */
    private $_client; // Google_Client object
    private $_service; // Google_Service_Drive object
    private $_parents; // array containing target file or folder's parent folders
    private $_initialized; // flag to raise if init don't go all the way

/* member functions */
    public function isInitialized(): bool { return $this->_initialized; }

    /**
     * Add parents to array
     *
     * @param string $parentId file id of the parent
     * @return void
     */
    public function addParents(string $parentId): bool { 
        try {
            // retrieve file metadata (name and mimetype)
            $respMeta = $this->_service->files->get($parentId);
            $fileMimetype = $respMeta->mimeType;
            
            if ($fileMimetype != self::GOOGLE_FOLDER_MIMETYPE) {
                return false;
            }
        }
        catch (Exception $e) {
            return false;
        }

        $this->_parents[] = $parentId; 
        return true;
    }
    
    /**
     * reset array containing parents' ids
     *
     * @return void
     */
    public function resetParents() { $this->_parents = array(); }

/* constructor */
    /**
     * Instantiate a new instance and create an API client
     *
     * @return void
     */
    function __construct() {
        $this->_client = $this->init();
        if ($this->_client != null) {
            $this->_service = new Google_Service_Drive($this->_client);
            $this->_parents = array();
            $this->_initialized = true;
        }
        else {
            $this->_initialized = false;
        }
    }

/* methods */
    /**
     * Return an authorized API client, creates/refreshed credentials if needed and attempt to get authorization
     * @return Google_Client the authorized client object
     */
    private function init() {
        try {
            $client = new Google_Client();
            $client->setApplicationName(GOOGLE_APPLICATION_NAME);
            $client->setScopes(SCOPES);
            $client->setAuthConfig(GOOGLE_CLIENT_SECRET_PATH);
            $client->setAccessType('offline');

            // load previously authorized credentials from a file.
            $credentialsPath = expandHomeDirectory(CREDENTIALS_PATH.GOOGLE_CREDENTIALS_FILE);
            if (file_exists($credentialsPath)) {
                $accessToken = json_decode(file_get_contents($credentialsPath), true);
            } else {
                // request authorization from the user.
                $authUrl = $client->createAuthUrl();
                print EOL . "You need to authorize the app to connect to your " . self::PROVIDER_NAME . " first." . EOL;
                printf(EOL . OPEN_AUTH_LINK_TXT, $authUrl);
                print ENTER_VERIFICATION_CODE_TXT;
                $authCode = trim(fgets(STDIN));

                // exchange authorization code for an access token.
                $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);

                if (!(isset($accessToken[JSON_ACCESS_TOKEN_ERROR_KEY]))) {
                    // store the credentials to disk.
                    if(!file_exists(dirname($credentialsPath))) {
                        mkdir(dirname($credentialsPath), 0700, true);
                    }
                    file_put_contents($credentialsPath, json_encode($accessToken));
                    printf(CREDENTIALS_SAVED_TXT . EOL, $credentialsPath);
                }
            }
            if (!(isset($accessToken[JSON_ACCESS_TOKEN_ERROR_KEY]))) {
                $client->setAccessToken($accessToken);

                // refresh the token if it's expired.
                if ($client->isAccessTokenExpired()) {
                    $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
                    file_put_contents($credentialsPath, json_encode($client->getAccessToken()));
                }
                return $client;
            }
            else {
                print "Access token not initialized for " . self::PROVIDER_NAME . EOL;
                return null;
            }
        }
        catch (Exception $e) {
            return null;
        }
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
        }
        else { 
            $fileLocalPath .= '/';
        }
        $filePathAndName = $fileLocalPath . $fileName;
        $content = file_get_contents($filePathAndName);
        $mimetype = mime_content_type($filePathAndName);
        $parent = array();

        // only the first parent folder is needed in order to create a file inside it
        $lastElmt = end($this->_parents);
        if ($lastElmt) { $parent[] = $lastElmt; }

        // define file metadata (name and containing folder if any)
        $fileMetadata = new Google_Service_Drive_DriveFile(array(
            'name' => $fileName,
            'parents' => $parent 
        ));

        // create file and retrieve its id
        $file = $this->_service->files->create($fileMetadata, 
            array(
                'data' => $content,
                'mimeType' => $mimetype,
                'uploadType' => 'multipart',
                'fields' => 'id'
            ));

        return $file->id;
    }

    /**
     * Create a folder and return its id. Folder is uploaded into a specific folder if specified
     *
     * @param string $folderName folder name
     * @return string
     */
    private function createFolder(string $folderName): string {
        $parent = array();
        
        // only the first parent folder is needed in order to create a file inside it
        $lastElmt = end($this->_parents);
        if ($lastElmt) { $parent[] = $lastElmt; }
        
        // define folder metadata (name, containing folder if any and mimetype of type folder)
        $fileMetadata = new Google_Service_Drive_DriveFile(array(
            'name' => $folderName,
            'parents' => $parent,
            'mimeType' => self::GOOGLE_FOLDER_MIMETYPE));

        // create folder and retrieve its id
        $file = $this->_service->files->create($fileMetadata, array(
            'fields' => 'id'));

        return $file->id;
    }

    /**
     * Upload a file or a folder and its content from the local temp folder to Google Drive
     *
     * @param string $elementName name of the file to upload or folder to create
     * @param string $elementLocalPath local path of file or folder
     * @return boolean
     */
    public function upload(string $elementName, string $elementLocalPath = ''): bool {
        if ($elementLocalPath == '') { $prefix = TEMP_FOLDER_PATH . '/'; }
        else { $prefix = $elementLocalPath . '/'; }
        $elmtPathAndName = $prefix . $elementName;
        
        try {
            if (mime_content_type($elmtPathAndName) == LOCAL_FOLDER_MIMETYPE) {
                $elmtId = $this->createFolder($elementName);
                
                // add parent id to array to know which folder we are currently in
                $this->_parents[] = $elmtId;
                
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
     * Download a file or a folder and its content to the local temp folder from Google Drive
     *
     * @param string $elementId id of the file or folder to download
     * @param string $elementLocalPath local path of file or folder
     * @return string
     */
    public function download(string $elementId, string $elementLocalPath = ''): string {
        // empty temp folder
        if ($elementLocalPath == '') { emptyDirectory(TEMP_FOLDER_PATH); }

        try {
            // retrieve file metadata (name and mimetype)
            $respMeta = $this->_service->files->get($elementId);
            $elmtName = $respMeta->name;
            $fileMimetype = $respMeta->mimeType;
            if ($elementLocalPath != '') { $elmtPathAndName = $elementLocalPath . '/' . $elmtName; }
            else { $elmtPathAndName = $elmtName; }
            
            if ($fileMimetype == self::GOOGLE_FOLDER_MIMETYPE) {
                // create new folder
                mkdir(TEMP_FOLDER_PATH . '/' . $elmtPathAndName);
                $elementLocalPath = $elmtPathAndName;

                // navigate through all files and folders underneath this remote folder
                $optParams = array(
                    'q' => "trashed = false and '$elementId' in parents",
                    'fields' => 'files(id, name)'
                );
                $results = $this->_service->files->listFiles($optParams);
                foreach ($results->getFiles() as $file) {
                    $this->download($file->getId(), $elementLocalPath);
                }

                // navigate up to the parent folder
                $elementLocalPath = dirname($elementLocalPath);
            }
            else {
                // retrieve file content and convert it if needed (Google Docs)
                if (startsWith($fileMimetype,self::GOOGLE_DOCS_MIMETYPE)) {
                    $respContent = $this->_service->files->export($elementId, PDF_MIMETYPE, array(
                        'alt' => 'media'));
                }
                else {
                    $respContent = $this->_service->files->get($elementId, array(
                        'alt' => 'media'));
                }
                $content = $respContent->getBody()->getContents();
                
                // create a new file and write the content in it
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
     * List all files and folders (non-trashed) located on the Google Drive account
     *
     * @return boolean
     */
    public function displayFiles(): bool {
        // list id and name of non-trashed files and folders
        $optParams = array(
            'q' => "trashed = false",
            'fields' => "files(id, name, mimeType)"
        );
        $results = $this->_service->files->listFiles($optParams);

        if (count($results->getFiles()) == 0) {
            print NO_FILES_FOUND."\n";
            return false;
        }
        else {
            printf(FILE_LIST_HEADER, self::PROVIDER_NAME);
            foreach ($results->getFiles() as $file) {
                printf("%s | %s | %s\n", $file->getName(), $file->getId(), $file->getMimeType());
            }
            return true;
        }
    }
}

?>