<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// Standard Moodle Admin Authentication & Authorization
require_login();
$context = context_system::instance();
require_capability('moodle/site:config', $context);

$action = optional_param('action', '', PARAM_ALPHA);

$apikey = get_config('readingassessment', 'openai_apikey');
$os = get_config('readingassessment', 'operating_system') ?: 'linux';
$port = get_config('readingassessment', 'service_port') ?: 8000;

// Hardcode internal environment path inside mod/readingassessment/venv_asr
$python_path = __DIR__ . DIRECTORY_SEPARATOR . 'venv_asr';

// Helper to check if service is running on port
function is_asr_service_running($host = '127.0.0.1', $port = 8000) {
    $connection = @fsockopen($host, $port, $errno, $errstr, 1.5);
    if (is_resource($connection)) {
        fclose($connection);
        return true;
    }
    return false;
}

// Detect WSL environment
function is_wsl_environment() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') return false;
    if (file_exists('/proc/version')) {
        $ver = @file_get_contents('/proc/version');
        if (stripos($ver, 'microsoft') !== false || stripos($ver, 'wsl') !== false) {
            return true;
        }
    }
    return false;
}

$is_wsl = is_wsl_environment();

$message = '';
$msg_type = 'info';

// Synchronize .env file with API key setting if directory exists
if (!empty($apikey) && is_dir($python_path)) {
    $env_file = rtrim($python_path, '/\\') . DIRECTORY_SEPARATOR . '.env';
    @file_put_contents($env_file, "OPENAI_API_KEY=\"{$apikey}\"\nPORT={$port}\n");
}

// Handle Admin Actions (Start/Stop)
if ($action && confirm_sesskey()) {
    if ($action === 'start') {
        if (!is_asr_service_running('127.0.0.1', $port)) {
            $env_file = rtrim($python_path, '/\\') . DIRECTORY_SEPARATOR . '.env';
            @file_put_contents($env_file, "OPENAI_API_KEY=\"{$apikey}\"\nPORT={$port}\n");

            if ($os === 'windows') {
                $cmd = "start /b cmd /c \"cd /d \"{$python_path}\" && run_windows.bat\"";
                pclose(popen($cmd, "r"));
            } else {
                $cmd = "nohup \"{$python_path}/run_linux.sh\" > \"{$python_path}/log.txt\" 2>&1 &";
                exec($cmd);
            }
            sleep(2);
            $message = "ASR Service start command issued.";
            $msg_type = 'success';
        } else {
            $message = "ASR Service is already running on port {$port}.";
            $msg_type = 'warning';
        }

    } else if ($action === 'stop') {
        if ($os === 'windows') {
            exec("for /f \"tokens=5\" %a in ('netstat -aon ^| findstr :{$port}') do taskkill /f /pid %a");
        } else {
            exec("fuser -k {$port}/tcp 2>&1 || pkill -f service.py 2>&1");
        }
        sleep(1);
        $message = "ASR Service stop command issued.";
        $msg_type = 'success';
    }
}

$is_running = is_asr_service_running('127.0.0.1', $port);

$PAGE->set_url('/mod/readingassessment/admin.php');
$PAGE->set_title(get_string('admin_dashboard', 'mod_readingassessment'));
$PAGE->set_heading(get_string('admin_dashboard', 'mod_readingassessment'));
$PAGE->set_context($context);

$PAGE->requires->css('/mod/readingassessment/styles.css');

// Build Admin Navbar
$PAGE->navbar->add(get_string('administrationsite'));
$PAGE->navbar->add(get_string('plugins', 'admin'));
$PAGE->navbar->add(get_string('activitymodules', 'admin'));
$PAGE->navbar->add(get_string('pluginname', 'mod_readingassessment'), new moodle_url('/admin/settings.php?section=modsettingreadingassessment'));
$PAGE->navbar->add(get_string('admin_dashboard', 'mod_readingassessment'));

echo $OUTPUT->header();

echo $OUTPUT->heading(get_string('admin_dashboard', 'mod_readingassessment'));

if ($message) {
    echo $OUTPUT->notification($message, $msg_type);
}

?>

