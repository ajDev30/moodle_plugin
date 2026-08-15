<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

// Authenticate administrator
require_login();
require_sesskey();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

@set_time_limit(300);
header('Content-Type: application/json; charset=utf-8');

$apikey = get_config('readingassessment', 'openai_apikey');
$os = get_config('readingassessment', 'operating_system') ?: 'linux';
$port = get_config('readingassessment', 'service_port') ?: 8000;

$python_path = __DIR__ . DIRECTORY_SEPARATOR . 'venv_asr';

// Step 1: Create internal target directory with full permissions
if (!is_dir($python_path)) {
    @mkdir($python_path, 0777, true);
}
@chmod($python_path, 0777);

// Step 2: Copy template package files
$source_dir = __DIR__ . '/templates_asr';
$files_to_copy = ['service.py', 'requirements.txt', 'setup_linux.sh', 'run_linux.sh', 'setup_windows.bat', 'run_windows.bat'];
foreach ($files_to_copy as $file) {
    $src_file = rtrim($source_dir, '/\\') . DIRECTORY_SEPARATOR . $file;
    $dst_file = rtrim($python_path, '/\\') . DIRECTORY_SEPARATOR . $file;
    if (file_exists($src_file)) {
        @copy($src_file, $dst_file);
        @chmod($dst_file, 0777);
    }
}

// Step 3: Write .env file
$env_file = rtrim($python_path, '/\\') . DIRECTORY_SEPARATOR . '.env';
$env_content = "OPENAI_API_KEY=\"{$apikey}\"\nPORT={$port}\n";
@file_put_contents($env_file, $env_content);
@chmod($env_file, 0777);

// Step 4: Execute non-interactive virtual environment creation & pip install
if ($os === 'windows') {
    $cmd = "cd /d \"{$python_path}\" && python -m venv venv && call venv\\Scripts\\activate.bat && pip install --no-input -r requirements.txt 2>&1";
} else {
    $cmd = "cd \"{$python_path}\" && (python3 -m venv venv || virtualenv venv) && ./venv/bin/pip install --no-input -r requirements.txt 2>&1";
}

$output = shell_exec($cmd);

// Flush stat cache so file_exists detects freshly written files
clearstatcache();

// Step 5: Strict Filesystem Verification
$venv_dir = $python_path . DIRECTORY_SEPARATOR . 'venv';
$service_file = $python_path . DIRECTORY_SEPARATOR . 'service.py';

$has_dir = is_dir($python_path);
$has_service = file_exists($service_file);
$has_env = file_exists($env_file);
$has_venv = is_dir($venv_dir);

if ($has_dir && $has_service && $has_env && $has_venv) {
    echo json_encode([
        'status'  => 'success',
        'message' => '✅ Installation Successful!',
        'log'     => "Filesystem Verification Passed:\n - Directory mod/readingassessment/venv_asr/ [EXISTS]\n - service.py [EXISTS]\n - .env [EXISTS]\n - venv/ [EXISTS]\n\nTerminal Output:\n" . s($output)
    ]);
} else {
    $missing = [];
    if (!$has_dir) $missing[] = 'venv_asr directory';
    if (!$has_service) $missing[] = 'service.py';
    if (!$has_env) $missing[] = '.env credentials file';
    if (!$has_venv) $missing[] = 'venv Python virtual environment';

    $missing_str = implode(', ', $missing);
    echo json_encode([
        'status'  => 'failed',
        'message' => "❌ Installation Failed: Missing {$missing_str}",
        'log'     => "Verification failed. Terminal Output:\n" . s($output)
    ]);
}
exit;
