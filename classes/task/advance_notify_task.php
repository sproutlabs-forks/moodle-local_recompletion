<?php
// File: classes/task/advance_notify_task.php

namespace local_recompletion\task;

use core\task\scheduled_task;
use context_course;
use moodle_exception;
use stdClass;

/**
 * Scheduled task to send advance notifications to learners before recompletion.
 */
class advance_notify_task extends scheduled_task
{

    public function get_name()
    {
        return get_string('advancenotifytask', 'local_recompletion');
    }

    public function execute()
    {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/local/recompletion/locallib.php');
        require_once($CFG->libdir . '/completionlib.php');
        $now = time();


        $courses = $DB->get_records_sql("
    SELECT c.id, c.fullname
    FROM {course} c
    JOIN {local_recompletion_config} cfg1 
      ON cfg1.course = c.id AND cfg1.name = 'enable' AND cfg1.value = '1'
    WHERE c.enablecompletion = :enabled", ['enabled' => COMPLETION_ENABLED]);


        foreach ($courses as $course) {
            print_r($course);
            $config = (object)$DB->get_records_menu(
                'local_recompletion_config',
                ['course' => $course->id],
                '',
                'name,value'
            );

            $now = time();
            $duration = (int)$config->recompletionduration;       // seconds (e.g. 100 * 86400)
            $leadseconds = (int)$config->notifyleadtime * 86400;  // lead time in seconds

            $sql = "
    SELECT userid, course, timecompleted
    FROM {course_completions}
    WHERE course = :courseid
      AND timecompleted > 0
      AND (
            ((timecompleted + :duration1) - :leadtime) <= :now1
            OR
            (timecompleted + :duration2) <= :now2
      )
";

            $now = time();

            $params = [
                'courseid' => $course->id,
                'duration1' => $duration,     // recompletion duration in seconds
                'duration2' => $duration,     // recompletion duration in seconds
                'leadtime' => $leadseconds,      // lead time in seconds
                'now1' => $now,
                'now2' => $now,
            ];


            $course_completions = $DB->get_records_sql($sql, $params);
            print_r($course_completions);


            foreach ($course_completions as $cc) {
                // each $cc is due for a reminder today
                $this->send_advance_notification($cc->userid, $course, $config);
            }

        }

    }

    protected function send_advance_notification($userid, $course, $config)
    {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['id' => $userid]);
        if (!$user) {
            return;
        }

        $context = context_course::instance($course->id);
        $from = get_admin();

        $a = new stdClass();
        $a->coursename = format_string($course->fullname, true, ['context' => $context]);
        $a->profileurl = "$CFG->wwwroot/user/view.php?id=$user->id&course=$course->id";
        $a->link = "$CFG->wwwroot/course/view.php?id=$course->id";
        $a->fullname = fullname($user);
        $a->email = $user->email;

        $bodytemplate = $config->notifymessagebody ?? '';
        $subjecttemplate = $config->notifysubject ?? '';

        $replacements = [
            '{$a->coursename}' => $a->coursename,
            '{$a->profileurl}' => $a->profileurl,
            '{$a->link}' => $a->link,
            '{$a->fullname}' => $a->fullname,
            '{$a->email}' => $a->email
        ];

        $message = strtr($bodytemplate, $replacements);
        $subject = strtr($subjecttemplate, $replacements);

        if (strpos($message, '<') === false) {
            $messagetext = $message;
            $messagehtml = text_to_html($message);
        } else {
            $messagehtml = format_text($message, FORMAT_HTML, ['context' => $context]);
            $messagetext = html_to_text($messagehtml);
        }

        email_to_user($user, $from, $subject, $messagetext, $messagehtml);
    }
}
