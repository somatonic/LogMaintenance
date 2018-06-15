# LogMaintenance

Make sure you don't have any exceptional large log files, as it could possibly fail to prune those via PHP. So prune, delete or back them up first.

**Global Settings**

The global settings are for all log files that are found within the site/assets/logs/ directory. These settings are recognized from the top down and the first setting found will be used. To ignore a setting, leave it blank or enter 0.

**Per Log Settings**

The per log settings can be used to set a rule for each log separately. These overwrite the global settings for the specified log file.

**Archive as ZIP**

The zip will be created in the site/assets/logs/archive/ folder. Only 1 zip file per log will be created. Each time the log is added to the zip into a subfolder named Ymd-His and deleted.

## Per Log Settings

Add a config per line. Example: errors:[archive]:[lines]:[days]:[bytes]- Logs currently found: file-compiler, helper, logmaintenance, modules, multisite

```errors:1:0:0:0 // would archive the errors.txt log each time the maintenance is run```

```errors:0:10000:0:0 // would prune the errors log to 10000 lines```

```errors:0:0:0:1000000 // would prune the errors log to 1000000 bytes```