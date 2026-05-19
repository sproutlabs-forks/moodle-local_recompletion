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
function import_completion_file(string $filepath): array
{
    global $DB;

    mtrace('Import completion file opened: ' . $filepath);
    $fh = fopen($filepath, 'r');
    $csv = [];
    $exceptions = []; // To collect exception rows

    if ($fh) {
        mtrace('Import completion file handle created.');
        $columns = fgetcsv($fh);

        if ($columns !== false) {
            $cleancolumns = [];

            foreach ($columns as $col) {
                $col = (string)$col;
                $col = preg_replace('/^\xEF\xBB\xBF/', '', $col);
                $col = trim($col);
                $col = core_text::strtolower($col);
                $cleancolumns[] = $col;
            }

            mtrace('Import completion columns: ' . implode(',', $cleancolumns));

            $expected = ['email', 'completed', 'courseid'];

            $missing = array_diff($expected, $cleancolumns);
            $extra = array_diff($cleancolumns, $expected);

            if (!empty($missing) || !empty($extra)) {
                fclose($fh);
                mtrace(
                    'Import completion invalid headers. Missing: ' . implode(',', $missing) .
                    ' Extra: ' . implode(',', $extra)
                );
                throw new coding_exception(
                    'Invalid CSV headers. Missing: ' . implode(',', $missing) .
                    ' Extra: ' . implode(',', $extra)
                );
            }

            $readrow = 1;
            while (($row = fgetcsv($fh)) !== false) {
                $readrow++;
                if (count($row) !== count($cleancolumns)) {
                    mtrace('Import completion skipped row ' . $readrow . ' due to column count mismatch.');
                    continue;
                }

                $csv[] = array_combine($cleancolumns, $row);
                if (count($csv) % 100 === 0) {
                    mtrace('Import completion loaded rows: ' . count($csv));
                }
            }
        } else {
            mtrace('Import completion file has no header row.');
        }

        fclose($fh);
        mtrace('Import completion file handle closed.');
    } else {
        mtrace('Import completion unable to open file: ' . $filepath);
    }

    mtrace('Import completion total rows loaded: ' . count($csv));
    $processedrows = 0;
    foreach ($csv as $row) {
        $processedrows++;
        $email = trim((string)($row['email'] ?? ''));
        $completed = trim((string)($row['completed'] ?? ''));
        $courseid = (int)trim((string)($row['courseid'] ?? ''));

        mtrace(
            'Import completion processing row ' . $processedrows .
            ': email=' . $email .
            ', courseid=' . $courseid .
            ', completed=' . $completed
        );

        if ($email === '' || $completed === '' || $courseid <= 0) {
            mtrace('Import completion skipped row ' . $processedrows . ' due to missing required values.');
            continue;
        }

        mtrace('Import completion looking up user for row ' . $processedrows . ': ' . $email);
        $users = $DB->get_records('user', ['email' => $email, 'deleted' => 0], '', 'id');
        mtrace('Import completion user records found for row ' . $processedrows . ': ' . count($users));

        if (count($users) !== 1) {
            mtrace('Import completion skipped row ' . $processedrows . ' because user count was not exactly one.');
            continue;
        }

        $user = reset($users);
        if (!$users) {
            $row['reason'] = 'User not found';
            $exceptions[] = $row;
            continue;
        }

        if (count($users) > 1) {
            $row['reason'] = 'Multiple users with same email';
            $exceptions[] = $row;
            continue;
        }

        $user = reset($users);

        mtrace('Import completion loading course context for row ' . $processedrows . ': courseid=' . $courseid);
        $context = context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            $row['reason'] = 'Invalid course ID';
            $exceptions[] = $row;
            mtrace('Import completion exception row ' . $processedrows . ': Invalid course ID.');
            continue;
        }

        mtrace(
            'Import completion checking enrolment for row ' . $processedrows .
            ': userid=' . $user->id .
            ', courseid=' . $courseid
        );
        if (!is_enrolled($context, $user->id)) {
            $row['reason'] = 'User not enrolled or may duplicate account exists.';
            $exceptions[] = $row;
            mtrace('Import completion exception row ' . $processedrows . ': User not enrolled.');
            continue;
        }

        mtrace('Import completion parsing completion date for row ' . $processedrows . ': ' . $completed);
        $dt = DateTime::createFromFormat('d/m/Y', $completed, new DateTimeZone('Australia/Hobart'));
        if (!$dt) {
            $row['reason'] = 'Invalid date format';
            $exceptions[] = $row;
            mtrace('Import completion exception row ' . $processedrows . ': Invalid date format.');
            continue;
        }

        $unixtimestamp = $dt->getTimestamp();
        mtrace(
            'Import completion marking row ' . $processedrows .
            ': userid=' . $user->id .
            ', courseid=' . $courseid .
            ', timestamp=' . $unixtimestamp
        );
        try {
            recompletion_mark_course_completion($user->id, $courseid, $unixtimestamp);
            mtrace('Import completion marked row ' . $processedrows . ' successfully.');
        } catch (Throwable $e) {
            mtrace('Import completion failed row ' . $processedrows . ': ' . $e->getMessage());
            throw $e;
        }
    }

    mtrace('Import completion finished. Exceptions: ' . count($exceptions));
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
    mtrace(
        'Recompletion mark start: userid=' . $userid .
        ', courseid=' . $courseid .
        ', timestamp=' . $unixtimestamp
    );

    $params = array(
        'userid' => $userid,
        'course' => $courseid
    );
    mtrace('Recompletion mark loading completion record.');
    $ccompletion = new \completion_completion($params);
    mtrace('Recompletion mark completion record loaded.');
    if ($ccompletion->is_complete()) {
        // If we already have a completion date, clear it first so that mark_complete works.
        mtrace('Recompletion mark existing completion found; clearing timecompleted before mark_complete.');
        $ccompletion->timecompleted = null;
    } else {
        mtrace('Recompletion mark existing completion not complete.');
    }
    mtrace('Recompletion mark calling mark_complete.');
    $ccompletion->mark_complete($unixtimestamp);
    mtrace('Recompletion mark mark_complete finished.');

    mtrace('Recompletion mark reloading completion record.');
    $ccompletion = new \completion_completion($params);
    mtrace('Recompletion mark reloaded completion record.');

    mtrace('Recompletion mark loading course record.');
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    mtrace('Recompletion mark loaded course record.');
    mtrace('Recompletion mark loading user record.');
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
    mtrace('Recompletion mark loaded user record.');

    // Trigger the course completed event manually.
    mtrace('Recompletion mark creating core course_completed event.');
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
    mtrace('Recompletion mark triggering core course_completed event.');
    $event->trigger();
    mtrace('Recompletion mark core course_completed event triggered.');

    mtrace('Recompletion mark creating local course_marked_complete_bulk event.');
    $event = \local_recompletion\event\course_marked_complete_bulk::create([
        'objectid' => $courseid,
        'relateduserid' => $userid,
        'context' => \context_course::instance($courseid),
        'other' => ['source' => 'bulk upload'],
    ]);
    mtrace('Recompletion mark triggering local course_marked_complete_bulk event.');
    $event->trigger();
    mtrace('Recompletion mark local course_marked_complete_bulk event triggered.');
    mtrace('Recompletion mark finished.');

}

function local_recompletion_extend_navigation(global_navigation $nav)
{
    global $PAGE;
    $url = $PAGE->url->out_as_local_url(false);
    if (strpos($url, '/mod/facetoface/attendees.php') !== false) {
        $PAGE->requires->js_call_amd('local_recompletion/injectbulkreset', 'init');
    }
}
