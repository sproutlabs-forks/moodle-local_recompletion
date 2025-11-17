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
 * General functions for recompletion plugin.
 *
 * @package    local_recompletion
 * @copyright  2017 Dan Marsden
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

/**
 * This function extends the navigation with the recompletion item
 *
 * @param navigation_node $navigation The navigation node to extend
 * @param stdClass $course The course to object for the tool
 * @param context $context The context of the course
 */
function import_completion_file(string $filepath): array {
    global $DB;

    $fh = fopen($filepath, 'r');
    $csv = [];
    $exceptions = []; // To collect exception rows

    if ($fh) {
        $columns = fgetcsv($fh); // read header
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) !== count($columns)) {
                continue; // skip bad row
            }
            $csv[] = array_combine($columns, $row);
        }
        fclose($fh);
    }

    foreach ($csv as $row) {
        $email = trim($row['email']);
        $completed = trim($row['completed']);
        $courseid = trim($row['courseid']);

        $user = $DB->get_record('user', ['email' => $email, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$user) {
            $row['reason'] = 'User not found';
            $exceptions[] = $row;
            continue;
        }

        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            $row['reason'] = 'Invalid course ID';
            $exceptions[] = $row;
            continue;
        }

        if (!is_enrolled($context, $user->id)) {
            $row['reason'] = 'User not enrolled';
            $exceptions[] = $row;
            continue;
        }

        $dt = DateTime::createFromFormat('d/m/Y', $completed, new DateTimeZone('Australia/Hobart'));
        if (!$dt) {
            $row['reason'] = 'Invalid date format';
            $exceptions[] = $row;
            continue;
        }

        $unixtimestamp = $dt->getTimestamp();
        recompletion_mark_course_completion($user->id, $courseid, $unixtimestamp);
    }

    return $exceptions;
}



function local_recompletion_extend_navigation_course($navigation, $course, $context)
{
    global $DB;
    $completion = new completion_info($course);
    if (!$completion->is_enabled()) {
        return;
    }

    if (has_capability('local/recompletion:resetmycompletion', $context)) {
        $enabled = $DB->get_field('local_recompletion_config', 'value', ['name' => 'enable', 'course' => $course->id]);
        if (!empty($enabled)) {
            $url = new moodle_url('/local/recompletion/resetcompletion.php', array('id' => $course->id));
            $name = get_string('resetmycompletion', 'local_recompletion');
            $navigation->add($name, $url, navigation_node::TYPE_SETTING, null, null, new pix_icon('i/settings', ''));
        }
    }

    if (has_capability('local/recompletion:manage', $context)) {
        $url = new moodle_url('/local/recompletion/recompletion.php', array('id' => $course->id));
        $name = get_string('pluginname', 'local_recompletion');
        $navigation->add($name, $url, navigation_node::TYPE_SETTING, null, null, new pix_icon('i/settings', ''));

        $url = new moodle_url('/local/recompletion/participants.php', array('id' => $course->id));
        $name = get_string('modifycompletiondates', 'local_recompletion');
        $navigation->add($name, $url, navigation_node::TYPE_SETTING, null, null, new pix_icon('i/settings', ''));


    }
}

function recompletion_mark_course_completion($userid, $courseid, $unixtimestamp)
{
    global $CFG, $DB; // 👈 This is required to use $CFG inside the function
    require_once($CFG->dirroot . '/completion/completion_completion.php');
//    print_r($userid . PHP_EOL);
//    print_r($courseid . PHP_EOL);
//    print_r($unixtimestamp . PHP_EOL);
    $params = array(
        'userid' => $userid,
        'course' => $courseid
    );
    $ccompletion = new \completion_completion($params);
    if ($ccompletion->is_complete()) {
        // If we already have a completion date, clear it first so that mark_complete works.
        $ccompletion->timecompleted = null;
    }
    $ccompletion->mark_complete($unixtimestamp);
    $ccompletion = new \completion_completion($params);
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    // Trigger the course completed event manually.
    $event = \core\event\course_completed::create([
        'objectid' => $courseid,
        'relateduserid' => $userid,
        'context' => context_course::instance($courseid),
        'other' => [
            'relateduserid' => $userid,
            'completiondate' => $unixtimestamp,
            'source' => 'local_recompletion_bulk' // your custom data
        ]
    ]);
    $event->trigger();

    $event = \local_recompletion\event\course_marked_complete_bulk::create([
        'objectid' => $courseid,
        'relateduserid' => $userid,
        'context' => \context_course::instance($courseid),
        'other' => ['source' => 'bulk upload'],
    ]);
    $event->trigger();

}

function local_recompletion_extend_navigation(global_navigation $nav)
{
    global $PAGE;
    $url = $PAGE->url->out_as_local_url(false);
    if (strpos($url, '/mod/facetoface/attendees.php') !== false) {
        $PAGE->requires->js_call_amd('local_recompletion/injectbulkreset', 'init');
    }
}

