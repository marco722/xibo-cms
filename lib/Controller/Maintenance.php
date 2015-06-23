<?php
/*
 * Spring Signage Ltd - http://www.springsignage.com
 * Copyright (C) 2015 Spring Signage Ltd
 * (Maintenance.php)
 */


namespace Xibo\Controller;


class Maintenance extends Base
{
    /**
     * Backup the Database
     * @param <string> $saveAs file|string
     */
    public function BackupDatabase($saveAs = "string")
    {
        // Check we can run mysql
        if (!function_exists('exec'))
            return $this->SetError(__('Exec is not available.'));

        // Global database variables to seed into exec
        global $dbhost;
        global $dbuser;
        global $dbpass;
        global $dbname;

        // get temporary file
        $fileNameStructure = Config::GetSetting('LIBRARY_LOCATION') . 'structure.dump';
        $fileNameData = Config::GetSetting('LIBRARY_LOCATION') . 'data.dump';
        $zipFile = 'database.tar.gz';

        // Run mysqldump structure to a temporary file
        $command = 'mysqldump --opt --host=' . $dbhost . ' --user=' . $dbuser . ' --password=' . addslashes($dbpass) . ' ' . $dbname . ' --no-data > ' . escapeshellarg($fileNameStructure) . ' ';
        exec($command);

        // Run mysqldump data to a temporary file
        $command = 'mysqldump --opt --host=' . $dbhost . ' --user=' . $dbuser . ' --password=' . addslashes($dbpass) . ' ' . $dbname . ' --ignore-table=' . $dbname . '.log --ignore-table=' . $dbname . '.oauth_log  > ' . escapeshellarg($fileNameData) . ' ';
        exec($command);

        // Check it worked
        if (!file_exists($fileNameStructure) || !file_exists($fileNameData))
            return $this->SetError(__('Database dump failed.'));

        // Zippy
        Log::debug($zipFile);
        $zip = new ZipArchive();
        $zip->open($zipFile, ZIPARCHIVE::OVERWRITE);
        $zip->addFile($fileNameStructure, 'structure.dump');
        $zip->addFile($fileNameData, 'data.dump');
        $zip->close();

        // Remove the dump file
        unlink($fileNameStructure);
        unlink($fileNameData);

        // Uncomment only if you are having permission issues
        // chmod($zipFile, 0777);

        // Push file back to browser
        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }

        $size = filesize($zipFile);

        header('Content-Type: application/octet-stream');
        header("Content-Transfer-Encoding: Binary");
        header("Content-disposition: attachment; filename=\"" . basename($zipFile) . "\"");

        //Output a header
        header('Pragma: public');
        header('Cache-Control: max-age=86400');
        header('Expires: '. gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
        header('Content-Length: ' . $size);

        // Send via Apache X-Sendfile header?
        if (Config::GetSetting('SENDFILE_MODE') == 'Apache') {
            header("X-Sendfile: $zipFile");
            exit();
        }

        // Return the file with PHP
        // Disable any buffering to prevent OOM errors.
        @ob_end_clean();
        @ob_end_flush();
        readfile($zipFile);

        exit;
    }

    /**
     * Restore Database
     * @param <string> $fileName
     */
    public function RestoreDatabase($fileName)
    {
        global $dbhost;
        global $dbuser;
        global $dbpass;
        global $dbname;

        // Push the file into msqldump
        exec('mysql --user=' . $dbuser . ' --password=' . $dbpass . ' ' . $dbname . ' < ' . escapeshellarg($fileName) . ' ');

        Log::notice('mysql --user=' . $dbuser . ' --password=' . $dbpass . ' ' . $dbname . ' < ' . escapeshellarg($fileName) . ' ' );

        return true;
    }

    public function TidyLibrary($tidyOldRevisions, $cleanUnusedFiles)
    {
        // Also run a script to tidy up orphaned media in the library
        $library = Config::GetSetting('LIBRARY_LOCATION');
        $library = rtrim($library, '/') . '/';
        $mediaObject = new Media();

        Log::debug('Library Location: ' . $library);

        // Dump the files in the temp folder
        foreach (scandir($library . 'temp') as $item) {
            if ($item == '.' || $item == '..')
                continue;

            Log::debug('Deleting temp file: ' . $item);

            unlink($library . 'temp' . DIRECTORY_SEPARATOR . $item);
        }

        $media = array();
        $unusedMedia = array();
        $unusedRevisions = array();

        // Run a query to get an array containing all of the media in the library
        try {
            $dbh = \Xibo\Storage\PDOConnect::init();

            $sth = $dbh->prepare('
                SELECT media.mediaid, media.storedAs, media.type, media.isedited,
                    SUM(CASE WHEN IFNULL(lklayoutmedia.lklayoutmediaid, 0) = 0 THEN 0 ELSE 1 END) AS UsedInLayoutCount,
                    SUM(CASE WHEN IFNULL(lkmediadisplaygroup.id, 0) = 0 THEN 0 ELSE 1 END) AS UsedInDisplayCount
                  FROM `media`
                    LEFT OUTER JOIN `lklayoutmedia`
                    ON lklayoutmedia.mediaid = media.mediaid
                    LEFT OUTER JOIN `lkmediadisplaygroup`
                    ON lkmediadisplaygroup.mediaid = media.mediaid
                GROUP BY media.mediaid, media.storedAs, media.type, media.isedited ');

            $sth->execute(array());

            foreach ($sth->fetchAll() as $row) {
                $media[$row['storedAs']] = $row;

                // Ignore any module files or fonts
                if ($row['type'] == 'module' || $row['type'] == 'font')
                    continue;

                // Collect media revisions that aren't used
                if ($tidyOldRevisions && $row['UsedInLayoutCount'] <= 0 && $row['UsedInDisplayCount'] <= 0 && $row['isedited'] > 0) {
                    $unusedRevisions[$row['storedAs']] = $row;
                }
                // Collect any files that aren't used
                else if ($cleanUnusedFiles && $row['UsedInLayoutCount'] <= 0 && $row['UsedInDisplayCount'] <= 0) {
                    $unusedMedia[$row['storedAs']] = $row;
                }
            }
        }
        catch (Exception $e) {

            Log::error($e->getMessage());

            if (!$this->IsError())
                $this->SetError(1, __('Unknown Error'));

            return false;
        }

        //Debug::Audit(var_export($media, true));
        //Debug::Audit(var_export($unusedMedia, true));

        // Get a list of all media files
        foreach(scandir($library) as $file) {

            if ($file == '.' || $file == '..')
                continue;

            if (is_dir($library . $file))
                continue;

            // Ignore thumbnails
            if (strstr($file, 'tn_') || strstr($file, 'bg_'))
                continue;

            // Is this file in the system anywhere?
            if (!array_key_exists($file, $media)) {
                // Totally missing
                Log::debug('Deleting file: ' . $file);

                // If not, delete it
                $mediaObject->DeleteMediaFile($file);
            }
            else if (array_key_exists($file, $unusedRevisions)) {
                // It exists but isn't being used any more
                Log::debug('Deleting unused revision media: ' . $media[$file]['mediaid']);
                $mediaObject->Delete($media[$file]['mediaid']);
            }
            else if (array_key_exists($file, $unusedMedia)) {
                // It exists but isn't being used any more
                Log::debug('Deleting unused media: ' . $media[$file]['mediaid']);
                $mediaObject->Delete($media[$file]['mediaid']);
            }
        }

        return true;
    }
}