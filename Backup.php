<?php

$backupDir = $_SERVER['DOCUMENT_ROOT'] . "/backup/";
$backup = new backup($backupDir, "backup@example.com");
$backup->startBackup();

class backup
{
    private $_backupDir;
    private $_recDirIt;
    private $_recItIt;

    private $_email;


    public function __construct($backupDir, $email = null)
    {
        $this->_backupDir = $backupDir;
        $this->_recDirIt = new RecursiveDirectoryIterator($_SERVER['DOCUMENT_ROOT'], RecursiveDirectoryIterator::SKIP_DOTS);
        $this->_recItIt = new RecursiveIteratorIterator($this->_recDirIt);

        $this->_email = $email;
    }

    public function startBackup()
    {
        $zipPath = $this->backup($this->_recItIt);

        if (is_null($zipPath)) {
            return false;
        }
        if (!is_null($this->_email)) {
            return $this->sendMail($zipPath);
        }
        return true;
    }

    private function backup($recItIt)
    {
        $zip = new ZipArchive();
        $zipPath = $this->_backupDir . "backup_" . date("Y-m-d_H-i-s") . ".zip";
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            exit("open zip file failed");
        }

        foreach ($recItIt as $singleItem) {

            if (dirname($singleItem) . "/" == $this->_backupDir) {
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

        return mail($this->_email, $subject, $message, $header);
    }

}