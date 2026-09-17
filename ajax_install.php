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

$azure_key    = get_config('readingassessment', 'azure_speech_key') ?: '';
$azure_region = get_config('readingassessment', 'azure_speech_region') ?: 'southeastasia';
$os           = get_config('readingassessment', 'operating_system') ?: 'linux';
$port         = get_config('readingassessment', 'service_port') ?: 8010;

$python_path = __DIR__ . DIRECTORY_SEPARATOR . 'venv_asr';

// Step 1: Create direct virtual environment (venv_asr/ IS the virtual environment)
if ($os === 'windows') {
    $cmd = "python -m venv \"{$python_path}\" 2>&1";
} else {
    $cmd = "(python3 -m venv \"{$python_path}\" || virtualenv \"{$python_path}\") 2>&1";
}
$output_venv = shell_exec($cmd);

// Step 2: Copy template package files directly into venv_asr/
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

// Step 3: Write .env file directly inside venv_asr/
$env_file = rtrim($python_path, '/\\') . DIRECTORY_SEPARATOR . '.env';
$env_content = "OPENAI_API_KEY=\"{$apikey}\"\nPORT={$port}\n";
@file_put_contents($env_file, $env_content);
@chmod($env_file, 0777);

// Step 4: Install pip requirements directly inside venv_asr/
if ($os === 'windows') {
    $pip_cmd = "\"{$python_path}\\Scripts\\pip\" install --no-input -r \"{$python_path}\\requirements.txt\" 2>&1";
} else {
    $pip_cmd = "\"{$python_path}/bin/pip\" install --no-input -r \"{$python_path}/requirements.txt\" && chmod -R 777 \"{$python_path}\" 2>&1";
}
$output_pip = shell_exec($pip_cmd);

// Flush stat cache
clearstatcache();

// Step 5: Strict Filesystem Verification directly inside venv_asr/
$python_exe = ($os === 'windows') ? "{$python_path}\\Scripts\\python.exe" : "{$python_path}/bin/python";
$service_file = $python_path . DIRECTORY_SEPARATOR . 'service.py';

$has_dir = is_dir($python_path);
$has_service = file_exists($service_file);
$has_env = file_exists($env_file);
$has_python = file_exists($python_exe);

$total_output = "VENV Creation:\n" . $output_venv . "\nPIP Install:\n" . $output_pip;

if ($has_dir && $has_service && $has_env && $has_python) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Installation Successful! venv_asr is now a direct Python environment.',
        'log'     => "Filesystem Verification Passed:\n - Direct Environment: mod/readingassessment/venv_asr/ [READY]\n - Python Executable: {$python_exe} [EXISTS]\n - service.py [EXISTS]\n - .env [EXISTS]\n\nTerminal Output:\n" . s($total_output)
    ]);
} else {
    $missing = [];
    if (!$has_dir) $missing[] = 'venv_asr directory';
    if (!$has_python) $missing[] = "Python binary at {$python_exe}";
    if (!$has_service) $missing[] = 'service.py';
    if (!$has_env) $missing[] = '.env file';

    $missing_str = implode(', ', $missing);
    echo json_encode([
        'status'  => 'failed',
        'message' => "Installation Failed: Missing {$missing_str}",
        'log'     => "Verification failed. Terminal Output:\n" . s($total_output)
    ]);
}
exit;