<div class="reading-assessment-container">

    <!-- Environment Detection Banner -->
    <div style="padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1; display: flex; align-items: center; justify-content: space-between;">
        <div>
            <?php if ($is_wsl): ?>
                <strong>🐧 Host Environment Detected: WSL (Windows Subsystem for Linux)</strong><br/>
                <span style="font-size: 0.9rem;">Moodle is running inside WSL. The internal Python environment is managed natively in Linux/WSL.</span>
            <?php elseif (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'): ?>
                <strong>🪟 Host Environment Detected: Native Windows (XAMPP / WAMP)</strong><br/>
                <span style="font-size: 0.9rem;">Moodle is running on native Windows. The internal Python environment is managed via Windows Command Prompt.</span>
            <?php else: ?>
                <strong>🐧 Host Environment Detected: Native Linux</strong><br/>
                <span style="font-size: 0.9rem;">Moodle is running on native Linux.</span>
            <?php endif; ?>
        </div>
        <span style="font-size: 0.85rem; font-weight: bold; background: #ffffff; padding: 4px 10px; border-radius: 12px; border: 1px solid #93c5fd;">
            Internal VENV Mode
        </span>
    </div>

    <div class="ra-card">
        <div class="ra-card-title">🖥️ Service Status & Control Panel</div>

        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px; background: #f8fafc; border-radius: 8px; margin-bottom: 20px;">
            <div>
                <strong>Current Status:</strong>
                <?php if ($is_running): ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; background: #dcfce7; color: #16a34a; font-weight: bold; margin-left: 8px;">
                        🟢 Running (Port <?php echo $port; ?>)
                    </span>
                <?php else: ?>
                    <span style="display: inline-block; padding: 4px 12px; border-radius: 12px; background: #fee2e2; color: #dc2626; font-weight: bold; margin-left: 8px;">
                        🔴 Stopped
                    </span>
                <?php endif; ?>
            </div>

            <div style="display: flex; gap: 10px;">
                <?php if (!$is_running): ?>
                    <a href="admin.php?action=start&sesskey=<?php echo sesskey(); ?>" class="ra-btn ra-btn-start">
                        ▶ Start ASR Service
                    </a>
                <?php else: ?>
                    <a href="admin.php?action=stop&sesskey=<?php echo sesskey(); ?>" class="ra-btn ra-btn-miscue" style="background-color: #dc2626; color: white;">
                        ⏹ Stop ASR Service
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div style="margin-bottom: 20px; font-size: 0.95rem; color: #475569;">
            <strong>Configuration Summary:</strong>
            <ul>
                <li>Selected OS: <code><?php echo s(strtoupper($os)); ?></code></li>
                <li>Internal Python Path: <code>mod/readingassessment/venv_asr</code> <?php echo (is_dir($python_path) && file_exists($python_path . '/service.py') && is_dir($python_path . '/venv')) ? '<span style="color:#16a34a;">(Created & Validated)</span>' : '<span style="color:#dc2626;">(Not Created - Click Install below)</span>'; ?></li>
                <li>OpenAI API Key: <code><?php echo !empty($apikey) ? 'Configured (sk-...' . substr($apikey, -4) . ')' : 'Not Configured'; ?></code></li>
            </ul>
            <a href="<?php echo $CFG->wwwroot; ?>/admin/settings.php?section=modsettingreadingassessment" class="btn btn-secondary btn-sm">
                ⚙️ Edit Configuration Settings
            </a>
        </div>

        <hr/>

        <div style="margin-top: 20px;">
            <h4>🛠️ Embedded Environment & Dependencies Installer</h4>
            <p>Click below to automatically build the internal Python virtual environment inside <code>mod/readingassessment/venv_asr</code> and install required packages (<code>aiohttp</code>, <code>openai</code>, <code>jellyfish</code>) for your operating system.</p>

            <button id="ra-install-btn" class="ra-btn ra-btn-retry" onclick="startInstallation();">
                📦 Install / Create Python Environment
            </button>
        </div>

        <!-- Please Wait & Verification Badge Wrapper -->
        <div id="ra-pleasewait-wrapper" style="display:none; margin-top:20px; padding:20px; background:#f8fafc; border-radius:8px; border:1px solid #cbd5e1;">
            <div id="ra-loading-badge" style="padding:16px; border-radius:8px; background:#eff6ff; border:1px solid #bfdbfe; color:#1d4ed8; font-weight:bold; font-size:1.1rem; display:flex; align-items:center; gap:12px;">
                <span id="ra-spinner" class="spinner-border spinner-border-sm" role="status" style="width: 1.2rem; height: 1.2rem; border: 3px solid currentColor; border-right-color: transparent; border-radius: 50%; display: inline-block; animation: spinner-border .75s linear infinite;"></span>
                <span id="ra-pleasewait-text">⏳ Please wait while the Python environment and dependencies are being created...</span>
            </div>
            <pre id="ra-progress-console" class="ra-progress-console" style="margin-top:12px;"></pre>
        </div>
    </div>
</div>

<script>
async function startInstallation() {
    const installBtn = document.getElementById("ra-install-btn");
    const wrapper = document.getElementById("ra-pleasewait-wrapper");
    const loadingBadge = document.getElementById("ra-loading-badge");
    const spinner = document.getElementById("ra-spinner");
    const statusText = document.getElementById("ra-pleasewait-text");
    const consoleBox = document.getElementById("ra-progress-console");

    installBtn.disabled = true;
    wrapper.style.display = "block";
    wrapper.scrollIntoView({ behavior: "smooth", block: "center" });

    loadingBadge.style.background = "#eff6ff";
    loadingBadge.style.borderColor = "#bfdbfe";
    loadingBadge.style.color = "#1d4ed8";
    if (spinner) spinner.style.display = "inline-block";

    statusText.textContent = "⏳ Please wait while the Python environment and dependencies are being created...";
    consoleBox.textContent = "Requesting installation from server...\n";

    const url = "ajax_install.php?sesskey=<?php echo sesskey(); ?>";

    try {
        const response = await fetch(url);
        const text = await response.text();

        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error("Non-JSON Response received:", text);
            throw new Error("Server returned non-JSON response: " + text.substring(0, 100));
        }

        if (data.log) {
            consoleBox.textContent = data.log;
        }

        if (data.status === "success") {
            if (spinner) spinner.style.display = "none";
            loadingBadge.style.background = "#dcfce7";
            loadingBadge.style.borderColor = "#86efac";
            loadingBadge.style.color = "#15803d";
            statusText.textContent = data.message || "✅ Installation Successful!";
            setTimeout(() => window.location.reload(), 1500);
        } else {
            if (spinner) spinner.style.display = "none";
            loadingBadge.style.background = "#fee2e2";
            loadingBadge.style.borderColor = "#fca5a5";
            loadingBadge.style.color = "#b91c1c";
            statusText.textContent = data.message || "❌ Installation Failed";
            installBtn.disabled = false;
        }
    } catch (err) {
        console.error("Installation error:", err);
        if (spinner) spinner.style.display = "none";
        statusText.textContent = "❌ Installation error: " + err.message;
        loadingBadge.style.background = "#fee2e2";
        loadingBadge.style.borderColor = "#fca5a5";
        loadingBadge.style.color = "#b91c1c";
        installBtn.disabled = false;
    }
}
</script>

<?php
echo $OUTPUT->footer();
