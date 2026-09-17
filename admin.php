<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('managemodules');
require_login();
require_capability('moodle/site:config', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$os = get_config('readingassessment', 'operating_system') ?: 'linux';
$port = get_config('readingassessment', 'service_port') ?: 8010;
$azure_key    = get_config('readingassessment', 'azure_speech_key') ?: '';
$azure_region = get_config('readingassessment', 'azure_speech_region') ?: 'southeastasia';

// Dedicated internal virtualenv directory
$venv_dir = $CFG->dirroot . '/mod/readingassessment/venv_asr';
$service_file = $venv_dir . '/service.py';

// Resolve Python Binary Path directly inside venv_asr/
function readingassessment_get_python_binary($venv_dir, $os) {
    if ($os === 'windows') {
        $candidates = [
            "{$venv_dir}\\Scripts\\python.exe",
            "{$venv_dir}\\venv\\Scripts\\python.exe",
            "python.exe"
        ];
    } else {
        $candidates = [
            "{$venv_dir}/bin/python",
            "{$venv_dir}/venv/bin/python",
            "/usr/bin/python3",
            "python3"
        ];
    }
    foreach ($candidates as $cand) {
        if (file_exists($cand)) {
            return $cand;
        }
    }
    return ($os === 'windows') ? "{$venv_dir}\\Scripts\\python.exe" : "{$venv_dir}/bin/python";
}

$python_bin = readingassessment_get_python_binary($venv_dir, $os);
$python_exists = file_exists($python_bin);

// Check service online status
$service_running = false;
$ch = @curl_init("http://127.0.0.1:{$port}/");
if ($ch) {
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    $response = @curl_exec($ch);
    $http_code = @curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http_code >= 200 && $http_code < 500) {
        $service_running = true;
    }
}

// Check venv and files
clearstatcache();
$venv_exists = is_dir($venv_dir);
$service_exists = file_exists($service_file);

// Handle start / stop actions
$feedback_msg = '';
$feedback_type = 'info';

if ($action === 'start' && confirm_sesskey()) {
    if ($python_exists || $os === 'windows') {
        if ($os === 'windows') {
            pclose(popen("start /B \"\" \"{$python_bin}\" \"{$service_file}\"", "r"));
        } else {
            exec("PORT={$port} \"{$python_bin}\" \"{$service_file}\" > /tmp/asr_service.log 2>&1 &");
        }
        $feedback_msg = "ASR & Instructional Reader Service start command dispatched using [{$python_bin}] on port {$port}.";
        $feedback_type = 'success';
        $service_running = true;
    } else {
        $feedback_msg = "Error: Python binary not found in {$python_bin}. Please click 'Install / Update Dependencies' first.";
        $feedback_type = 'error';
    }
}

if ($action === 'stop' && confirm_sesskey()) {
    if ($os === 'windows') {
        exec("taskkill /F /IM python.exe 2>&1");
    } else {
        exec("pkill -f 'service.py' 2>&1");
    }
    $feedback_msg = "ASR & Instructional Reader Service stopped.";
    $feedback_type = 'info';
    $service_running = false;
}

$PAGE->set_url('/mod/readingassessment/admin.php');
$PAGE->set_title('Reading Assessment & Instructional Readers Administration');
$PAGE->set_heading('Reading Assessment & Instructional Readers Controller');

echo $OUTPUT->header();
?>

