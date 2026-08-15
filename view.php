<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('readingassessment', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$readingassessment = $DB->get_record('readingassessment', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/readingassessment:view', $context);

// Process submission if sent via AJAX/POST
if ($action === 'submit' && data_submitted() && confirm_sesskey()) {
    // Check attempt limit before accepting submission
    $attemptcount = $DB->count_records('readingassessment_attempts', [
        'readingassessmentid' => $readingassessment->id,
        'userid' => $USER->id
    ]);

    $maxattempts = (int)($readingassessment->maxattempts ?? 0);
    if ($maxattempts > 0 && $attemptcount >= $maxattempts) {
        echo json_encode(['status' => 'error', 'message' => 'Maximum attempt limit reached.']);
        exit;
    }

    $transcript = optional_param('transcript', '', PARAM_RAW);
    $accuracy = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
    $comprehension = optional_param('comprehension_score', 0.0, PARAM_FLOAT);
    $finalgrade = optional_param('final_grade', 0.0, PARAM_FLOAT);
    $miscues = optional_param('miscues_json', '[]', PARAM_RAW);
    $answers = optional_param('answers_json', '[]', PARAM_RAW);

    $attempt = new stdClass();
    $attempt->readingassessmentid = $readingassessment->id;
    $attempt->userid              = $USER->id;
    $attempt->attempt             = $attemptcount + 1;
    $attempt->transcript          = $transcript;
    $attempt->accuracy_score      = $accuracy;
    $attempt->comprehension_score = $comprehension;
    $attempt->final_grade         = $finalgrade;
    $attempt->miscues_json        = $miscues;
    $attempt->answers_json        = $answers;
    $attempt->timecompleted       = time();

    $DB->insert_record('readingassessment_attempts', $attempt);

    // Update Moodle Gradebook
    readingassessment_update_grades($readingassessment, $USER->id);

    echo json_encode(['status' => 'success']);
    exit;
}

// Service URL dynamic determination
$serviceport = get_config('readingassessment', 'service_port') ?: 8000;
$server_host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
$asr_service_url = "http://{$server_host}:{$serviceport}";

// Set up page
$PAGE->set_url('/mod/readingassessment/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($readingassessment->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Include CSS and JS
$PAGE->requires->css('/mod/readingassessment/styles.css');
$PAGE->requires->js('/mod/readingassessment/js/assessment.js');

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($readingassessment->name));

if (!empty($readingassessment->intro)) {
    echo $OUTPUT->box(format_module_intro('readingassessment', $readingassessment, $cm->id), 'generalbox mod_introbox', 'readingassessmentintro');
}

// Decode questions JSON
$questions = [];
if (!empty($readingassessment->questions_json)) {
    $decoded = json_decode($readingassessment->questions_json, true);
    if (is_array($decoded)) {
        $questions = $decoded;
    }
}

// Get user previous attempts
$attempts = $DB->get_records('readingassessment_attempts', [
    'readingassessmentid' => $readingassessment->id,
    'userid' => $USER->id
], 'timecompleted DESC');

$attemptcount = count($attempts);
$maxattempts = (int)($readingassessment->maxattempts ?? 0);
$attempts_exhausted = ($maxattempts > 0 && $attemptcount >= $maxattempts);

if ($attempts_exhausted) {
    echo $OUTPUT->notification(get_string('attempts_exhausted', 'mod_readingassessment', $maxattempts), 'warning');
}

?>

<div class="reading-assessment-container">

    <!-- Reading Passage & Recording Card -->
    <div class="ra-card">
        <div class="ra-card-title">📖 Reading Passage</div>
        <div class="ra-passage-box" id="ra-passage-text">
            <?php echo nl2br(s($readingassessment->passage)); ?>
        </div>

        <div class="ra-controls">
            <button id="ra-btn-start" class="ra-btn ra-btn-start" <?php echo $attempts_exhausted ? 'disabled' : ''; ?>>
                <span>▶</span> Start
            </button>
            <button id="ra-btn-retry" class="ra-btn ra-btn-retry" disabled>
                <span>🔄</span> Retry
            </button>
        </div>

        <div class="ra-status-bar" id="ra-vad-indicator">
            <div class="ra-vad-dot"></div>
            <span id="ra-status-text">
                <?php
                if ($attempts_exhausted) {
                    echo get_string('attempts_exhausted', 'mod_readingassessment', $maxattempts);
                } else {
                    echo "Ready. Click [Start] when you are ready to read.";
                }
                ?>
            </span>
        </div>

        <div style="margin-top: 15px; font-style: italic; color: #64748b;" id="ra-live-transcript">
            <!-- Realtime ASR text streaming preview -->
        </div>
    </div>

    <!-- Comprehension Questions Card -->
    <?php if (!empty($questions)): ?>
    <div class="ra-card">
        <div class="ra-card-title">❓ Reading Comprehension Questions</div>
        <form id="ra-quiz-form">
            <?php foreach ($questions as $qidx => $q): ?>
                <div class="ra-question-item" id="ra-qitem-<?php echo $qidx; ?>">
                    <div class="ra-question-text"><?php echo ($qidx + 1) . '. ' . s($q['question']); ?></div>
                    <?php if (isset($q['options']) && is_array($q['options'])): ?>
                        <?php foreach ($q['options'] as $oidx => $opt): ?>
                            <label class="ra-option-label">
                                <input type="radio" name="q_<?php echo $qidx; ?>" value="<?php echo $oidx; ?>" <?php echo $attempts_exhausted ? 'disabled' : ''; ?>>
                                <?php echo s($opt); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </form>

        <div style="margin-top: 24px; text-align: right;">
            <button id="ra-btn-submit" class="ra-btn ra-btn-submit" <?php echo $attempts_exhausted ? 'disabled' : ''; ?>>
                <span>📤</span> Submit Assessment
            </button>
        </div>
    </div>
    <?php else: ?>
    <div style="margin-top: 24px; text-align: right;">
        <button id="ra-btn-submit" class="ra-btn ra-btn-submit" <?php echo $attempts_exhausted ? 'disabled' : ''; ?>>
            <span>📤</span> Submit Assessment
        </button>
    </div>
    <?php endif; ?>

    <!-- Recent Attempt Results Card -->
    <?php if (!empty($attempts)): ?>
    <div class="ra-card">
        <div class="ra-card-title">📊 Previous Attempt Results</div>
        <?php foreach ($attempts as $att): ?>
            <div style="padding: 16px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px; background: #fafafa;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                    <strong>Attempt #<?php echo $att->attempt; ?></strong>
                    <span class="ra-score-badge"><?php echo $att->final_grade; ?>% Composite</span>
                </div>
                <div style="display: flex; gap: 20px; font-size: 0.95rem; color: #475569; margin-bottom: 12px;">
                    <div>Accuracy: <strong><?php echo $att->accuracy_score; ?>%</strong></div>
                    <div>Comprehension: <strong><?php echo $att->comprehension_score; ?>%</strong></div>
                    <div>Date: <?php echo userdate($att->timecompleted); ?></div>
                </div>
                <?php
                if (!empty($att->miscues_json)):
                    $miscues = json_decode($att->miscues_json, true);
                    if (is_array($miscues)):
                ?>
                    <div style="margin-top: 10px; font-size: 1rem; line-height: 1.8;">
                        <strong>Passage Word Breakdown:</strong><br/>
                        <?php foreach ($miscues as $item): ?>
                            <?php
                            $st = $item['status'] ?? 'miscue';
                            $wordText = s($item['word'] ?? '');
                            $spokenText = s($item['spoken'] ?? '');
                            if ($st === 'good') {
                                echo "<span class=\"word-good\" title=\"Spoken cleanly\">{$wordText}</span> ";
                            } else if ($st === 'improvement') {
                                echo "<span class=\"word-improvement\" title=\"Spoken: {$spokenText} (slight improvement needed)\">{$wordText}</span> ";
                            } else {
                                echo "<span class=\"word-miscue\" title=\"Spoken: {$spokenText} (miscue)\">{$wordText}</span> ";
                            }
                            ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>

<script>
document.addEventListener("DOMContentLoaded", function() {
    ReadingAssessment.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        passage: <?php echo json_encode($readingassessment->passage); ?>,
        questions: <?php echo json_encode($questions); ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>
    });
});
</script>

<?php
echo $OUTPUT->footer();
