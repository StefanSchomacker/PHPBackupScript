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

    /**
     * Backup constructor.
     * @param $backupDir
     */
    public function __construct($backupDir)
    {
        $this->_backupDir = $backupDir;
    }

    /**
     * Starts the file backup
     * @param $directory
     */
    public function backupDirectory($directory)
    {
        //avoid php timeouts
        ini_set("max_execution_time", $this->_phpTimeoutTime);

        $this->createNewLogEntry("info", "Backup files started");

        if ($this->_deleteBackupsAfter != -1) {

            $deletedFiles = $this->deleteOldBackup();

            if (!empty($deletedFiles)) {

                foreach ($deletedFiles as $file) {
                    $this->createNewLogEntry("delete", $file);
                }

            } else {
                $this->createNewLogEntry("info", "No old backup deleted");
            }
        }

        $zipPath = $this->createZipArchive($directory);

        if ($zipPath === null) {
            $this->createNewLogEntry("error", "Zip archive cannot be created");
            return;
        }

        $this->sendBackupMail($zipPath);

        $this->createNewLogEntry("create", basename($zipPath));

        if ($this->_weeklyReport) {
            //check date last E-Mail
            $currentDate = new DateTime("now");
            $content = file($this->_backupDir . "log/" . $currentDate->format("Y") . "/"
                . "backup-" . strtolower($currentDate->format("F")) . ".log");

            $reportRequired = $this->isReportMailRequired($content);

            if ($reportRequired == -1) {
                //check if last E-Mail was in previous month
                $currentDate->modify("-1 month");
                $content = file($this->_backupDir . "log/" . $currentDate->format("Y") . "/"
                    . "backup-" . strtolower($currentDate->format("F")) . ".log");
                $reportRequired = $this->isReportMailRequired($content);
            }

            if ($reportRequired) {

                $this->sendReportMail();
                $this->createNewLogEntry("info", "E-Mail Report send");

            }

        }
    }

    /**
     * Creates zip archive of the given path
     * @param $directory
     * @return string path of the created backup,
     * null if backup failed
     */
    private function createZipArchive($directory)
    {
        $recDirIt = new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS);
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

                $zip->addFile($singleItem, str_replace($directory, '', $singleItem));

            }
        }

        if ($zip->close()) {
            return $zipPath;
        }

        return null;
    }

    /**
     * Starts the database backup
     * @param $databaseHost
     * @param $databaseUser
     * @param $databasePassword
     * @param $databaseName
     */
    public function backupDatabase($databaseHost, $databaseUser, $databasePassword, $databaseName)
    {
        $this->createNewLogEntry("info", "Backup database started");

        $dumpPath = $this->_backupDir . "sqldump_" . (new DateTime("now"))->format("Y-m-d_H-i-s") . ".sql.gz";
        $output = [];
        exec('mysqldump --host=' . $databaseHost . ' --user=' . $databaseUser .
            ' --password=\'' . $databasePassword . '\' ' . $databaseName . ' | gzip > ' . $dumpPath, $output, $success);
        if (!$success) {
            $this->createNewLogEntry("create", basename($dumpPath));
            $this->sendBackupMail($dumpPath);
        } else {
            $this->createNewLogEntry("error", "Database dump cannot be created");
        }
    }

    /**
     * Sends a mail with attachment (backup zip)
     * @param $attachmentPath
     */
    private function sendBackupMail($attachmentPath)
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
            $this->createNewLogEntry("error", "E-Mail cannot be sent");
        }
    }

    /**
     * Creates new log entry
     * $type = {INFO, DELETE, CREATE, ERROR}
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

        $logType = "UNDEFINED";

        switch ($type) {
            case "info":
                $logType = "INFO:";
                break;
            case "delete":
                $logType = "DELETE:";
                break;
            case "create":
                $logType = "CREATE:";
                break;
            case "error":
                $logType = "ERROR:";
                $message .= "...Script aborted";
                break;
        }

        $entry = sprintf("%s %s %s %s" . PHP_EOL, $currentDate->format("Y-m-d H:i:s"), "-- PHPBackupScript -", $logType, $message);
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

    /**
     * Checks if last Report Mail was in another week
     * @param array $fileContent
     * @return bool true if Report Mail is required,
     * false otherwise
     * int -1 if not found
     */
    private function isReportMailRequired($fileContent)
    {
        foreach (array_reverse($fileContent) as $entry) {

            if (preg_match("/(INFO: E-Mail Report send)/", $entry) == 1) {

                if ((new DateTime(substr($entry, 0, 10)))->format("W") != (new DateTime("now"))->format("W")) {
                    return true;
                }

                return false;

            }

        }
        return -1;
    }


    /**
     * Sends report email every sunday.
     * Contains:
     *      - Error during backup
     *      - Created backups
     *      - Deleted backups
     * No changes = No Email
     */
    private function sendReportMail()
    {
        $generatedReport = $this->generateReportContent();
        if ($generatedReport != false) {

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
     * @return string contains HTML formatted report,
     * false on failure
     */
    private function generateReportContent()
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

        return ($msg != "") ? $msg : false;

    }

    /**
     * Generates string array with all paths of the current week
     * @return array
     */
    private function getLastWeekPath()
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
     * @param $array
     * @return string
     */
    private function splitInUL($array)
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

    /**
     * @return boolean
     */
    public function getWeeklyReport()
    {
        return $this->_weeklyReport;
    }

    /**
     * @param boolean $weeklyReport
     */
    public function setWeeklyReport($weeklyReport)
    {
        $this->_weeklyReport = $weeklyReport;
    }

    /**
     * @return int
     */
    public function getPhpTimeoutTime()
    {
        return $this->_phpTimeoutTime;
    }

    /**
     * @param int $phpTimeoutTime
     */
    public function setPhpTimeoutTime($phpTimeoutTime)
    {
        $this->_phpTimeoutTime = $phpTimeoutTime;
    }

}
