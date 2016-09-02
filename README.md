# PHPBackupScript

### Overview
* Backup of all Files
* Select own folder for zip backups
* Except backup folder
* Send E-Mail with zip archive
* Log available
* Delete old backups after X days


## Installation
It's very simple to include this script to your project.

**Download Zip**

_or_

**Clone Git**

```
git clone https://github.com/StefanSchomacker/PHPBackupScript
```

## Getting Started
**Sample Setup**

Create a path for the document root and your backup folder.
The last `/` is important!

Example:
```
$serverRoot = "/var/www/html/";
$backupDir = $serverRoot . "backup/";
```

Create an object of backup class

```
$backup = new backup($serverRoot, $backupDir);
```

Set E-Mail for zip Archive

```
$backup->setMail("[YOUR EMAIL]");
```

Configure some Settings

```
$backup->setCreateLog(true);
$backup->setDeleteBackupsAfter(20); //deletes zip archives older than 20 days
```

Execute script instantly

```
$backup->execute();
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
$_mail | null | zip will be send to this mail. `null` disabled
$_createLog | true | log files will be created in backup folder
$_deleteBackupsAfter | 30 | old zip backup will be deleted. `-1` disabled

## Details
**Timeout**

A PHP script will be canceled after 30 seconds by the server.
The backup could take more time.
To avoid the timeout, I set the `max_execution_time` to 10 minutes. 
If you need more time, feel free to change the time manually.
```
//10 minutes
ini_set("max_execution_time", 600);
```

**Backup folder**

The folder of your backups is excluded in every zip archive.

**ServerRoot**

The constructor needs the path of the document root.
Unfortunately, you can't use `$_SERVER['DOCUMENT_ROOT']`.
If you run this script via cron, the `$_SERVER` is not available.

**Zip Archive**

The default name of a zip backup is `backup_Y-m-d_H-i-s.zip`.

**Log structure**

```
year1
...backup-january.log
...backup-february.log
   ...
year2
...backup-january.log
...backup-february.log
   ...
```

## Important
Please check your zip backups regularly.
Check the log files if archive is broken.

## Improvements
Feel free to create a new
[Issue](https://github.com/StefanSchomacker/PHPBackupScript/issues) or a 
[Pull request](https://github.com/StefanSchomacker/PHPBackupScript/pulls)


## Changelog

##### v1.0
* zip archive of all files
* except backup folder
* send Mail with backup
* create log files
* delete old backups

### IDEAS and TODO for v1.1
- [ ] MySQL database backup
- [ ] user decide between backup files and db
- [ ] separate mail of different backups
- [ ] validate user input
- [ ] weekly report mail
- [ ] backup with runwhen

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
