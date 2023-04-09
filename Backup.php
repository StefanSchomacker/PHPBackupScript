<?php

$options = getopt("",['sourcePath:','destinationPath:']);

if(empty($options)) {
    $dirPath = '/var/www/html/';
    $backupPath = '/var/www/html/backup/';
} else {
    if(!isset($options['sourcePath']) || is_array($options['sourcePath']))
        showUsage('sourcePath');
        $dirPath = $options['sourcePath'];

    if(!isset($options['destinationPath']) || is_array($options['destinationPath']))
        showUsage('destinationPath');
        $backupPath = $options['destinationPath'];
}

function showUsage($option = '') {
    echo 'Wrong use of option ' .$option.PHP_EOL;
    echo 'Usage: '. basename(__FILE__). ' [options]' .PHP_EOL;
    echo '  --sourcePath          Which directory should be backed up?'.PHP_EOL;
    echo '  --destinationPath     Where should the backup be stored?'.PHP_EOL;
    exit;
}

$backupPath = "/var/www/html/backup/";

$backup = new Backup($backupPath);
$backup->setDeleteBackupsAfter(20);
$backup->backupDirectory($dirPath);

$databaseHost = "localhost";
$databaseUser = "user";
$databasePassword = "password";
$databaseName = "databaseName";
$backup->backupDatabase($databaseHost, $databaseUser, $databasePassword, $databaseName);


class Backup
{
    private $_backupDir;

    private $_log;

    //default values
    private $_deleteBackupsAfter = 30; //delete old backups after X Days || -1 deactivate
    private $_phpTimeoutTime = 600; //default 10 minutes || max_execution_time


    /**
     * Backup constructor.
     * @param string $backupPath
     */
    public function __construct(string $backupPath)
    {
        $this->_backupDir = $this->validateUserInputFilePath($backupPath);
        $this->_log = new Log($this->_backupDir . 'log');
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

        $this->_log->info('backup files started');

        if ($this->_deleteBackupsAfter != -1) {
            $this->deleteOldBackup();
        }

        $this->createZipArchive($directoryPath);
    }

    /**
     * Creates zip archive of the given path
     * @param string $directoryPath
     */
    private function createZipArchive(string $directoryPath) : void
    {
        $recDirIt = new RecursiveDirectoryIterator($directoryPath, RecursiveDirectoryIterator::SKIP_DOTS);
        $recItIt = new RecursiveIteratorIterator($recDirIt);

        $zip = new ZipArchive();
        $zipPath = $this->_backupDir . "backup_" . (new DateTime("now"))->format("Y-m-d_H-i-s") . ".zip";
        if ($zip->open($zipPath, ZipArchive::CREATE) !== TRUE) {
            $this->_log->error('open zip file failed');
            exit(1);
        }

        $includedFiles = 0;
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
                $includedFiles++;
            }
        }

        if ($zip->close()) {
            if ($includedFiles > 0) {
                $this->_log->info(sprintf('zip archive created successfully with %d files', $includedFiles));
            } else {
                $this->_log->info('no zip archive created because of empty directory');
            }
            return;
        }

        $this->_log->error('zip archive creation failed');
        exit(1);
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
        $this->_log->info('backup database started');

        $dumpPath = $this->_backupDir . "sqldump_" . (new DateTime("now"))->format("Y-m-d_H-i-s") . ".sql.gz";
        $output = [];
        exec('mysqldump --host=' . $databaseHost . ' --user=' . $databaseUser .
            ' --password=\'' . $databasePassword . '\' ' . $databaseName . ' | gzip > ' . $dumpPath, $output, $success);
        if (!$success) {
            $this->_log->info('database dump created ' . basename($dumpPath));
        } else {
            $this->_log->error('database dump cannot be created');
            exit(1);
        }
    }

    /**
     * Deletes zip archives older than $this->_deleteBackupsAfter days
     */
    private function deleteOldBackup() : void
    {
        $recDirIt = new RecursiveDirectoryIterator($this->_backupDir, RecursiveDirectoryIterator::SKIP_DOTS);
        $recItIt = new RecursiveIteratorIterator($recDirIt);

        foreach ($recItIt as $file) {
            if (is_file($file)) {
                $dateFileCreated = date_create();
                $dateToday = date_create();
                date_timestamp_set($dateFileCreated, filectime($file));

                if (pathinfo($file)["extension"] == "zip" &&
                    date_diff($dateFileCreated, $dateToday)->d >= $this->_deleteBackupsAfter
                ) {
                    if (unlink($file)) {
                        $this->_log->info('delete old backup ' . basename($file));
                    } else {
                        $this->_log->warn('deleting backup failed for file ' . basename($file));
                    }
                }
            }
        }
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

class Log
{
    private $_logPath;

    public function __construct(string $logPath)
    {
        $this->_logPath = $logPath;
        if (!file_exists($this->_logPath)) {
            mkdir($this->_logPath, 0440, true);
        }
    }

    /**
     * Creates new debug log entry.
     * @param string $message
     */
    public function debug($message): void
    {
        $this->log('DEBUG', $message);
    }

    /**
     * Creates new info log entry.
     * @param string $message
     */
    public function info($message): void
    {
        $this->log('INFO', $message);
    }

    /**
     * Creates new warn log entry.
     * @param string $message
     */
    public function warn($message): void
    {
        $this->log('WARN', $message);
    }

    /**
     * Creates new error log entry.
     * @param string $message
     */
    public function error($message): void
    {
        $this->log('ERROR', $message);
    }

    /**
     * Creates new log entry.
     * @param string $logLevel
     * @param string $message
     */
    private function log(string $logLevel, string $message): void
    {
        $file = $this->_logPath . '/backup.log';
        $entry = sprintf("%s - %s - %s - %s" . PHP_EOL, (new DateTime('now'))->format("Y-m-d H:i:s"), 'PHPBackupScript', $logLevel, $message);
        echo $entry;
        file_put_contents($file, $entry, FILE_APPEND);
    }
}
