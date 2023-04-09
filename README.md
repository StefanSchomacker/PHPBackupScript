# PHPBackupScript

### Overview
* Backup of all Files
* Select own directory for zip backups
* Except backup directory
* Log-file
* Delete old backups after X days
* MySQL database backup


## Installation
It's very simple to include this script.

**Download Zip**

_or_

**Clone Git**

```
git clone https://github.com/StefanSchomacker/PHPBackupScript
```

## Getting Started
**Sample Setup**

Create a string with the path of your backup directory.

Example:
```
$backupPath = "/var/www/html/backup/";
```

Create an object of Backup::class

```
$backup = new Backup($backupPath);
```

Configure some Settings

```
$backup->setDeleteBackupsAfter(20); //deletes archives older than 20 days
```

Execute script instantly

```
$dirPath = "/var/www/html/";
$backup->backupDirectory($dirPath);

$databaseHost = "localhost";
$databaseUser = "user";
$databasePassword = "password";
$databaseName = "databaseName";
$backup->backupDatabase($databaseHost, $databaseUser, $databasePassword, $databaseName);
```

## Run it automatically
**Cron**

* The PHP file needs `chmd a+x`
* Uncomment first lines of my script and modify

The example creates a backup every day at 1am:

```
0 1 * * * [PATH TO BACKUP.PHP] // e.g. ~/html/backup.php
```

## Default values
variable | default value | description
------------ | ------------- | -------------
$_deleteBackupsAfter | 30 | old zip backup will be deleted. `-1` disabled
$_phpTimeoutTime | 600 | max_execution_time in seconds - to avoid php timeouts

## Details
**Timeout**

A PHP script will be canceled after 30 seconds by the server.
The backup could take more time.
To avoid the timeout, I set the `max_execution_time` to 10 minutes. 
If you need more time, feel free to change the time (in seconds).

```
$backup->setPhpTimeoutTime(X);
```

**Backup directory**

The directory of your backups is excluded in every zip archive.

**Archives**

The default name of a archive is `filename_Y-m-d_H-i-s`.

**Logs**

The logfiles are created in the subdirectory `log`.
Please set up an external log rotation.

## Important
Please check your zip backups regularly.
Check the log files if archive is broken.

## Improvements
Feel free to create a new
[Issue](https://github.com/StefanSchomacker/PHPBackupScript/issues) or a 
[Pull request](https://github.com/StefanSchomacker/PHPBackupScript/pulls)

### License

    Copyright 2016 Stefan Schomacker

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.
