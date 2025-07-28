<?php
require('../../config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

$facetofacesessionid = required_param('s', PARAM_INT);

$session = $DB->get_record('facetoface_sessions', ['id' => $facetofacesessionid], '*', MUST_EXIST);
$facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface], '*', MUST_EXIST);
$courseid = $facetoface->course;
$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
require_login($course);
$context = context_course::instance($courseid);
require_capability('local/recompletion:manage', $context);

$PAGE->set_url('/local/recompletion/bulkreset.php', ['id' => $facetofacesessionid]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('bulkreset', 'local_recompletion'));
$PAGE->set_heading(get_string('bulkreset', 'local_recompletion'));

echo $OUTPUT->header();

render_user_reset_table($facetofacesessionid, $courseid);

echo $OUTPUT->footer();


function render_user_reset_table($facetofacesessionid, $courseid)
{
    global $OUTPUT, $DB, $PAGE, $CFG;

    require_once($CFG->libdir . '/tablelib.php');
    $attendees = facetoface_get_attendees($facetofacesessionid);
    echo html_writer::start_tag('form', [
        'method' => 'post',
        'action' => new moodle_url('/local/recompletion/bulkreset_process.php', ['courseid' => $courseid]),
        'id' => 'bulkresetform'
    ]);

    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

    $table = new flexible_table('bulkuserresettbl');
    $table->define_columns(['select', 'fullname', 'email']);
    $table->define_headers([
        html_writer::checkbox('toggleall', 1, false, '', ['id' => 'toggleall']),
        'Full Name',
        'Email'
    ]);
    $table->define_baseurl($PAGE->url);
    $table->set_attribute('class', 'generaltable');
    $table->setup();

    foreach ($attendees as $user) {
        $userid = $user->id;

        // Checkbox for selected users.
        $checkbox = html_writer::checkbox("selectedusers[{$userid}]", 1, false, '', ['class' => 'usercheckbox']);

        // Hidden field for all users (used when bulkall is clicked).
        echo html_writer::empty_tag('input', [
            'type' => 'hidden',
            'name' => 'allusers[]',
            'value' => $userid
        ]);

        $fullname = fullname($user);
        $table->add_data([$checkbox, $fullname, $user->email]);
    }


    $table->finish_output();

    // Buttons
    echo html_writer::start_div('buttons', ['style' => 'margin-top: 20px;']);
    echo html_writer::tag('button', 'Bulk Reset (All)', ['type' => 'submit', 'name' => 'action', 'value' => 'bulkall', 'class' => 'btn btn-secondary']);
    echo html_writer::tag('button', 'Apply Reset (Selected)', ['type' => 'submit', 'name' => 'action', 'value' => 'bulkselected', 'class' => 'btn btn-primary', 'style' => 'margin-left:10px']);
    echo html_writer::tag('button', 'Cancel', [
        'type' => 'button',
        'onclick' => "window.location.href='" . new moodle_url('/course/view.php', ['id' => $courseid]) . "'",
        'class' => 'btn btn-link',
        'style' => 'margin-left:10px'
    ]);
    echo html_writer::end_div();

    echo html_writer::end_tag('form');

    // JS for toggle all
    $PAGE->requires->js_call_amd('core/checkbox-toggleall', 'enhance', ['bulkresetform', 'selectedusers']);
}
