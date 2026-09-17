<?php
/**
 * Reading Assessment & Instructional Readers View Page.
 *
 * @package    mod_readingassessment
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id     = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);

$cm                 = get_coursemodule_from_id('readingassessment', $id, 0, false, MUST_EXIST);
$course             = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$readingassessment = $DB->get_record('readingassessment', ['id' => $cm->instance], '*', MUST_EXIST);

// Self-healing database schema verification
readingassessment_ensure_schema();

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/readingassessment:view', $context);

// Decode Custom Questions JSON
$custom_questions = [];
if (!empty($readingassessment->questions_json)) {
    $decoded = json_decode($readingassessment->questions_json, true);
    if (is_array($decoded)) {
        $custom_questions = $decoded;
    }
}

// Decode Non-Reader Studio Data
$nonreader_data = [
    'letters' => 'a, e, i, o, u',
    'words' => [
        ['word' => 'fish', 'image' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400', 'letters' => 'f, i, s, h'],
        ['word' => 'cat',  'image' => 'https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400', 'letters' => 'c, a, t'],
        ['word' => 'sun',  'image' => 'https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400', 'letters' => 's, u, n']
    ]
];
if (!empty($readingassessment->nonreader_data)) {
    $parsed_nr = json_decode($readingassessment->nonreader_data, true);
    if (is_array($parsed_nr)) {
        $nonreader_data = $parsed_nr;
    }
}

$attemptcount = $DB->count_records('readingassessment_attempts', [
    'readingassessmentid' => $readingassessment->id,
    'userid'              => $USER->id
]);

$maxattempts        = (int)($readingassessment->maxattempts ?? 0);
$attempts_exhausted = ($maxattempts > 0 && $attemptcount >= $maxattempts);
$activitytype       = $readingassessment->activitytype ?? 'assessment';
$is_instructional   = ($activitytype === 'instructional');
$is_nonreader       = ($activitytype === 'nonreader');
$is_struggling      = ($activitytype === 'struggling');

// Process submission if sent via AJAX/POST
if ($action === 'submit' && data_submitted() && confirm_sesskey()) {
    $transcript    = optional_param('transcript', '', PARAM_RAW);
    $accuracy      = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
    $reading_time  = optional_param('reading_time', 0, PARAM_INT);
    $reading_speed = optional_param('reading_speed', 0.0, PARAM_FLOAT);
    $miscues       = optional_param('miscues_json', '[]', PARAM_RAW);
    $answers_raw   = optional_param('answers_json', '[]', PARAM_RAW);
    $asrengine     = optional_param('asr_engine', 'azure', PARAM_ALPHA);

    $student_answers = json_decode($answers_raw, true) ?: [];

    // Evaluate custom multi-type questions
    $total_earned = 0;
    $total_max    = 0;

    foreach ($custom_questions as $qidx => $q) {
        $qtype = $q['type'] ?? 'multichoice';
        if ($qtype === 'description') {
            continue;
        }

        $total_max += 1.0;
        $ans        = $student_answers[$qidx] ?? null;

        if ($qtype === 'multichoice') {
            if ($ans !== null && intval($ans) === intval($q['correct'] ?? 0)) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'truefalse') {
            $expected = ($q['correct'] === true || $q['correct'] === 'true' || $q['correct'] === 1);
            $actual   = ($ans === true || $ans === 'true' || $ans === 1);
            if ($ans !== null && $expected === $actual) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'matching' || $qtype === 'randommatch') {
            $pairs = $q['pairs'] ?? [];
            if (!empty($pairs) && is_array($ans)) {
                $pair_correct = 0;
                foreach ($pairs as $pidx => $p) {
                    if (isset($ans[$pidx]) && trim(strtolower($ans[$pidx])) === trim(strtolower($p['answer']))) {
                        $pair_correct++;
                    }
                }
                $total_earned += ($pair_correct / count($pairs));
            }
        } else if ($qtype === 'shortanswer') {
            $accepted = array_map('trim', explode(',', $q['accepted_answers'] ?? ''));
            $case     = !empty($q['casesensitive']);
            $matched  = false;
            foreach ($accepted as $acc) {
                if ($case ? (trim($ans) === $acc) : (trim(strtolower($ans)) === strtolower($acc))) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) $total_earned += 1.0;
        } else if ($qtype === 'numerical' || $qtype === 'calculated' || $qtype === 'calculatedsimple' || $qtype === 'calculatedmulti') {
            $target = floatval($q['target_number'] ?? 0);
            $tol    = floatval($q['tolerance'] ?? 0.1);
            if ($ans !== null && is_numeric($ans) && abs(floatval($ans) - $target) <= $tol) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'essay') {
            if (!empty($ans) && strlen(trim($ans)) > 2) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'ddtext' || $qtype === 'selectmissing') {
            $expected_gaps = array_map('trim', explode(',', $q['gap_answers'] ?? ''));
            if (!empty($expected_gaps) && is_array($ans)) {
                $gaps_correct = 0;
                foreach ($expected_gaps as $gidx => $gexp) {
                    if (isset($ans[$gidx]) && trim(strtolower($ans[$gidx])) === strtolower($gexp)) {
                        $gaps_correct++;
                    }
                }
                $total_earned += ($gaps_correct / count($expected_gaps));
            }
        } else if ($qtype === 'ordering') {
            $expected_items = $q['items'] ?? [];
            if (!empty($expected_items) && is_array($ans)) {
                $order_correct = 0;
                foreach ($expected_items as $oidx => $oit) {
                    if (isset($ans[$oidx]) && trim($ans[$oidx]) === trim($oit)) {
                        $order_correct++;
                    }
                }
                $total_earned += ($order_correct / count($expected_items));
            }
        } else {
            if (!empty($ans)) $total_earned += 1.0;
        }
    }

    if ($total_max > 0) {
        $comprehension = round(($total_earned / $total_max) * 100.0, 2);
        $finalgrade    = round(($accuracy * 0.6) + ($comprehension * 0.4), 2);
    } else {
        $comprehension = 100.0;
        $finalgrade    = $accuracy;
    }

    $attempt                      = new stdClass();
    $attempt->readingassessmentid = $readingassessment->id;
    $attempt->userid              = $USER->id;
    $attempt->attempt             = $attemptcount + 1;
    $attempt->transcript          = $transcript;
    $attempt->accuracy_score      = $accuracy;
    $attempt->comprehension_score = $comprehension;
    $attempt->final_grade         = $finalgrade;
    $attempt->reading_time        = $reading_time;
    $attempt->reading_speed       = $reading_speed;
    $attempt->miscues_json        = $miscues;
    $attempt->answers_json        = $answers_raw;
    $attempt->timecompleted       = time();

    $DB->insert_record('readingassessment_attempts', $attempt);

    if (!$attempts_exhausted) {
        readingassessment_update_grades($readingassessment, $USER->id);
    }

    echo json_encode([
        'status'        => 'success',
        'engine'        => $asrengine,
        'comprehension' => $comprehension,
        'final_grade'   => $finalgrade,
        'practice'      => $attempts_exhausted
    ]);
    exit;
}

// Determine Dynamic Service Endpoint URL
$serviceport     = get_config('readingassessment', 'service_port') ?: 8010;
$server_host     = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
$asr_service_url = "http://{$server_host}:{$serviceport}";

// Setup Page Context
$PAGE->set_url('/mod/readingassessment/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($readingassessment->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Include Stylesheet & Dynamic Activity JS Engine
$PAGE->requires->css('/mod/readingassessment/styles.css');
if ($is_nonreader) {
    $PAGE->requires->js('/mod/readingassessment/js/nonreader.js');
} else if ($is_instructional || $is_struggling) {
    $PAGE->requires->js('/mod/readingassessment/js/instructional.js');
} else {
    $PAGE->requires->js('/mod/readingassessment/js/assessment.js');
}

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($readingassessment->name));

if (!empty($readingassessment->intro)) {
    echo $OUTPUT->box(format_module_intro('readingassessment', $readingassessment, $cm->id), 'generalbox mod_introbox', 'readingassessmentintro');
}

// Fetch Previous User Attempts
$attempts = $DB->get_records('readingassessment_attempts', [
    'readingassessmentid' => $readingassessment->id,
    'userid'              => $USER->id
], 'timecompleted DESC');

$latest_attempt = !empty($attempts) ? reset($attempts) : null;

if ($attempts_exhausted) {
    echo $OUTPUT->notification("You have completed the maximum allowed official attempts ({$maxattempts}). You can now use Practice Mode for unlimited reading without affecting your official grade.", 'info');
}

$mastery_rep_value = isset($readingassessment->mastery_repetitions) ? intval($readingassessment->mastery_repetitions) : 2;
if ($mastery_rep_value <= 0) {
    $mastery_rep_value = 2;
}

$can_grade = has_capability('mod/readingassessment:grade', $context);
?>

<div class="reading-assessment-container">

    <!-- Teacher Dashboard Access Bar -->
    <?php if ($can_grade): ?>
        <div class="ra-teacher-bar">
            <div class="ra-teacher-title">
                <span>👩‍🏫</span> <span>Teacher Dashboard:</span>
            </div>
            <a href="<?php echo new moodle_url('/mod/readingassessment/report.php', ['id' => $cm->id, 'courseid' => $course->id]); ?>" 
               class="ra-teacher-btn">
                <span>📊</span> ARAL Program: Student Reading Progress
            </a>
        </div>
    <?php endif; ?>

    <!-- Activity Mode Renderers -->
    <?php if ($is_nonreader): ?>
        <!-- Non-Reader & Early Phonics Studio -->
        <div id="ra-nonreader-studio"></div>

    <?php elseif ($is_instructional || $is_struggling): ?>
        <!-- Guided Instructional / Struggling Reader Mode -->
        <div class="ra-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                <div class="ra-card-title" style="margin-bottom: 0;">
                    <?php echo $is_struggling ? '🆘 Struggling Reader Intervention' : '📖 Guided Instructional Reader'; ?>
                </div>
                <div style="display: flex; gap: 12px;">
                    <span class="ra-score-badge" style="background: #0284c7; font-size: 0.9rem;" id="ra-instructional-progress">Line 1</span>
                    <span class="ra-score-badge" style="background: #e11d48; font-size: 0.9rem;" id="ra-miscue-badge">0 Miscues</span>
                </div>
            </div>

            <!-- Teacher Speech Intervention Bubble -->
            <div id="ra-coach-bubble" class="ra-coach-bubble" style="display: none;">
                <span id="ra-coach-text">👩‍🏫 <em>Listen carefully...</em></span>
            </div>

            <!-- Previous Completed Lines Review -->
            <div id="ra-instructional-prev-lines" style="display: none; margin-bottom: 16px; padding: 12px; background: #fafafa; border-radius: 8px; border-left: 3px solid #94a3b8;"></div>

            <!-- Active Focus Line -->
            <div class="ra-instructional-box">
                <div style="font-size: 0.9rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Read this line aloud:</div>
                <div id="ra-instructional-line" class="ra-instructional-line"></div>
                <div style="font-size: 0.85rem; color: #94a3b8;">💡 Tip: Click on any word to hear how it sounds.</div>
            </div>

            <div class="ra-controls" style="justify-content: center;">
                <button id="ra-btn-inst-start" class="ra-btn ra-btn-start" style="font-size: 1.15rem; padding: 12px 32px;">
                    <span>▶</span> Start Reading
                </button>
            </div>

            <div class="ra-status-bar" style="text-align: center; justify-content: center;">
                <span id="ra-inst-status">Click [Start Reading] when you are ready. The teacher will guide you line-by-line!</span>
            </div>
        </div>

        <!-- Custom Questions Section -->
        <div id="ra-instructional-quiz" style="display: none;">
            <?php echo readingassessment_render_questions($custom_questions, 'ra-inst-quiz-form', 'ra-btn-inst-submit'); ?>
        </div>

    <?php else: ?>
        <!-- Independent Assessment Mode -->
        <div class="ra-card">
            <div class="ra-card-title">📖 Reading Passage</div>
            <div class="ra-passage-box" id="ra-passage-text">
                <?php echo nl2br(s($readingassessment->passage)); ?>
            </div>

            <div class="ra-controls">
                <button id="ra-btn-start" class="ra-btn ra-btn-start">
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
                        echo "Max attempts reached ({$maxattempts}). Click [Start] to read in Practice Mode.";
                    } else {
                        echo "Official Attempt (" . ($attemptcount + 1) . "/" . ($maxattempts > 0 ? $maxattempts : '∞') . "). Click [Start] when ready.";
                    }
                    ?>
                </span>
            </div>

            <div style="margin-top: 15px; font-style: italic; color: #64748b;" id="ra-live-transcript"></div>
        </div>

        <!-- Custom Questions Section -->
        <?php echo readingassessment_render_questions($custom_questions, 'ra-quiz-form', 'ra-btn-submit'); ?>

    <?php endif; ?>

    <!-- Student Performance & Analytics Card -->
    <?php if (!empty($latest_attempt)): ?>
        <div class="ra-card" style="padding: 24px; margin-top: 24px;">
            <div class="ra-card-title">📊 My Latest Reading Analytics & Performance</div>

            <!-- Student KPI Metrics Grid -->
            <div class="ra-kpi-grid">
                <div class="ra-kpi-card">
                    <div class="ra-kpi-icon" style="background: #e0f2fe; color: #0284c7;">⚡</div>
                    <div class="ra-kpi-val"><?php echo !empty($latest_attempt->reading_speed) ? $latest_attempt->reading_speed : 'N/A'; ?> <span style="font-size: 0.95rem; font-weight: 500;">WPM</span></div>
                    <div class="ra-kpi-label">Reading Speed</div>
                </div>
                <div class="ra-kpi-card">
                    <div class="ra-kpi-icon" style="background: #dcfce7; color: #15803d;">🎯</div>
                    <div class="ra-kpi-val"><?php echo $latest_attempt->accuracy_score; ?>%</div>
                    <div class="ra-kpi-label">Reading Accuracy</div>
                </div>
                <div class="ra-kpi-card">
                    <div class="ra-kpi-icon" style="background: #fef3c7; color: #b45309;">⏱️</div>
                    <div class="ra-kpi-val"><?php echo !empty($latest_attempt->reading_time) ? $latest_attempt->reading_time . 's' : 'N/A'; ?></div>
                    <div class="ra-kpi-label">Reading Duration</div>
                </div>
                <div class="ra-kpi-card">
                    <div class="ra-kpi-icon" style="background: #ede9fe; color: #7c3aed;">🏆</div>
                    <div class="ra-kpi-val"><?php echo $latest_attempt->final_grade; ?>%</div>
                    <div class="ra-kpi-label">Overall Grade</div>
                </div>
            </div>

            <!-- Latest Attempt Word Breakdown -->
            <?php
            if (!empty($latest_attempt->miscues_json)):
                $miscues = json_decode($latest_attempt->miscues_json, true);
                if (is_array($miscues)):
            ?>
                <div style="margin-top: 20px; padding: 18px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px;">
                    <div style="font-weight: 700; color: #1e293b; margin-bottom: 10px; font-size: 1.05rem;">📖 Word-by-Word Reading Review (Attempt #<?php echo $latest_attempt->attempt; ?>):</div>
                    <div style="font-size: 1.15rem; line-height: 2.2; letter-spacing: 0.01em;">
                        <?php foreach ($miscues as $item): ?>
                            <?php
                            $st         = $item['status'] ?? 'miscue';
                            $wordText   = s($item['word'] ?? '');
                            $spokenText = s($item['spoken'] ?? '');
                            if ($st === 'good') {
                                echo "<span class=\"word-good\" title=\"Spoken cleanly\">{$wordText}</span> ";
                            } else if ($st === 'improvement') {
                                echo "<span class=\"word-improvement\" title=\"Spoken: {$spokenText}\">{$wordText}</span> ";
                            } else {
                                echo "<span class=\"word-miscue\" title=\"Spoken: {$spokenText} (miscue)\">{$wordText}</span> ";
                            }
                            ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; endif; ?>

            <!-- Previous Attempt History -->
            <?php if (count($attempts) > 1): ?>
                <div style="margin-top: 24px;">
                    <div style="font-weight: 700; color: #475569; margin-bottom: 10px;">📜 Previous Attempts History:</div>
                    <?php foreach ($attempts as $idx => $att): ?>
                        <?php if ($idx === 0) continue; ?>
                        <div style="padding: 12px 16px; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 8px; background: #fafafa; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; font-size: 0.9rem;">
                            <div>
                                <strong>Attempt #<?php echo $att->attempt; ?></strong> &bull;
                                Speed: <strong><?php echo !empty($att->reading_speed) ? $att->reading_speed . ' WPM' : 'N/A'; ?></strong> &bull;
                                Accuracy: <strong><?php echo $att->accuracy_score; ?>%</strong> &bull;
                                Duration: <strong><?php echo !empty($att->reading_time) ? $att->reading_time . 's' : 'N/A'; ?></strong> &bull;
                                Grade: <strong><?php echo $att->final_grade; ?>%</strong>
                            </div>
                            <div style="color: #64748b; font-size: 0.85rem;">
                                <?php echo userdate($att->timecompleted); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>
    <?php endif; ?>

</div>

<!-- Client-side Engine Initialization -->
<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if ($is_nonreader): ?>
    NonReaderStudio.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        nonreader_data: <?php echo json_encode($nonreader_data); ?>,
        tts_voice: <?php echo json_encode($readingassessment->tts_voice ?? 'en-US-JennyNeural'); ?>,
        tts_personality_prompt: <?php echo json_encode($readingassessment->tts_personality_prompt ?? ''); ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>
    });
    <?php elseif ($is_instructional || $is_struggling): ?>
    InstructionalReader.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        passage: <?php echo json_encode($readingassessment->passage); ?>,
        questions: <?php echo json_encode($custom_questions); ?>,
        tts_voice: <?php echo json_encode($readingassessment->tts_voice ?? 'en-US-JennyNeural'); ?>,
        tts_personality_prompt: <?php echo json_encode($readingassessment->tts_personality_prompt ?? ''); ?>,
        mastery_repetitions: <?php echo $mastery_rep_value; ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>,
        is_struggling: <?php echo $is_struggling ? 'true' : 'false'; ?>
    });
    <?php else: ?>
    ReadingAssessment.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        passage: <?php echo json_encode($readingassessment->passage); ?>,
        questions: <?php echo json_encode($custom_questions); ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>,
        attempts_exhausted: <?php echo $attempts_exhausted ? 'true' : 'false'; ?>,
        maxattempts: <?php echo (int)$maxattempts; ?>,
        attemptcount: <?php echo (int)$attemptcount; ?>
    });
    <?php endif; ?>
});
</script>

<?php
echo $OUTPUT->footer();
