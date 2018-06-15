<?php

/**
 *  Module to help maintain log files
 *
 */



class LogMaintenance extends WireData implements Module, ConfigurableModule {

    protected static $defaults = array(
        "interval" => "every6Hours",
        "archive" => 0,
        "lines" => 0,
        "days" => 0,
        "bytes" => 0,
        "logsconfig" => "",
    );

    static $logs;

    /**
     * getModuleInfo is a method required by all modules to tell ProcessWire about them
     * @return array
     */
    public static function getModuleInfo() {

        return array(
            'title' => 'Log Maintenance',
            'version' => 1,
            'summary' => 'Module to maintenance logfiles automaticly using LazyCron',
            'href' => '',
            'author' => 'Philipp Urlich, update AG',
            'singular' => true,
            'autoload' => true,
            'requires' => "ProcessWire>=2.6.0",
            'installs' => "LazyCron",
            );
    }


    /**
     * Initialize the module
     *
     * ProcessWire calls this when the module is loaded. For 'autoload' modules, this will be called
     * when ProcessWire's API is ready. As a result, this is a good place to attach hooks.
     *
     */
    public function init() {

        self::$logs = $this->wire("log")->getLogs();

        $options = self::$defaults;
        foreach(self::$defaults as $key => $unused) {
            $this->$key = $this->get($key) ?: $options[$key];
        }

        $logsArray = array();
        if($this->logsconfig) {
            $logs = explode("\n", $this->logsconfig);
            foreach($logs as $log) {
                list($name, $archive, $lines, $days, $bytes) = explode(":", $log);
                $logsArray[] = array(
                    "name" => $name,
                    "archive" => $archive,
                    "lines" => $lines,
                    "days" => $days,
                    "bytes" => $bytes,
                );
            }
        }

        $this->logsArray = $logsArray;

        // create archive folder if not exists
        $this->archivePath = $this->wire("config")->paths->assets . "logs/archive/";
        if(!is_dir($this->archivePath)){
            wireMkdir($this->archivePath);
        }

        $this->addHook("LazyCron::{$this->interval}", $this, "maintenance");

    }

    /**
     * Maintenance Function
     */
    public function maintenance() {

        $this->wire("log")->save("logmaintenance", "log maintenance started");

        // per the text config
        if(count($this->logsArray)) {

            foreach($this->logsArray as $logsettings) {

                // list($name, $lines, $days, $bytes, $archive) = $logsettings;
                $name       = $logsettings['name'];
                $archive    = $logsettings['archive'];
                $lines      = $logsettings['lines'];
                $days       = $logsettings['days'];
                $bytes      = $logsettings['bytes'];

                // exists really?
                $pathName = $this->wire("config")->paths->assets . "logs/". $name . ".txt";
                if(!file_exists($pathName)) continue;

                $totalEntries = $this->wire("log")->getTotalEntries($name);
                $log = $this->wire("log")->getFileLog($name);
                $size = $log->size();

                if($archive) {
                    $this->archiveLog($name, $log->pathName());
                    $log->delete();
                    $this->wire("session")->message("Log '$name' archived");

                } else if($lines) {
                    if($totalEntries > $lines) {
                        $this->pruneLines($name, $lines);
                        $this->wire("session")->message("Log '$name' pruned to $lines lines");
                    }

                } else if($days) {
                    $this->wire("log")->prune($name, $days);
                    $this->wire("session")->message("Log '$name' pruned by $days");

                } else if($bytes) {
                    if($size > $bytes) {
                        $log->pruneBytes($bytes);
                        $this->wire("session")->message("Log pruned $name to $bytes");
                    }
                }

            }

        }

        // all log files found, except the ones found in the text config
        $logfiles = $this->wire("log")->getLogs();

        // if not settings active return
        if(!$this->archive && !$this->lines && !$this->days && !$this->bytes) return;

        foreach($logfiles as $logfile) {

            $name = $logfile['name'];

            // is there a text config for this log already?
            if(count($this->logsArray)) {
                $key = array_search($name, array_column($this->logsArray, 'name'));
                if($key !== false) continue;
            }

            $size = $logfile['size'];
            $totalEntries = $this->wire("log")->getTotalEntries($name);
            $log = $this->wire("log")->getFileLog($name);

            if($this->archive != "") {
                $this->archiveLog($name, $log->pathName());
                $log->delete();
                $this->wire("session")->message("Log '$name' archived");

            } else if($this->lines) {
                $this->pruneLines($name, $this->lines);
                $this->wire("session")->message("Log '$name' pruned to $this->lines lines");

            } else if($this->days) {
                $this->wire("log")->prune($name, $this->days);
                $this->wire("session")->message("Log '$name' pruned by $this->days");

            } else if($this->bytes) {
                if($this->bytes && $size > $this->bytes) {
                    $log->pruneBytes($this->bytes);
                    $this->wire("session")->message("Log '$name' pruned to $this->bytes");
                }

            }
        }
    }

