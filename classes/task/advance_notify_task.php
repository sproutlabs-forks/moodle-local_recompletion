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

        $siteconfig = get_config('local_recompletion');
        $courses = $DB->get_records_sql("
    SELECT DISTINCT c.id, c.fullname
      FROM {course} c
      JOIN {local_recompletion_config} cfg
        ON cfg.course = c.id
       AND cfg.name = :cfgname
       AND cfg.value = :cfgval
     WHERE c.enablecompletion = :enabled",
            ['cfgname' => 'enableleadnotify', 'cfgval' => '1', 'enabled' => COMPLETION_ENABLED]
        );

        foreach ($courses as $course) {
            $courseconfig = (object)$DB->get_records_menu(
                'local_recompletion_config',
                ['course' => $course->id],
                '',
                'name,value'
            );

            $duration = (int)($courseconfig->recompletionduration ?? $siteconfig->duration);
            $leadseconds = (int)($courseconfig->notifyleadtime ?? 0) * DAYSECS;

            $sql = "
            SELECT cc.userid, cc.course, cc.timecompleted
              FROM {course_completions} cc
         LEFT JOIN {local_recompletion_notify} n
                ON n.userid = cc.userid
               AND n.courseid = cc.course
             WHERE cc.course = :courseid
               AND cc.timecompleted > 0
               AND n.id IS NULL
               AND (
                    ((cc.timecompleted + :duration1) - :leadtime) <= :now1
                    OR
                    (cc.timecompleted + :duration2) <= :now2
               )
        ";

            $now = time();
            $params = [
                'courseid' => $course->id,
                'duration1' => $duration,
                'duration2' => $duration,
                'leadtime' => $leadseconds,
                'now1' => $now,
                'now2' => $now,
            ];

            $coursecompletions = $DB->get_records_sql($sql, $params);

            foreach ($coursecompletions as $cc) {
                if ($DB->record_exists('local_recompletion_notify', ['userid' => $cc->userid, 'courseid' => $course->id])) {
                    continue;
                }

                if (!local_recompletion_user_is_active_enrolled($cc->userid, $course)) {
                    continue;
                }

                $this->send_advance_notification($cc->userid, $course, $siteconfig, $courseconfig);

                $DB->insert_record('local_recompletion_notify', [
                    'userid' => $cc->userid,
                    'courseid' => $course->id,
                    'emailsent' => 1,
                    'timesent' => time(),
                    'param1' => null,
                    'param2' => null,
                    'param3' => null,
                    'param4' => null,
                    'param5' => null,
                ]);
            }
        }
    }


    protected function send_advance_notification($userid, $course, $config, $courseconfig)
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
        $a->leadtime = $courseconfig->notifyleadtime;

        $bodytemplate = $config->default_notify_message ?? '';
        $subjecttemplate = $config->default_notify_subject ?? '';

        $replacements = [
            '{$a->coursename}' => $a->coursename,
            '{$a->profileurl}' => $a->profileurl,
            '{$a->link}' => $a->link,
            '{$a->fullname}' => $a->fullname,
            '{$a->email}' => $a->email,
            '{$a->leadtime}' => $a->leadtime
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
