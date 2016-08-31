<?php

$backupDir = $_SERVER['DOCUMENT_ROOT'] . "/backup/";

$backup = new backup($backupDir);
$backup->setMail("backup@example.com");

$backup->execute();

class backup
{
    private $_backupDir;
    private $_recDirIt;
    private $_recItIt;

    //zip values
    private $_zipNumFiles = null;
    private $_zipStatus = null;

    //default values
    private $_mail = null;
    private $_createLog = true;


    public function __construct($backupDir)
    {
        $this->_backupDir = $backupDir;
        $this->_recDirIt = new RecursiveDirectoryIterator($_SERVER['DOCUMENT_ROOT'], RecursiveDirectoryIterator::SKIP_DOTS);
        $this->_recItIt = new RecursiveIteratorIterator($this->_recDirIt);
    }

    public function execute()
    {
        $success = true;

        //10 minutes
        ini_set('max_execution_time', 600);

        if ($this->_createLog) {
            $this->createNewLogEntry("start");
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
            $this->createNewLogEntry("end");
        }


        return $success;
    }

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

    private function sendMail($attachmentPath)
    {
        $from = "backup@" . $_SERVER['SERVER_NAME'];
        $subject = 'Backup ' . $_SERVER['HTTP_HOST'] . ' || ' . basename($attachmentPath);

        $content = chunk_split(base64_encode(file_get_contents($attachmentPath)));
        $part = md5(time());

        $header = "From: " . $from . "\r\n";
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

    private function createNewLogEntry($type, $message = null)
    {

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
        $logMessage = "...";

        switch ($type) {
            case "start":
                $logType = "NOTICE";
                $logMessage = "Backup started";
                break;
            case "end":
                $logType = "NOTICE";
                $logMessage = "Backup finished successfully";
                break;
            case "error":
                $logType = "ERROR";
                if (!is_null($message)) {
                    $logMessage = $message . "...Script aborted";
                } else {
                    $logMessage = "An error has occurred";
                }
                break;
        }

        $entry = sprintf("%s %s %s %s\r\n", date("Y-m-d H:i:s"), "-- PHPBackupScript -", $logType, $logMessage);
        file_put_contents($path, $entry, FILE_APPEND);

        if ($type == "error") {
            exit;
        }

    }


    //getter and setter

    /**
     * @return null|string
     */
    public function getMail()
    {
        return $this->_mail;
    }

    /**
     * @param null|string $email
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

}