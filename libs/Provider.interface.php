<?php 

/**
 * Interface for common functionalities between all providers (Google Drive, DropBox, OneDrive...)
 */
interface iProvider {
    public function upload(string $elementName, string $elementLocalPath = ''): bool;
    public function download(string $elementId, string $elementLocalPath = ''): string;
}

?>