    /**
     * Archive the logs to a zip file, adding folder per date
     * @param  string $name Name of the log
     * @param  string $file The file to add to zip
     */
    public function archiveLog($name, $file){
        $options = array(
            "overwrite" => false, // adds files to zip
            "dir" => date("Ymd-His"), // folder to add new files to
        );
        wireZipFile($this->archivePath . $name . ".zip", $file, $options);
    }

    /**
     * Prune log by lines
     * @param  string $name  Name of the log
     * @param  integer $lines Max lines to cut to
     * @return integer        Number of lines or written
     */
    public function pruneLines($name, $lines){
        $log = $this->wire("log")->getFileLog($name);
        $toFile = $log->pathname() . '.new';

        $qty = $log->find($lines, 1, array(
            'reverse' => true,
            'toFile' => $toFile,
            'dateFrom' => 0,
            'dateTo' => 0,
        ));

        if(file_exists($toFile)) {
            unlink($log->pathname());
            rename($toFile, $log->pathname());
            return $qty;
        }

        return 0;
    }



    /**
     * build module configuration fields
     * @param  array  $data module config array
     * @return fieldwrapper fieldwrapper object
     */
    public static function getModuleConfigInputfields(array $data) {

        $fields = new InputfieldWrapper();

        $data = array_merge(self::$defaults, $data);

        $field = wire('modules')->get("InputfieldMarkup");
        $field->value = "<p><b>General Infos</b></p><p>Make sure you don't have any exceptional large log files, as it could possibly fail to prune those via PHP. So prune, delete or back them up first.</p>";
        $field->value .= "<p>The global settings are for all log files that are found within the site/assets/logs/ directory. These settings are recognized from the top down and the first setting found will be used. To ignore a setting, leave it blank or enter 0.</p>";
        $field->value .= "<p>The per log settings can be used to set a rule for each log separately. These overwrite the global settings for the specified log file.</p>";
        $fields->add($field);

        $field = wire('modules')->get("InputfieldSelect");
        $field->attr('name', 'interval');
        $field->addOptions(array(
            'every30Seconds' => 'every30Seconds',
            'everyMinute' => 'everyMinute',
            'every2Minutes' => 'every2Minutes',
            'every3Minutes' => 'every3Minutes',
            'every4Minutes' => 'every4Minutes',
            'every5Minutes' => 'every5Minutes',
            'every10Minutes' => 'every10Minutes',
            'every15Minutes' => 'every15Minutes',
            'every30Minutes' => 'every30Minutes',
            'every45Minutes' => 'every45Minutes',
            'everyHour' => 'everyHour',
            'every2Hours' => 'every2Hours',
            'every4Hours' => 'every4Hours',
            'every6Hours' => 'every6Hours',
            'every12Hours' => 'every12Hours',
            'everyDay' => 'everyDay',
            'every2Days' => 'every2Days',
            'every4Days' => 'every4Days',
            'everyWeek' => 'everyWeek',
            'every2Weeks' => 'every2Weeks',
            'every4Weeks' => 'every4Weeks',
        ));
        $field->attr('value', $data['interval']);
        $field->label = "Interval for LazyCron";
        $fields->append($field);

        $fs = wire("modules")->get("InputfieldFieldset");
        $fs->label = "Global Settings";

        $field = wire('modules')->get("InputfieldCheckbox");
        $field->attr('name', 'archive');
        $field->attr('value', $data['archive']);
        $field->attr('checked', $data['archive'] ? "checked" : "");
        $field->label = "Archive logs as .zip";
        $field->description = "The zip will be created in the site/assets/logs/archive/ folder. Only 1 zip file per log will be created. Each time the log is added to the zip into a subfolder named Ymd-His and deleted.";
        $fs->append($field);

        $field = wire('modules')->get("InputfieldInteger");
        $field->attr('name', 'lines');
        $field->attr('value', $data['lines']);
        $field->label = "Lines";
        $field->description = "Prunes the logs to number of lines";
        $fs->append($field);

        $field = wire('modules')->get("InputfieldInteger");
        $field->attr('name', 'days');
        $field->attr('value', $data['days']);
        $field->label = "Days";
        $field->description = "Prune logs to days specified";
        $fs->append($field);

        $field = wire('modules')->get("InputfieldInteger");
        $field->attr('name', 'Bytes');
        $field->attr('value', $data['bytes']);
        $field->Label = "Bytes";
        $field->description = "Prune logs to number of bytes";
        $fs->append($field);

        $fields->add($fs);


        $fs = wire("modules")->get("InputfieldFieldset");
        $fs->label = "Per Log Settings";

        $field = wire('modules')->get("InputfieldTextarea");
        $field->attr('name', 'logsconfig');
        $field->attr('value', $data['logsconfig']);
        $field->label = "Enter a explicit config for logs";
        $field->description = "This config settings overwrites the global settings above.";
        $field->notes = "Add a config per line. Example: errors:[archive]:[lines]:[days]:[bytes]";
        $field->notes .= "- Logs currently found:";
        foreach(self::$logs as $log) $field->notes .= " " . $log['name'] . ",";
        $field->notes = rtrim($field->notes, ",");

        $fs->append($field);
        $fields->append($fs);

        return $fields;

    }


}

