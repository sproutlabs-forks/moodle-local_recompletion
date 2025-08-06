<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->dirroot . '/lib/formslib.php');

class bulkupload_form extends moodleform {
    public function definition() {
        $mform = $this->_form;
        $mform->addElement('filepicker', 'userfile', get_string('file'), null, ['accepted_types' => ['.csv']]);
        $mform->addRule('userfile', null, 'required');
        $this->add_action_buttons(false, get_string('uploadusers', 'local_recompletion'));
    }
}

require_login();

$PAGE->set_url(new moodle_url('/local/recompletion/bulkupload.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Bulk Completion Upload');
$PAGE->set_heading('Bulk Completion Upload');

$mform = new bulkupload_form();

$toprocessdir = $CFG->dataroot . '/local_recompletion/bulkuploads/toprocess';
$processeddir = $CFG->dataroot . '/local_recompletion/bulkuploads/processed';

// Ensure folders exist
if (!file_exists($toprocessdir)) {
    mkdir($toprocessdir, $CFG->directorypermissions, true);
}
if (!file_exists($processeddir)) {
    mkdir($processeddir, $CFG->directorypermissions, true);
}

// File Upload Handler
if ($mform->is_cancelled()) {
    redirect(new moodle_url('/'));
} else if ($data = $mform->get_data()) {
    $user_context = context_user::instance($USER->id);
    $draftitemid = file_get_submitted_draft_itemid('userfile');
    $area_files = get_file_storage()->get_area_files($user_context->id, 'user', 'draft', $draftitemid, null, false);

    if (!empty($area_files)) {
        $file = array_shift($area_files);
        $filename = $file->get_filename();
        $content = $file->get_content();

        // Save with unique name
        $unique = time() . '_' . rand(1000, 9999) . '_' . clean_param($filename, PARAM_FILE);
        $storedpath = "$toprocessdir/$unique";
        file_put_contents($storedpath, $content);

        redirect(new moodle_url('/local/recompletion/bulkupload.php', ['uploaded' => 1]));
    }
}

// Render page
echo $OUTPUT->header();
echo $OUTPUT->heading('Bulk Completion Upload');

// Show success notification
if (optional_param('uploaded', 0, PARAM_INT)) {
    echo $OUTPUT->notification('File uploaded successfully and queued for processing.', 'notifysuccess');
}

// Show upload form
$mform->display();

// Show list of unprocessed files
echo html_writer::tag('h3', 'Unprocessed Files');

$files = array_diff(scandir($toprocessdir), ['.', '..']);

if (empty($files)) {
    echo html_writer::div('No unprocessed files.');
} else {
    echo html_writer::start_tag('ul');
    foreach ($files as $file) {
        $downloadurl = new moodle_url('/local/recompletion/downloadfile.php', ['file' => $file]);
        echo html_writer::tag('li', html_writer::link($downloadurl, $file));
    }
    echo html_writer::end_tag('ul');
}

echo $OUTPUT->footer();
