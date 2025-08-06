<?php

namespace local_recompletion\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use context_system;
use core_user;
use moodle_url;

class process_bulk_completion_files extends scheduled_task
{

    public function get_name()
    {
        return get_string('processbulkuploadtask', 'local_recompletion');
    }

    public function execute()
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/local/recompletion/lib.php'); // your import_completion_file() here

        $toprocess = $CFG->dataroot . '/local_recompletion/bulkuploads/toprocess';
        $processed = $CFG->dataroot . '/local_recompletion/bulkuploads/processed';

        if (!is_dir($toprocess)) {
            mtrace("Directory not found: $toprocess");
            return;
        }
        $files = array_filter(scandir($toprocess), function ($file) use ($toprocess) {
            return is_file("$toprocess/$file");
        });

        if (empty($files)) {
            mtrace("No files to process.");
            return;
        }

        if (empty($files)) {
            // No files found
            return;
        }

        usort($files, function ($a, $b) use ($toprocess) {
            return filemtime("$toprocess/$b") <=> filemtime("$toprocess/$a");
        });

        $latestfile = reset($files);
        $latestfilepath = "$toprocess/$latestfile";

        $latestfile = $files[0];
        $filepath = "$toprocess/$latestfile";
        mtrace("Processing file: $latestfile");


        // Read file content
        $fs = get_file_storage();
        $csvcontent = file_get_contents($filepath);

        // Simulate file-like object
        $tempfile = tmpfile();
        fwrite($tempfile, $csvcontent);
        fseek($tempfile, 0);

        // Process file and collect exceptions
        $exceptions = [];
        $exceptions = import_completion_file($filepath, $exceptions); // New version that accepts path & returns exceptions


        // Email exceptions if any
        if (!empty($exceptions)) {
            $csv = "userid,username,email,reason\n";
            foreach ($exceptions as $e) {
                $csv .= implode(',', array_map(function ($v) {
                        return clean_param($v, PARAM_TEXT);
                    }, $e)) . "\n";
            }

            $admin = get_admin();
            $subject = "Bulk Upload Exceptions from $latestfile";
            $message = "Some users could not be processed in $latestfile. See attached CSV.";
            $tempattachment = $CFG->tempdir . "/exceptions_" . time() . ".csv";
            file_put_contents($tempattachment, $csv);

            email_to_user(
                $admin,
                core_user::get_noreply_user(),
                $subject,
                $message,
                $message,
                $tempattachment,
                'exceptions.csv'
            );

            unlink($tempattachment);
        }

        // Move file to processed/
         rename($filepath, "$processed/$latestfile");

        mtrace("Moved $latestfile to processed/");
    }
}
