<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/lib/formslib.php');
require_once(__DIR__ . '/lib.php');

class bulkupload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'userfile', get_string('file'), null, ['accepted_types' => '.csv']);
        $mform->addRule('userfile', null, 'required');
        $this->add_action_buttons(false, get_string('uploadusers', 'local_recompletion'));
    }
}

// Require login
require_login();

// Setup page
$PAGE->set_url(new moodle_url('/local/recompletion/bulkupload.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin'); // Default admin layout
$PAGE->set_title('Bulk Completion Upload');
$PAGE->set_heading('Bulk Completion Upload');

// Instantiate form
$mform = new bulkupload_form();

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/'));
} else if ($data = $mform->get_data()) {
    $user_context = context_user::instance($USER->id);
    $draftitemid = file_get_submitted_draft_itemid('userfile');
    $area_files = get_file_storage()->get_area_files($user_context->id, 'user', 'draft', $draftitemid, null, false);

    if (!empty($area_files)) {
        $file = array_shift($area_files);
        $result = import_completion_file($file);

        echo $OUTPUT->header();
        echo $OUTPUT->heading('Upload Results');
        echo $OUTPUT->notification($result, 'notifysuccess');
        echo $OUTPUT->continue_button(new moodle_url('/local/recompletion/bulkupload.php'));
        echo $OUTPUT->footer();
        exit;
    }
}

// Display upload form
echo $OUTPUT->header();
echo $OUTPUT->heading('Bulk Completion Upload');
$mform->display();
echo $OUTPUT->footer();
