<?php
require_once(__DIR__ . '/../../config.php');
require_login();

$filename = clean_param(required_param('file', PARAM_FILE), PARAM_FILE);
$filepath = $CFG->dataroot . '/local_recompletion/bulkuploads/toprocess/' . $filename;

if (!preg_match('/^[a-zA-Z0-9_\- %\.]+\.csv$/', $filename) || !file_exists($filepath)) {
    throw new moodle_exception('Invalid file');
}

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));

readfile($filepath);
exit;
