# PHPBackupScript

### Overview
* Backup of all Files
* Select own folder for zip backups
* Except backup folder
* Send E-Mail with zip archive
* Log-file
* Weekly Report E-Mail
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

Create an object of Backup::class

```
$backup = new Backup($serverRoot, $backupDir);
```

Set E-Mail for zip Archive

```
$backup->setMail("[YOUR EMAIL]");
```

Configure some Settings

```
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
$_deleteBackupsAfter | 30 | old zip backup will be deleted. `-1` disabled
$_weeklyReport | false | send report mail
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

##### v1.1.0
* added weekly report mail
* log is now activated by default
* log entry style changed
* set max_execution_time to avoid php timeouts
* changed date to DateTime

##### v1.0.0
* zip archive of all files
* except backup folder
* send Mail with backup
* create log files
* delete old backups

### IDEAS and TODO
- [ ] MySQL database backup
- [ ] user decide between backup files and db
- [ ] separate mail of different backups
- [ ] validate user input
- [x] weekly report mail
- [x] set max_execution_time by function
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
