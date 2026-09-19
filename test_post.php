<?php
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
try {
    redirect(new moodle_url('/'), 'Test.', null, \core\output\notification::NOTIFY_SUCCESS);
} catch (Exception $e) {
    echo $e->getMessage() . "\n";
}
