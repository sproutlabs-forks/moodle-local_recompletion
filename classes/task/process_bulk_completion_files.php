<?php

namespace local_recompletion\task;

defined('MOODLE_INTERNAL') || die();

use core\task\scheduled_task;
use context_system;
use core_user;
use moodle_url;

class process_bulk_completion_files extends scheduled_task
{
    /**
     * User that should receive every bulk completion task notification.
     */
    const ALWAYS_EMAIL_USERID = 46194;

    public function get_name()
    {
        return get_string('processbulkuploadtask', 'local_recompletion');
    }

    public function execute()
    {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/local/recompletion/lib.php');

        $toprocess = $CFG->dataroot . '/local_recompletion/bulkuploads/toprocess';
        $processed = $CFG->dataroot . '/local_recompletion/bulkuploads/processed';

        if (!is_dir($toprocess)) {
            mtrace("Directory not found: $toprocess");
            return;
        }

        $files = array_filter(scandir($toprocess), function ($file) use ($toprocess) {
            return is_file($toprocess . '/' . $file);
        });

        if (empty($files)) {
            mtrace('No files to process.');
            return;
        }

        usort($files, function ($a, $b) use ($toprocess) {
            return filemtime($toprocess . '/' . $b) <=> filemtime($toprocess . '/' . $a);
        });

        $latestfile = reset($files);
        $filepath = $toprocess . '/' . $latestfile;
        mtrace('Processing file: ' . $latestfile);

        $fh = fopen($filepath, 'r');
        if ($fh === false) {
            mtrace('Unable to open file: ' . $latestfile);
            return;
        }

        $headers = fgetcsv($fh);
        fclose($fh);

        if ($headers && isset($headers[0])) {
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
        }

        $expected = ['email', 'completed', 'courseid'];

        $found = array_map(function($h) {
            return trim((string)$h);
        }, (array)$headers);

        $missing = array_diff($expected, $found);
        $extra   = array_diff($found, $expected);

        if (!empty($missing) || !empty($extra)) {
            $subject = 'Bulk Upload Header Mismatch: ' . $latestfile;
            $message =
                "The uploaded file \"$latestfile\" has invalid headers.\n" .
                "Expected: " . implode(',', $expected) . "\n" .
                "Found: " . implode(',', $found);
            $this->send_task_email($subject, $message);
            if (is_dir($processed)) {
                @rename($filepath, $processed . '/' . $latestfile);
                mtrace('Moved ' . $latestfile . ' to processed/');
            }
            return;
        }

        $exceptions = [];
        $exceptions = import_completion_file($filepath, $exceptions);

        $subject = 'Bulk Upload Processed Complete: ' . $latestfile;
        $message = $latestfile;
        $this->send_task_email($subject, $message);

        if (!empty($exceptions)) {
            $csv = "email,courseid,reason\n";
            foreach ($exceptions as $e) {
                $row = array(
                    clean_param($e['email'], PARAM_TEXT),
                    clean_param($e['courseid'], PARAM_INT),
                    clean_param($e['reason'], PARAM_TEXT),
                );
                $csv .= implode(',', $row) . "\n";
            }
            $subject = 'Bulk Upload Exceptions from ' . $latestfile;
            $message = 'Some users could not be processed in ' . $latestfile . '. See attached CSV.';
            $tempattachment = $CFG->dataroot . '/local_recompletion/exceptions_' . time() . '.csv';

            file_put_contents($tempattachment, $csv);
            $this->send_task_email($subject, $message, $tempattachment, 'exceptions.csv');
            @unlink($tempattachment);
        }

        if (is_dir($processed)) {
            @rename($filepath, $processed . '/' . $latestfile);
            mtrace('Moved ' . $latestfile . ' to processed/');
        }
    }

    /**
     * Sends task emails to the admin and the always-on recipient, with mtrace logging.
     *
     * @param string $subject email subject.
     * @param string $message plain/html email body.
     * @param string $attachment optional attachment path.
     * @param string $attachname optional attachment name.
     */
    protected function send_task_email($subject, $message, $attachment = '', $attachname = '')
    {
        global $DB;

        $from = core_user::get_noreply_user();
        $recipients = array();

        $admin = get_admin();
        if (!empty($admin->id)) {
            $recipients[$admin->id] = $admin;
        }

        $alwaysrecipient = $DB->get_record('user', array('id' => self::ALWAYS_EMAIL_USERID, 'deleted' => 0));
        if ($alwaysrecipient) {
            $recipients[$alwaysrecipient->id] = $alwaysrecipient;
        } else {
            mtrace('Bulk upload task email recipient user id ' . self::ALWAYS_EMAIL_USERID . ' was not found.');
        }

        foreach ($recipients as $recipient) {
            $sent = email_to_user($recipient, $from, $subject, $message, $message, $attachment, $attachname);
            if ($sent) {
                mtrace('Bulk upload task email sent to user id ' . $recipient->id . ': ' . $subject);
            } else {
                mtrace('Bulk upload task email failed for user id ' . $recipient->id . ': ' . $subject);
            }
        }
    }

}
