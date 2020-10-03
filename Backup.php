<?php

/*
$backupPath = "/var/www/html/backup/";

$backup = new Backup($backupPath);
$backup->setMail("[YOUR EMAIL]");
$backup->setDeleteBackupsAfter(20);

$dirPath = "/var/www/html/";
$backup->backupDirectory($dirPath);

$databaseHost = "localhost";
$databaseUser = "user";
$databasePassword = "password";
$databaseName = "databaseName";
$backup->backupDatabase($databaseHost, $databaseUser, $databasePassword, $databaseName);
*/

class Backup
{
    private $_backupDir;

    //default values
    private $_mail = null;
    private $_deleteBackupsAfter = 30; //delete old backups after X Days || -1 deactivate
    private $_phpTimeoutTime = 600; //default 10 minutes || max_execution_time

    //logging types
    const LOG_INFO = 0;
    const LOG_DELETE = 1;
    const LOG_CREATE = 2;
    const LOG_ERROR = 3;

    /**
     * Backup constructor.
     * @param string $backupPath
     */
    public function __construct(string $backupPath)
    {
        $this->_backupDir = $this->validateUserInputFilePath($backupPath);
    }

    /**
     * Starts the file backup
     * @param string $directoryPath
     */
    public function backupDirectory(string $directoryPath) : void
    {
        $directoryPath = $this->validateUserInputFilePath($directoryPath);

        //avoid php timeouts
        ini_set("max_execution_time", $this->_phpTimeoutTime);

        $this->createNewLogEntry(self::LOG_INFO, "Backup files started");

        if ($this->_deleteBackupsAfter != -1) {

            $deletedFiles = $this->deleteOldBackup();

            if (!empty($deletedFiles)) {

                foreach ($deletedFiles as $file) {
                    $this->createNewLogEntry(self::LOG_DELETE, $file);
                }

            } else {
                $this->createNewLogEntry(self::LOG_INFO, "No old backup deleted");
            }
        }

        $zipPath = $this->createZipArchive($directoryPath);

        if ($zipPath === null) {
            $this->createNewLogEntry(self::LOG_ERROR, "Zip archive cannot be created");
            return;
        }

        $this->sendBackupMail($zipPath);

        $this->createNewLogEntry(self::LOG_CREATE, basename($zipPath));
    }

    /**
     * Creates zip archive of the given path
     * @param string $directoryPath
     * @return string|null path of the created backup,
     * null if backup failed
     */
    private function createZipArchive(string $directoryPath) : ?string
    {
        $recDirIt = new RecursiveDirectoryIterator($directoryPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $recItIt = new RecursiveIteratorIterator($recDirIt);

        $zip = new ZipArchive();
        $zipPath = $this->_backupDir . "backup_" . (new DateTime("now"))->format("Y-m-d_H-i-s") . ".zip";
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

                $this->createZipArchive($singleItem);

            } elseif (is_file($singleItem)) {

                $zip->addFile($singleItem, str_replace($directoryPath, '', $singleItem));

            }
        }

        if ($zip->close()) {
            return $zipPath;
        }

        return null;
    }

    /**
     * Starts the database backup
     * @param string $databaseHost
     * @param string $databaseUser
     * @param string $databasePassword
     * @param string $databaseName
     */
    public function backupDatabase(string $databaseHost, string $databaseUser, string $databasePassword, string $databaseName) : void
    {
        $this->createNewLogEntry(self::LOG_INFO, "Backup database started");

        $dumpPath = $this->_backupDir . "sqldump_" . (new DateTime("now"))->format("Y-m-d_H-i-s") . ".sql.gz";
        $output = [];
        exec('mysqldump --host=' . $databaseHost . ' --user=' . $databaseUser .
            ' --password=\'' . $databasePassword . '\' ' . $databaseName . ' | gzip > ' . $dumpPath, $output, $success);
        if (!$success) {
            $this->createNewLogEntry(self::LOG_CREATE, basename($dumpPath));
            $this->sendBackupMail($dumpPath);
        } else {
            $this->createNewLogEntry(self::LOG_ERROR, "Database dump cannot be created");
        }
    }

    /**
     * Sends a mail with attachment (backup zip)
     * @param string $attachmentPath
     */
    private function sendBackupMail(string $attachmentPath) : void
    {
        if($this->_mail === null){
            return;
        }

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

        $success = mail($this->_mail, $subject, $message, $header);
        if (!$success) {
            $this->createNewLogEntry(self::LOG_ERROR, "E-Mail cannot be sent");
        }
    }

    /**
     * Creates new log entry
     * @param int $type
     * @param string $message
     */
    private function createNewLogEntry(int $type, string $message) : void
    {
        //folder structure
        //2017
        //--backup-january.log
        //--backup-february.log
        //  ...

        $currentDate = new DateTime("now");

        //log folder
        $path = $this->_backupDir . "log/";
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        //year folder
        $path .= $currentDate->format("Y") . "/";
        if (!file_exists($path)) {
            mkdir($path, 0777, true);
        }

        //log file
        $path .= "backup-" . strtolower($currentDate->format("F")) . ".log";

        switch ($type) {
            case self::LOG_DELETE:
                $logType = "DELETE:";
                break;
            case self::LOG_CREATE:
                $logType = "CREATE:";
                break;
            case self::LOG_ERROR:
                $logType = "ERROR:";
                $message .= "...Script aborted";
                break;
            case self::LOG_INFO:
            default:
                $logType = "INFO:";
                break;
        }

        $entry = sprintf("%s %s %s %s" . PHP_EOL, $currentDate->format("Y-m-d H:i:s"), "-- PHPBackupScript -", $logType, $message);
        file_put_contents($path, $entry, FILE_APPEND);

        if ($type === self::LOG_ERROR) {
            exit;
        }

    }

    /**
     * Deletes zip archives older than $this->_deleteBackupsAfter days
     * log folder is excluded
     * @return array of the deleted filename
     */
    private function deleteOldBackup() : array
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

    /**
     * Validates the given filePath
     * @param string $filePath
     * @return string
     */
    private function validateUserInputFilePath(string $filePath) : string
    {
        $result = $filePath;
        if(substr($result, -1) !== DIRECTORY_SEPARATOR){
            $result .= DIRECTORY_SEPARATOR;
        }
        return $result;
    }

    //getter and setter

    /**
     * @return string of email,
     * null if unset
     */
    public function getMail() : string
    {
        return $this->_mail;
    }

    /**
     * Backup zip archive will be sent to this email
     * @param string $email ,
     * null if unset
     */
    public function setMail(string $email)
    {
        $this->_mail = $email;
    }

    /**
     * @return int in days
     */
    public function getDeleteBackupsAfter() : int
    {
        return $this->_deleteBackupsAfter;
    }

    /**
     * @param int $deleteBackupsAfter in days
     */
    public function setDeleteBackupsAfter(int $deleteBackupsAfter) : void
    {
        $this->_deleteBackupsAfter = $deleteBackupsAfter;
    }

    /**
     * @return int
     */
    public function getPhpTimeoutTime() : int
    {
        return $this->_phpTimeoutTime;
    }

    /**
     * @param int $phpTimeoutTime
     */
    public function setPhpTimeoutTime(int $phpTimeoutTime) : void
    {
        $this->_phpTimeoutTime = $phpTimeoutTime;
    }

}
