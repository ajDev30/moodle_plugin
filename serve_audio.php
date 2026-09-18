<?php
require_once(__DIR__ . '/../../config.php');

require_login();
if (isguestuser()) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}
$is_admin = is_siteadmin();
$is_teacher = $DB->record_exists_sql("
    SELECT 1 FROM {role_assignments} ra 
    JOIN {role} r ON ra.roleid = r.id 
    WHERE ra.userid = ? AND r.shortname IN ('editingteacher', 'teacher', 'manager')
", [$USER->id]);
if (!$is_admin && !$is_teacher) {
    header('HTTP/1.0 403 Forbidden');
    exit;
}

$id = required_param('id', PARAM_INT);
$ext = optional_param('ext', 'webm', PARAM_ALPHANUM);

$audio_dir = $CFG->dataroot . '/readingassessment_audio';
$file = $audio_dir . '/attempt_' . $id . '.' . $ext;

if (!file_exists($file)) {
    // Check if it exists with .mp4 or .ogg
    $file_mp4 = $audio_dir . '/attempt_' . $id . '.mp4';
    if (file_exists($file_mp4)) {
        $file = $file_mp4;
        $ext = 'mp4';
    } else {
        header('HTTP/1.0 404 Not Found');
        echo "Audio not found.";
        exit;
    }
}

$mime = ($ext === 'mp4') ? 'audio/mp4' : 'audio/webm';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
readfile($file);