<style>
.ra-admin-container {
    max-width: 1000px;
    margin: 20px auto;
    font-family: system-ui, -apple-system, sans-serif;
}
.ra-admin-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 24px;
    margin-bottom: 24px;
    box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
}
.ra-admin-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 2px solid #f1f5f9;
    padding-bottom: 16px;
    margin-bottom: 20px;
}
.ra-badge {
    padding: 6px 14px;
    border-radius: 20px;
    font-weight: 600;
    font-size: 0.9rem;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.ra-badge-running { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.ra-badge-stopped { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.ra-category-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 24px;
}
.ra-cat-box {
    background: #f8fafc;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    padding: 18px;
}
.ra-cat-title {
    font-size: 1.15rem;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.ra-cat-features {
    font-size: 0.9rem;
    color: #475569;
    line-height: 1.6;
    margin-left: 18px;
}
</style>

<div class="ra-admin-container">

    <?php if ($feedback_msg): ?>
        <div class="alert alert-<?php echo ($feedback_type === 'success') ? 'success' : (($feedback_type === 'error') ? 'danger' : 'info'); ?> alert-dismissible fade show" role="alert">
            <?php echo s($feedback_msg); ?>
        </div>
    <?php endif; ?>

    <div class="ra-admin-card">
        <div class="ra-admin-header">
            <div>
                <h2 style="margin: 0; color: #0f172a; font-size: 1.5rem;">🎙️ ASR & Instructional Reader Service Controller</h2>
                <div style="color: #64748b; font-size: 0.95rem; margin-top: 4px;">Host OS: <strong><?php echo strtoupper($os); ?></strong> &bull; Port: <strong><?php echo $port; ?></strong></div>
            </div>
            <div>
                <?php if ($service_running): ?>
                    <span class="ra-badge ra-badge-running">● Service Online (Port <?php echo $port; ?>)</span>
                <?php else: ?>
                    <span class="ra-badge ra-badge-stopped">○ Service Offline</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Activity Categories Supported -->
        <h4 style="margin-bottom: 12px; color: #334155;">Supported Activity Categories:</h4>
        <div class="ra-category-grid">
            <div class="ra-cat-box" style="border-left: 4px solid #2563eb;">
                <div class="ra-cat-title">📖 1. Independent Reading Assessment</div>
                <div style="font-size: 0.9rem; color: #64748b; margin-bottom: 10px;">For standard oral reading fluency tests and official grading.</div>
                <ul class="ra-cat-features">
                    <li>Real-time WebRTC live speech streaming (<code>gpt-live-transcribe</code>).</li>
                    <li>3-Tier Jaro-Winkler accuracy scoring (Good, Improvement, Miscue).</li>
                    <li>Comprehension questionnaire & Moodle Gradebook sync.</li>
                </ul>
            </div>

            <div class="ra-cat-box" style="border-left: 4px solid #16a34a;">
                <div class="ra-cat-title">👩‍🏫 2. Instructional Readers</div>
                <div style="font-size: 0.9rem; color: #64748b; margin-bottom: 10px;">For developing readers requiring active teacher scaffolding.</div>
                <ul class="ra-cat-features">
                    <li>Line-by-line reading presentation with active word focus.</li>
                    <li>Real-time WebRTC live speech recognition (<code>gpt-live-transcribe</code>).</li>
                    <li>Word-level TTS audio synthesis (<code>tts-1</code>) with loopback muting protection.</li>
                    <li>Real-time mispronunciation/hesitation detection with audio corrections.</li>
                </ul>
            </div>
        </div>

        <!-- Environment Health & Status -->
        <h4 style="margin-bottom: 12px; color: #334155;">Virtual Environment & Dependencies:</h4>
        <div style="background: #f8fafc; padding: 16px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 20px;">
            <div style="margin-bottom: 6px;">📂 Direct Virtual Environment: <code><?php echo s($venv_dir); ?></code></div>
            <div style="margin-bottom: 6px;">🐍 Python Executable: <code><?php echo s($python_bin); ?></code> (<?php echo $python_exists ? '✅ Ready' : '❌ Not Installed'; ?>)</div>
            <div style="margin-bottom: 6px;">📄 Microservice Script: <code><?php echo s($service_file); ?></code> (<?php echo $service_exists ? '✅ Found' : '❌ Missing'; ?>)</div>
            <div>🔑 OpenAI API Key: <strong><?php echo !empty($apikey) ? '✅ Configured (sk-...'.substr($apikey, -4).')' : '⚠️ Missing in Plugin Settings'; ?></strong></div>
        </div>

        <!-- Controls -->
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <?php if (!$service_running): ?>
                <a href="<?php echo new moodle_url('/mod/readingassessment/admin.php', ['action' => 'start', 'sesskey' => sesskey()]); ?>" class="btn btn-success" style="font-weight: 600;">
                    ▶ Start ASR & Instructional Service
                </a>
            <?php else: ?>
                <a href="<?php echo new moodle_url('/mod/readingassessment/admin.php', ['action' => 'stop', 'sesskey' => sesskey()]); ?>" class="btn btn-danger" style="font-weight: 600;">
                    ⏹ Stop Service
                </a>
                <a href="<?php echo new moodle_url('/mod/readingassessment/admin.php', ['action' => 'start', 'sesskey' => sesskey()]); ?>" class="btn btn-outline-secondary" style="font-weight: 600;">
                    🔄 Restart Service
                </a>
            <?php endif; ?>

            <button id="btn-install-deps" class="btn btn-primary" style="font-weight: 600;">
                📦 Install / Update Dependencies
            </button>

            <a href="<?php echo new moodle_url('/admin/settings.php?section=modsettingreadingassessment'); ?>" class="btn btn-outline-primary">
                ⚙️ Plugin Settings
            </a>
        </div>

        <!-- Live Installation Progress Console -->
        <div id="install-progress-card" style="display: none; margin-top: 24px; padding: 20px; background: #f8fafc; border-radius: 10px; border: 1px solid #cbd5e1;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <strong id="install-status-label" style="font-size: 1.1rem; color: #1e293b;">⏳ Preparing environment...</strong>
                <span id="install-step-badge" class="ra-badge" style="background: #e0f2fe; color: #0369a1;">Step 1/3</span>
            </div>
            
            <div style="height: 20px; border-radius: 10px; background: #e2e8f0; overflow: hidden; margin-bottom: 14px;">
                <div id="install-bar" style="height: 100%; width: 0%; background: #16a34a; transition: width 0.4s ease;"></div>
            </div>

            <div id="install-console" style="background: #0f172a; color: #38bdf8; padding: 14px; border-radius: 8px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 0.85rem;">
                [Ready] Clicked Install Dependencies...
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    const installBtn = document.getElementById("btn-install-deps");
    const progressCard = document.getElementById("install-progress-card");
    const installBar = document.getElementById("install-bar");
    const statusLabel = document.getElementById("install-status-label");
    const stepBadge = document.getElementById("install-step-badge");
    const consoleBox = document.getElementById("install-console");

    if (installBtn) {
        installBtn.addEventListener("click", function() {
            installBtn.disabled = true;
            progressCard.style.display = "block";
            installBar.style.width = "25%";
            statusLabel.textContent = "⏳ Creating direct Python environment & installing packages (Please wait)...";
            stepBadge.textContent = "Processing...";
            consoleBox.textContent = "[Info] Initiating direct Python VENV installer...\n";

            const payload = new URLSearchParams({
                action: "install",
                os: "<?php echo $os; ?>",
                sesskey: "<?php echo sesskey(); ?>"
            });

            fetch("<?php echo $CFG->wwwroot; ?>/mod/readingassessment/ajax_install.php", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: payload
            })
            .then(res => res.json())
            .then(data => {
                if (data.status === "success") {
                    installBar.style.width = "100%";
                    statusLabel.textContent = "✅ " + data.message;
                    stepBadge.textContent = "Completed";
                    stepBadge.style.background = "#dcfce7";
                    stepBadge.style.color = "#15803d";
                    consoleBox.textContent += data.log + "\n\n[Success] Installation verified successfully! Reloading...";
                    setTimeout(() => window.location.reload(), 1500);
                } else {
                    installBar.style.width = "100%";
                    installBar.style.background = "#dc2626";
                    statusLabel.textContent = "❌ " + data.message;
                    stepBadge.textContent = "Failed";
                    stepBadge.style.background = "#fee2e2";
                    stepBadge.style.color = "#b91c1c";
                    consoleBox.textContent += (data.log || data.error || "");
                    installBtn.disabled = false;
                }
            })
            .catch(err => {
                installBar.style.background = "#dc2626";
                statusLabel.textContent = "❌ Installation error: " + err.message;
                consoleBox.textContent += "\n[Error] " + err.message;
                installBtn.disabled = false;
            });
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
