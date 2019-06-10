<?php

/**
 * Expands the home directory alias '~' to the full path
 * @param string $path the path to expand
 * @return string the expanded path
 */
function expandHomeDirectory(string $path): string {
    $homeDirectory = getenv('HOME');
    if (empty($homeDirectory)) {
    $homeDirectory = getenv('HOMEDRIVE') . getenv('HOMEPATH');
    }
    return str_replace('~', realpath($homeDirectory), $path);
}

/**
 * Verify if a string starts with a given shorter string
 *
 * @param string $haystack string to search in
 * @param string $needle string to search for
 * @return bool true if found else false
 */
function startsWith(string $haystack, string $needle): bool {
    $length = strlen($needle);
    return (substr($haystack, 0, $length) === $needle);
}

/**
 * retrieve a specific value for a given key inside a json string
 *
 * @param string $json json string
 * @param string $key json key to look for
 * @return string value for the key
 */
function getJsonValueFromKey(string $json, string $key): string {
    $jsonArray = json_decode($json, true);
    if (isset($jsonArray[$key])) { return $jsonArray[$key]; }
    else { return ''; }
}

/**
 * delete a directory and its content
 *
 * @param string $path path to directory
 * @return void
 */
function emptyDirectory(string $path) {
	$files = glob($path . '/*');
	foreach ($files as $file) {
        if (is_dir($file)) {
            emptyDirectory($file);
            rmdir($file);
        }
        else {
            unlink($file);
        }
	}
}

?>