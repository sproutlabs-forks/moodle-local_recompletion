<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Local functions and constants for recompletion plugin.
 *
 * @package    local_recompletion
 * @copyright  2018 Catalyst IT
 * @author     Dan Marsden
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

// Used by settings to decide if attempts should be deleted or an extra attempt allowed.
define('LOCAL_RECOMPLETION_NOTHING', 0);
define('LOCAL_RECOMPLETION_DELETE', 1);
define('LOCAL_RECOMPLETION_EXTRAATTEMPT', 2);

/**
 * Get list of supported activity classes.
 * @return array
 * @throws coding_exception
 */
function local_recompletion_get_supported_activities() {
    global $CFG;
    $activities = [];
    $files = scandir($CFG->dirroot. '/local/recompletion/classes/activities');
    foreach ($files as $file) {
        $activity = clean_param(str_replace('.php', '', $file), PARAM_ALPHA);
        if (!empty($activity) && file_exists($CFG->dirroot.'/mod/'.$activity)) {
            $activities[] = $activity;
        }

    }
    return $activities;
}

/**
 * Check whether a user currently has an active enrolment in a course.
 *
 * @param int $userid User id.
 * @param stdClass|int $course Course record or course id.
 * @return bool
 */
function local_recompletion_user_is_active_enrolled($userid, $course) {
    global $CFG, $DB;

    require_once($CFG->libdir . '/enrollib.php');

    if (is_object($course)) {
        $courseid = $course->id;
    } else {
        $courseid = $course;
    }

    $now = time();

    return $DB->record_exists_sql("
        SELECT 1
          FROM {user} u
          JOIN {user_enrolments} ue ON ue.userid = u.id
          JOIN {enrol} e ON e.id = ue.enrolid
         WHERE u.id = :userid
           AND u.deleted = 0
           AND u.suspended = 0
           AND e.courseid = :courseid
           AND e.status = :enrolenabled
           AND ue.status = :useractive
           AND (ue.timestart = 0 OR ue.timestart <= :now1)
           AND (ue.timeend = 0 OR ue.timeend > :now2)",
        [
            'userid' => $userid,
            'courseid' => $courseid,
            'enrolenabled' => ENROL_INSTANCE_ENABLED,
            'useractive' => ENROL_USER_ACTIVE,
            'now1' => $now,
            'now2' => $now,
        ]
    );
}
