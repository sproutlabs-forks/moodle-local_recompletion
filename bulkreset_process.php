<?php

require('../../config.php');
require_once($CFG->dirroot . '/local/recompletion/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');
require_once($CFG->libdir . '/completionlib.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->dirroot . '/mod/quiz/lib.php');

use local_recompletion\task\check_recompletion;

$facetofacesessionid = required_param('s', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
$selectedusers = optional_param_array('selectedusers', [], PARAM_BOOL);
$allusers = optional_param_array('allusers', [], PARAM_INT);
$session = $DB->get_record('facetoface_sessions', ['id' => $facetofacesessionid], '*', MUST_EXIST);
$facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
$courseid = $facetoface->course;
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
require_login($course);
require_sesskey();

global $CFG, $DB;
$context = context_course::instance($courseid);
require_capability('local/recompletion:resetfacetofacecompletion', $context);

$course = get_course($courseid);

// Get config for this course.
$config = $DB->get_records_menu('local_recompletion_config', ['course' => $courseid], '', 'name, value');
$config = (object)$config;


if (empty($config->enable)) {
    throw new moodle_exception('recompletionnotenabled', 'local_recompletion');
}


// Only users belonging to this Face-to-Face session may be reset by this endpoint.
$attendees = facetoface_get_attendees($facetofacesessionid);
$attendeesbyid = [];
foreach ($attendees as $attendee) {
    $attendeesbyid[$attendee->id] = $attendee;
}

$userids = [];
if ($action === 'bulkall') {
    foreach ($allusers as $userid) {
        $userids[] = $userid;
    }
} else if ($action === 'bulkselected') {
    foreach ($selectedusers as $userid => $selected) {
        if ($selected) {
            $userids[] = $userid;
        }
    }
} else {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

$users = [];
foreach (array_unique($userids) as $userid) {
    if (!isset($attendeesbyid[$userid])) {
        throw new moodle_exception('invaliduser');
    }
    $users[] = $attendeesbyid[$userid];
}
// Reset completion for each user.
$reset = new check_recompletion();
foreach ($users as $user) {
    $reset->reset_user($user->id, $course, $config);
}

// Redirect back with message.
redirect(
    new moodle_url('/course/view.php', ['id' => $courseid]),
    get_string('resetcomplete', 'local_recompletion'),

);
