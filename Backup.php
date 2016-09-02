<?php

/*
$serverRoot = "/var/www/html/";
$backupDir = $serverRoot . "backup/";

$backup = new backup($serverRoot, $backupDir);
$backup->setMail("[YOUR EMAIL]");
$backup->setCreateLog(true);
$backup->setDeleteBackupsAfter(20);

$backup->execute();
*/

class backup
{
    private $_serverRoot; //$_SERVER['DOCUMENT_ROOT'] cant be used with cron
    private $_backupDir;
    private $_recDirIt;
    private $_recItIt;

    //zip values
    private $_zipNumFiles = null;
    private $_zipStatus = null;

    //default values
    private $_mail = null;
    private $_createLog = true;
    private $_deleteBackupsAfter = 30; //delete old backups after X Days || -1 deactivate

    /**
     * Backup constructor.
     * @param $serverRoot
     * @param $backupDir
     */
    public function __construct($serverRoot, $backupDir)
    {
        $this->_serverRoot = $serverRoot;
        $this->_backupDir = $backupDir;
        $this->_recDirIt = new RecursiveDirectoryIterator($this->_serverRoot, RecursiveDirectoryIterator::SKIP_DOTS);
        $this->_recItIt = new RecursiveIteratorIterator($this->_recDirIt);
    }

    /**
     * Starts the backup
     * @return bool true if backup was successful,
     * false otherwise
     */
    public function execute()
    {
        $success = false;

        //10 minutes
        ini_set("max_execution_time", 600);

        if ($this->_createLog) {
            $this->createNewLogEntry("notice", "Backup started");
        }

        if ($this->_deleteBackupsAfter != -1) {

            $deletedFiles = $this->deleteOldBackup();

            if (!empty($deletedFiles) && $this->_createLog) {
                $this->createNewLogEntry("notice", "Old backup deleted: " . implode(", ", $deletedFiles));
            } elseif ($this->_createLog) {
                $this->createNewLogEntry("notice", "No old backup deleted");
            }
        }

        $zipPath = $this->backup($this->_recItIt);

        if (is_null($zipPath)) {
            $success = false;

            if ($this->_createLog) {
                $this->createNewLogEntry("error", "Zip Archive cannot be created");
            }
        }

        if (!is_null($this->_mail)) {
            $success = $this->sendMail($zipPath);

            if (!$success && $this->_createLog) {
                $this->createNewLogEntry("error", "E-Mail cannot be sent");
            }
        }

        if ($this->_createLog) {
            $this->createNewLogEntry("notice", "Backup finished successfully");
        }

        return $success;
    }

    /**
     * Creates zip archive of the given path
     * @param RecursiveIteratorIterator $recItIt path of root dir
     * @return string path of the created backup,
     * null if backup failed
     */
    private function backup($recItIt)
    {
        $zip = new ZipArchive();
        $zipPath = $this->_backupDir . "backup_" . date("Y-m-d_H-i-s") . ".zip";
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            exit("open zip file failed");
        }

        foreach ($recItIt as $singleItem) {

            //exclude backup folder
            if (is_int(strpos($singleItem, $this->_backupDir))) {
                continue;
            }

            if (is_dir($singleItem)) {

                $zip->addEmptyDir($singleItem);

                $this->backup($singleItem);

            } elseif (is_file($singleItem)) {

                $zip->addFile($singleItem);

            }
        }

        if ($zip->close()) {

            $this->_zipNumFiles = $zip->numFiles;
            $this->_zipStatus = $zip->status;
            return $zipPath;
        }

        return null;
    }

    /**
     * Sends a mail with attachment (backup zip)
     * @param $attachmentPath
     * @return bool true if mail was sent successfully,
     * false otherwise
     */
    private function sendMail($attachmentPath)
    {
        $subject = "Backup || " . basename($attachmentPath);

        $content = chunk_split(base64_encode(file_get_contents($attachmentPath)));
        $part = md5(time());

        $header = "From: " . $this->_mail . "\r\n";
        $header .= "MIME-Version: 1.0\r\n";
        $header .= "Content-Type: multipart/mixed; boundary=\"" . $part . "\"\r\n\r\n";

        $message = "--" . $part . "\r\n";
        $message .= "Content-type:text/plain; charset=iso-8859-1\r\n";
        $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";

        $message .= "--" . $part . "\r\n";
        $message .= "Content-Type: application/octet-stream; name=\"" . basename($attachmentPath) . "\"\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "Content-Disposition: attachment; filename=\"" . basename($attachmentPath) . "\"\r\n\r\n";
        $message .= $content . "\r\n\r\n";
        $message .= "--" . $part . "--";

        return mail($this->_mail, $subject, $message, $header);
    }

    /**
     * Creates new log entry
     * $type = {NOTICE, ERROR}
     * @param $type
     * @param $message
     */
    private function createNewLogEntry($type, $message)
    {
        //folder structure
        //2017
        //--backup-january.log
        //--backup-february.log
        //  ...

        $currentYear = date("Y");
        $currentMonth = date("F");


        //log folder
        $path = $this->_backupDir . "log/";
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        //year folder
        $path = $path . $currentYear . "/";
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        //log file
        $path = $path . "backup-" . strtolower($currentMonth) . ".log";

        $logType = "UNDEFINED";

        switch ($type) {
            case "notice":
                $logType = "NOTICE";
                break;
            case "error":
                $logType = "ERROR";
                $message .= "...Script aborted";
                break;
        }

        $entry = sprintf("%s %s %s %s" . PHP_EOL, date("Y-m-d H:i:s"), "-- PHPBackupScript -", $logType, $message);
        file_put_contents($path, $entry, FILE_APPEND);

        if ($type == "error") {
            exit;
        }

    }

    /**
     * Deletes zip archives older than $this->_deleteBackupsAfter days
     * log folder is excluded
     * @return array of the deleted filename
     */
    private function deleteOldBackup()
    {
        $deletedFiles = array();

        $recDirIt = new RecursiveDirectoryIterator($this->_backupDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $recItIt = new RecursiveIteratorIterator($recDirIt);

        foreach ($recItIt as $file) {

            //exclude log folder
            if (is_int(strpos($file, $this->_backupDir . "log/"))) {
                continue;
            }

            if (is_file($file)) {
                $dateFileCreated = date_create();
                $dateToday = date_create();
                date_timestamp_set($dateFileCreated, filectime($file));

                if (pathinfo($file)["extension"] == "zip" &&
                    date_diff($dateFileCreated, $dateToday)->d >= $this->_deleteBackupsAfter
                ) {
                    if (unlink($file)) {
                        array_push($deletedFiles, basename($file));
                    }
                }
            }
        }

        return $deletedFiles;
    }


    //getter and setter

    /**
     * @return string of email,
     * null if unset
     */
    public function getMail()
    {
        return $this->_mail;
    }

    /**
     * Backup zip archive will be sent to this email
     * @param string $email ,
     * null if unset
     */
    public function setMail($email)
    {
        $this->_mail = $email;
    }

    /**
     * @return boolean
     */
    public function getCreateLog()
    {
        return $this->_createLog;
    }

    /**
     * @param boolean $createLog
     */
    public function setCreateLog($createLog)
    {
        $this->_createLog = $createLog;
    }

    /**
     * @return int in days
     */
    public function getDeleteBackupsAfter()
    {
        return $this->_deleteBackupsAfter;
    }

    /**
     * @param int $deleteBackupsAfter in days
     */
    public function setDeleteBackupsAfter($deleteBackupsAfter)
    {
        $this->_deleteBackupsAfter = $deleteBackupsAfter;
    }

}