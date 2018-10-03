<?php

/*
$backupDir = "/var/www/html/backup/";

$backup = new Backup($backupDir);
$backup->setMail("[YOUR EMAIL]");
$backup->setDeleteBackupsAfter(20);
$backup->setWeeklyReport(true);

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
    private $_weeklyReport = false; //send email report on sunday
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
        $this->_backupDir = $backupPath;
    }

    /**
     * Starts the file backup
     * @param string $directoryPath
     */
    public function backupDirectory(string $directoryPath) : void
    {
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

        if ($this->_weeklyReport) {
            //check date last E-Mail
            $currentDate = new DateTime("now");
            $content = file($this->_backupDir . "log/" . $currentDate->format("Y") . "/"
                . "backup-" . strtolower($currentDate->format("F")) . ".log");

            $reportRequired = $this->isReportMailRequired($content);

            if (!$reportRequired) {
                //check if last E-Mail was in previous month
                $currentDate->modify("-1 month");
                $content = file($this->_backupDir . "log/" . $currentDate->format("Y") . "/"
                    . "backup-" . strtolower($currentDate->format("F")) . ".log");
            } else {
                $this->sendReportMail();
                $this->createNewLogEntry(self::LOG_INFO, "E-Mail Report send");
            }

        }
    }

    /**
     * Creates zip archive of the given path
     * @param string $directoryPath
     * @return string path of the created backup,
     * null if backup failed
     */
    private function createZipArchive(string $directoryPath) : string
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
            case self::LOG_INFO:
                $logType = "INFO:";
                break;
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
     * Checks if last Report Mail was in another week
     * @param array $fileContent
     * @return bool true if Report Mail is required,
     * false otherwise
     */
    private function isReportMailRequired(array $fileContent) : bool
    {
        foreach (array_reverse($fileContent) as $entry) {

            if (preg_match("/(INFO: E-Mail Report send)/", $entry) == 1) {

                if ((new DateTime(substr($entry, 0, 10)))->format("W") != (new DateTime("now"))->format("W")) {
                    return true;
                }

                return false;

            }

        }
        return false;
    }


    /**
     * Sends report email every sunday.
     * Contains:
     *      - Error during backup
     *      - Created backups
     *      - Deleted backups
     * No changes = No Email
     */
    private function sendReportMail() : void
    {
        $generatedReport = $this->generateReportContent();
        if ($generatedReport !== null) {

            $currentDate = new DateTime("now");

            $subject = "Backup Report - Week " . $currentDate->format("W");
            $message = "<h1>Report Week " . $currentDate->format("W") . "</h1>";
            $message .= $generatedReport;

            $header = "MIME-Version: 1.0 \r\n";
            $header .= "Content-type: text/html; charset=iso-8859-1 \r\n";
            $header .= "X-Mailer: PHP " . phpversion();

            mail($this->_mail, $subject, $message, $header);

        }
    }

    /**
     * Creates HTML formatted string for sendReportMail().
     * Validate Log files of current week
     * @return string contains HTML formatted report
     */
    private function generateReportContent() : string
    {
        $errorLogEntries = array();
        $createLogEntries = array();
        $deleteLogEntries = array();

        foreach ($this->getLastWeekPath() as $path) {

            $logEntries = file($path);
            $logEntries = array_reverse($logEntries);

            for ($i = 0; $i < count($logEntries); $i++) {

                if (date("W", strtotime(substr($logEntries[$i], 0, 10))) != date("W")) {
                    continue;
                }

                if (preg_match("/(INFO:)/", $logEntries[$i]) == 1) {
                    continue;
                } elseif (preg_match("/(DELETE:)/", $logEntries[$i]) == 1) {
                    array_push($deleteLogEntries,
                        substr($logEntries[$i], strpos($logEntries[$i], "DELETE:") + 8));
                } elseif (preg_match("/(CREATE:)/", $logEntries[$i]) == 1) {
                    array_push($createLogEntries,
                        substr($logEntries[$i], strpos($logEntries[$i], "CREATE:") + 8));
                } elseif (preg_match("/(ERROR:)/", $logEntries[$i]) == 1) {
                    array_push($errorLogEntries,
                        substr($logEntries[$i], strpos($logEntries[$i], "ERROR:") + 7) . " on " . substr($logEntries[$i], 0, 19));
                }

            }
        }

        $msg = "";

        //generate string for mail
        if (!empty($errorLogEntries)) {
            $msg .= "<h2>Error:</h2>";
            $msg .= $this->splitInUL($errorLogEntries);
        }

        if (!empty($createLogEntries)) {
            $msg .= "<h2>Created:</h2>";
            $msg .= $this->splitInUL($createLogEntries);
        }

        if (!empty($deleteLogEntries)) {
            $msg .= "<h2>Deleted:</h2>";
            $msg .= $this->splitInUL($deleteLogEntries);
        }

        return ($msg != "") ? $msg : null;

    }

    /**
     * Generates string array with all paths of the current week
     * @return array
     */
    private function getLastWeekPath() : array
    {
        $currentDate = new DateTime("now");
        $previousDate = new DateTime("now");

        if (((int)$currentDate->format("j") - 7) < 1) {
            //week is also in previous month
            $previousDate->modify("-1 month");
        }

        $arrPath = array();
        $path = $this->_backupDir . "log/";

        //check if different || only check dates - timestamp could be inaccurate
        if ($currentDate->format("Y-m-d") != $previousDate->format("Y-m-d")) {
            $pathYear = $previousDate->format("Y") . "/";
            $pathFile = "backup-" . strtolower($previousDate->format("F")) . ".log";

            if (file_exists($path . $pathYear . $pathFile) || true) {
                array_push($arrPath, $path . $pathYear . $pathFile);
            }
        }

        $pathYear = $currentDate->format("Y") . "/";
        $pathFile = "backup-" . strtolower($currentDate->format("F")) . ".log";

        if (file_exists($path . $pathYear . $pathFile) || true) {
            array_push($arrPath, $path . $pathYear . $pathFile);
        }

        return $arrPath;
    }

    /**
     * Puts all items of the array in an unordered list (HTML)
     * @param array $array
     * @return string
     */
    private function splitInUL(array $array) : string
    {
        $msg = "<ul>";
        foreach ($array as $entry) {
            $msg .= "<li>" . $entry . "</li>";
        }
        $msg .= "</ul>";

        return $msg;
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
     * @return boolean
     */
    public function getWeeklyReport() : bool
    {
        return $this->_weeklyReport;
    }

    /**
     * @param boolean $weeklyReport
     */
    public function setWeeklyReport(bool $weeklyReport) : void
    {
        $this->_weeklyReport = $weeklyReport;
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
