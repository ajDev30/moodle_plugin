<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course Module ID
$action = optional_param('action', '', PARAM_ALPHA);

$cm = get_coursemodule_from_id('readingassessment', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$readingassessment = $DB->get_record('readingassessment', ['id' => $cm->instance], '*', MUST_EXIST);

// Self-healing database schema verification
$dbman = $DB->get_manager();
$table_ra = new xmldb_table('readingassessment');
$field_mastery = new xmldb_field('mastery_repetitions', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'tts_voice');
if (!$dbman->field_exists($table_ra, $field_mastery)) {
    $dbman->add_field($table_ra, $field_mastery);
}
$table_att = new xmldb_table('readingassessment_attempts');
$field_time = new xmldb_field('reading_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'final_grade');
if (!$dbman->field_exists($table_att, $field_time)) {
    $dbman->add_field($table_att, $field_time);
}
$field_speed = new xmldb_field('reading_speed', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.00', 'reading_time');
if (!$dbman->field_exists($table_att, $field_speed)) {
    $dbman->add_field($table_att, $field_speed);
}
$field_nr = new xmldb_field('nonreader_data', XMLDB_TYPE_TEXT, null, null, null, null, null, 'questions_json');
if (!$dbman->field_exists($table_ra, $field_nr)) {
    $dbman->add_field($table_ra, $field_nr);
}
$field_personality = new xmldb_field('tts_personality_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'tts_voice');
if (!$dbman->field_exists($table_ra, $field_personality)) {
    $dbman->add_field($table_ra, $field_personality);
}

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/readingassessment:view', $context);

// Decode Custom Questions
$custom_questions = [];
if (!empty($readingassessment->questions_json)) {
    $decoded = json_decode($readingassessment->questions_json, true);
    if (is_array($decoded)) {
        $custom_questions = $decoded;
    }
}

// Decode Non-Reader Data
$nonreader_data = [
    'letters' => 'a, e, i, o, u',
    'words' => [
        ['word' => 'fish', 'image' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400', 'letters' => 'f, i, s, h'],
        ['word' => 'cat', 'image' => 'https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400', 'letters' => 'c, a, t'],
        ['word' => 'sun', 'image' => 'https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400', 'letters' => 's, u, n']
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
    'userid' => $USER->id
]);

$maxattempts = (int)($readingassessment->maxattempts ?? 0);
$attempts_exhausted = ($maxattempts > 0 && $attemptcount >= $maxattempts);
$activitytype = $readingassessment->activitytype ?? 'assessment';
$is_instructional = ($activitytype === 'instructional');
$is_nonreader = ($activitytype === 'nonreader');

// Process submission if sent via AJAX/POST
if ($action === 'submit' && data_submitted() && confirm_sesskey()) {
    $transcript = optional_param('transcript', '', PARAM_RAW);
    $accuracy = optional_param('accuracy_score', 0.0, PARAM_FLOAT);
    $reading_time = optional_param('reading_time', 0, PARAM_INT);
    $reading_speed = optional_param('reading_speed', 0.0, PARAM_FLOAT);
    $miscues = optional_param('miscues_json', '[]', PARAM_RAW);
    $answers_raw = optional_param('answers_json', '[]', PARAM_RAW);
    $asrengine = optional_param('asr_engine', 'openai', PARAM_ALPHA);

    $student_answers = json_decode($answers_raw, true) ?: [];

    // Evaluate custom multi-type questions
    $total_earned = 0;
    $total_max = 0;

    foreach ($custom_questions as $qidx => $q) {
        $qtype = $q['type'] ?? 'multichoice';
        if ($qtype === 'description') {
            continue; // Descriptions carry no point value
        }

        $total_max += 1.0;
        $ans = $student_answers[$qidx] ?? null;

        if ($qtype === 'multichoice') {
            if ($ans !== null && intval($ans) === intval($q['correct'] ?? 0)) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'truefalse') {
            $expected = ($q['correct'] === true || $q['correct'] === 'true' || $q['correct'] === 1);
            $actual = ($ans === true || $ans === 'true' || $ans === 1);
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
            $case = !empty($q['casesensitive']);
            $matched = false;
            foreach ($accepted as $acc) {
                if ($case ? (trim($ans) === $acc) : (trim(strtolower($ans)) === strtolower($acc))) {
                    $matched = true;
                    break;
                }
            }
            if ($matched) $total_earned += 1.0;
        } else if ($qtype === 'numerical' || $qtype === 'calculated' || $qtype === 'calculatedsimple' || $qtype === 'calculatedmulti') {
            $target = floatval($q['target_number'] ?? 0);
            $tol = floatval($q['tolerance'] ?? 0.1);
            if ($ans !== null && is_numeric($ans) && abs(floatval($ans) - $target) <= $tol) {
                $total_earned += 1.0;
            }
        } else if ($qtype === 'essay') {
            // Award completion point upon non-empty submission
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
            // Default completion
            if (!empty($ans)) $total_earned += 1.0;
        }
    }

    if ($total_max > 0) {
        $comprehension = round(($total_earned / $total_max) * 100.0, 2);
        $finalgrade = round(($accuracy * 0.6) + ($comprehension * 0.4), 2);
    } else {
        $comprehension = 100.0;
        $finalgrade = $accuracy;
    }

    $attempt = new stdClass();
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

    // Update Moodle Gradebook
    if (!$attempts_exhausted) {
        readingassessment_update_grades($readingassessment, $USER->id);
    }

    echo json_encode([
        'status' => 'success',
        'engine' => $asrengine,
        'comprehension' => $comprehension,
        'final_grade' => $finalgrade,
        'practice' => $attempts_exhausted
    ]);
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
if ($is_nonreader) {
    $PAGE->requires->js('/mod/readingassessment/js/nonreader.js');
} else if ($is_instructional) {
    $PAGE->requires->js('/mod/readingassessment/js/instructional.js');
} else {
    $PAGE->requires->js('/mod/readingassessment/js/assessment.js');
}

echo $OUTPUT->header();

echo $OUTPUT->heading(format_string($readingassessment->name));

if (!empty($readingassessment->intro)) {
    echo $OUTPUT->box(format_module_intro('readingassessment', $readingassessment, $cm->id), 'generalbox mod_introbox', 'readingassessmentintro');
}

// Get user previous attempts
$attempts = $DB->get_records('readingassessment_attempts', [
    'readingassessmentid' => $readingassessment->id,
    'userid' => $USER->id
], 'timecompleted DESC');

$latest_attempt = !empty($attempts) ? reset($attempts) : null;

if ($attempts_exhausted) {
    echo $OUTPUT->notification("You have completed the maximum allowed official attempts ({$maxattempts}). You can now use Practice Mode (Browser Speech Engine) for unlimited reading without affecting your official grade.", 'info');
}

$mastery_rep_value = isset($readingassessment->mastery_repetitions) ? intval($readingassessment->mastery_repetitions) : 2;
if ($mastery_rep_value <= 0) {
    $mastery_rep_value = 2;
}

$can_grade = has_capability('mod/readingassessment:grade', $context);

?>

<div class="reading-assessment-container">

    <!-- Teacher Access Bar (Visible only to teachers and graders) -->
    <?php if ($can_grade): ?>
        <div style="display: flex; justify-content: space-between; align-items: center; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 10px; padding: 12px 18px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.04);">
            <div style="display: flex; align-items: center; gap: 8px; font-weight: 700; color: #1e293b; font-size: 1rem;">
                <span style="font-size: 1.2rem;">👩‍🏫</span> <span>Teacher Dashboard:</span>
            </div>
            <a href="<?php echo new moodle_url('/mod/readingassessment/report.php', ['id' => $cm->id, 'courseid' => $course->id]); ?>" 
               class="btn btn-primary font-weight-bold" 
               style="background: #0284c7; border: none; border-radius: 8px; padding: 8px 20px; display: inline-flex; align-items: center; gap: 6px; text-decoration: none; color: #ffffff; font-weight: 700;">
                <span>📊</span> ARAL Program: Student Reading Progress
            </a>
        </div>
    <?php endif; ?>

<?php if ($is_nonreader): ?>
    <!-- ========================================== -->
    <!-- NON-READER & EARLY PHONICS STUDIO          -->
    <!-- ========================================== -->
    <div id="ra-nonreader-studio"></div>

<?php elseif ($is_instructional): ?>
    <!-- ========================================== -->
    <!-- INSTRUCTIONAL READER: GUIDED COACHING MODE -->
    <!-- ========================================== -->
    <div class="ra-card">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <div class="ra-card-title" style="margin-bottom: 0;">📖 Guided Instructional Reader</div>
            <div style="display: flex; gap: 12px;">
                <span class="ra-score-badge" style="background: #0284c7; font-size: 0.9rem;" id="ra-instructional-progress">Line 1</span>
                <span class="ra-score-badge" style="background: #e11d48; font-size: 0.9rem;" id="ra-miscue-badge">0 Miscues</span>
            </div>
        </div>

        <!-- Teacher Intervention Speech Bubble -->
        <div id="ra-coach-bubble" class="ra-coach-bubble" style="display: none;">
            <span id="ra-coach-text">👩‍🏫 <em>Listen carefully...</em></span>
        </div>

        <!-- Previous completed lines review -->
        <div id="ra-instructional-prev-lines" style="display: none; margin-bottom: 16px; padding: 12px; background: #fafafa; border-radius: 8px; border-left: 3px solid #94a3b8;"></div>

        <!-- Active Focus Line -->
        <div class="ra-instructional-box">
            <div style="font-size: 0.9rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: 0.05em;">Read this line aloud:</div>
            <div id="ra-instructional-line" class="ra-instructional-line">
                <!-- Word tokens rendered by instructional.js -->
            </div>
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

    <!-- In-Page Custom Questionnaire Section (Instructional Mode) -->
    <?php if (!empty($custom_questions)): ?>
    <div id="ra-instructional-quiz" class="ra-card" style="display: none; margin-top: 24px; border-left: 4px solid #3b82f6;">
        <div class="ra-card-title">📝 Comprehension & Reflection Questions</div>
        <p style="color: #64748b; margin-bottom: 16px; font-size: 0.95rem;">Please complete the items below based on the reading passage:</p>
        
        <form id="ra-inst-quiz-form">
            <?php foreach ($custom_questions as $qidx => $q): ?>
                <?php
                $type = $q['type'] ?? 'multichoice';
                ?>
                <div class="ra-question-item" style="padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px;">
                    
                    <?php if ($type === 'description'): ?>
                        <div class="alert alert-info" style="border-radius: 8px; margin: 0; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1;">
                            <div style="font-weight: 700; font-size: 1.05rem; margin-bottom: 4px;"><?php echo s($q['title'] ?? 'Instructions'); ?></div>
                            <div style="font-size: 0.95rem; line-height: 1.5;"><?php echo nl2br(s($q['question'] ?? '')); ?></div>
                        </div>

                    <?php elseif ($type === 'multichoice'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <?php if (!empty($q['options'])): ?>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($q['options'] as $oidx => $opt): ?>
                                    <label class="ra-option-label" style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                        <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="<?php echo $oidx; ?>" style="transform: scale(1.2);">
                                        <span style="font-size: 0.95rem; color: #334155;"><strong><?php echo chr(65 + $oidx); ?>.</strong> <?php echo s($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($type === 'truefalse'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <div style="display: flex; gap: 16px;">
                            <label class="ra-option-label" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="true" style="transform: scale(1.2);">
                                <span style="font-weight: 600; color: #16a34a;">True</span>
                            </label>
                            <label class="ra-option-label" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="false" style="transform: scale(1.2);">
                                <span style="font-weight: 600; color: #dc2626;">False</span>
                            </label>
                        </div>

                    <?php elseif ($type === 'matching' || $type === 'randommatch'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? 'Match the items:'); ?>
                        </div>
                        <?php
                        $pairs = $q['pairs'] ?? [];
                        $all_answers = array_filter(array_map(function($p) { return $p['answer'] ?? ''; }, $pairs));
                        shuffle($all_answers);
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($pairs as $pidx => $p): ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #ffffff; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                    <span style="font-weight: 600; color: #334155; flex: 1;"><?php echo s($p['question'] ?? ''); ?></span>
                                    <select name="ra_q_<?php echo $qidx; ?>_p_<?php echo $pidx; ?>" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #94a3b8; flex: 1;">
                                        <option value="">-- Choose matching answer --</option>
                                        <?php foreach ($all_answers as $ans_opt): ?>
                                            <option value="<?php echo s($ans_opt); ?>"><?php echo s($ans_opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'shortanswer' || $type === 'numerical' || $type === 'calculated' || $type === 'calculatedsimple' || $type === 'calculatedmulti'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <input type="text" name="ra_q_<?php echo $qidx; ?>" placeholder="Type your answer here..." style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 1rem;">

                    <?php elseif ($type === 'essay'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <textarea name="ra_q_<?php echo $qidx; ?>" rows="4" placeholder="Write your essay response here..." style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.95rem;"></textarea>

                    <?php elseif ($type === 'ordering'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? 'Arrange in order:'); ?>
                        </div>
                        <?php
                        $items = $q['items'] ?? [];
                        $shuffled_items = $items;
                        shuffle($shuffled_items);
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <?php foreach ($shuffled_items as $iidx => $it): ?>
                                <div style="display: flex; align-items: center; gap: 10px; background: #ffffff; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                    <select name="ra_q_<?php echo $qidx; ?>_ord_<?php echo $iidx; ?>" style="padding: 6px; border-radius: 6px; border: 1px solid #94a3b8; font-weight: 700;">
                                        <?php for ($s = 1; $s <= count($shuffled_items); $s++): ?>
                                            <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span style="font-size: 0.95rem; color: #334155;" data-itemtext="<?php echo s($it); ?>"><?php echo s($it); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <!-- Cloze / Drag and drop into text / Fallback -->
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <input type="text" name="ra_q_<?php echo $qidx; ?>" placeholder="Your response..." style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </form>

        <div style="margin-top: 24px; text-align: right;">
            <button id="ra-btn-inst-submit" class="ra-btn ra-btn-submit" style="padding: 12px 30px; font-size: 1.05rem;">
                <span>📤</span> Complete & Submit Assessment
            </button>
        </div>
    </div>
    <?php else: ?>
    <div id="ra-instructional-quiz" style="display: none; margin-top: 20px; text-align: right;">
        <button id="ra-btn-inst-submit" class="ra-btn ra-btn-submit" style="padding: 12px 30px; font-size: 1.05rem;">
            <span>📤</span> Submit Reading Assessment
        </button>
    </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ========================================== -->
    <!-- INDEPENDENT ASSESSMENT: STANDARD MODE      -->
    <!-- ========================================== -->
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
                    echo "Max attempts reached ({$maxattempts}). Click [Start] to read in Practice Mode (Browser Speech Engine).";
                } else {
                    $remaining = ($maxattempts > 0) ? ($maxattempts - $attemptcount) : 'Unlimited';
                    echo "Official Attempt (" . ($attemptcount + 1) . "/" . ($maxattempts > 0 ? $maxattempts : '∞') . "). Click [Start] when ready to read.";
                }
                ?>
            </span>
        </div>

        <div style="margin-top: 15px; font-style: italic; color: #64748b;" id="ra-live-transcript">
            <!-- Realtime ASR text streaming preview -->
        </div>
    </div>

    <!-- In-Page Custom Questionnaire Section (Standard Mode) -->
    <?php if (!empty($custom_questions)): ?>
    <div class="ra-card" style="margin-top: 24px; border-left: 4px solid #3b82f6;">
        <div class="ra-card-title">📝 Comprehension & Reflection Questions</div>
        <p style="color: #64748b; margin-bottom: 16px; font-size: 0.95rem;">After reading the passage above, please complete the questions below:</p>
        
        <form id="ra-quiz-form">
            <?php foreach ($custom_questions as $qidx => $q): ?>
                <?php
                $type = $q['type'] ?? 'multichoice';
                ?>
                <div class="ra-question-item" style="padding: 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; margin-bottom: 16px;">
                    
                    <?php if ($type === 'description'): ?>
                        <div class="alert alert-info" style="border-radius: 8px; margin: 0; background: #e0f2fe; border: 1px solid #bae6fd; color: #0369a1;">
                            <div style="font-weight: 700; font-size: 1.05rem; margin-bottom: 4px;"><?php echo s($q['title'] ?? 'Instructions'); ?></div>
                            <div style="font-size: 0.95rem; line-height: 1.5;"><?php echo nl2br(s($q['question'] ?? '')); ?></div>
                        </div>

                    <?php elseif ($type === 'multichoice'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <?php if (!empty($q['options'])): ?>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                <?php foreach ($q['options'] as $oidx => $opt): ?>
                                    <label class="ra-option-label" style="display: flex; align-items: center; gap: 10px; padding: 10px 14px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                        <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="<?php echo $oidx; ?>" style="transform: scale(1.2);">
                                        <span style="font-size: 0.95rem; color: #334155;"><strong><?php echo chr(65 + $oidx); ?>.</strong> <?php echo s($opt); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                    <?php elseif ($type === 'truefalse'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <div style="display: flex; gap: 16px;">
                            <label class="ra-option-label" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="true" style="transform: scale(1.2);">
                                <span style="font-weight: 600; color: #16a34a;">True</span>
                            </label>
                            <label class="ra-option-label" style="display: flex; align-items: center; gap: 8px; padding: 10px 20px; background: #ffffff; border: 1px solid #cbd5e1; border-radius: 8px; cursor: pointer;">
                                <input type="radio" name="ra_q_<?php echo $qidx; ?>" value="false" style="transform: scale(1.2);">
                                <span style="font-weight: 600; color: #dc2626;">False</span>
                            </label>
                        </div>

                    <?php elseif ($type === 'matching' || $type === 'randommatch'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? 'Match the items:'); ?>
                        </div>
                        <?php
                        $pairs = $q['pairs'] ?? [];
                        $all_answers = array_filter(array_map(function($p) { return $p['answer'] ?? ''; }, $pairs));
                        shuffle($all_answers);
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php foreach ($pairs as $pidx => $p): ?>
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #ffffff; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                    <span style="font-weight: 600; color: #334155; flex: 1;"><?php echo s($p['question'] ?? ''); ?></span>
                                    <select name="ra_q_<?php echo $qidx; ?>_p_<?php echo $pidx; ?>" style="padding: 6px 12px; border-radius: 6px; border: 1px solid #94a3b8; flex: 1;">
                                        <option value="">-- Choose matching answer --</option>
                                        <?php foreach ($all_answers as $ans_opt): ?>
                                            <option value="<?php echo s($ans_opt); ?>"><?php echo s($ans_opt); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php elseif ($type === 'shortanswer' || $type === 'numerical' || $type === 'calculated' || $type === 'calculatedsimple' || $type === 'calculatedmulti'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <input type="text" name="ra_q_<?php echo $qidx; ?>" placeholder="Type your answer here..." style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 1rem;">

                    <?php elseif ($type === 'essay'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <textarea name="ra_q_<?php echo $qidx; ?>" rows="4" placeholder="Write your essay response here..." style="width: 100%; padding: 12px; border-radius: 8px; border: 1px solid #cbd5e1; font-size: 0.95rem;"></textarea>

                    <?php elseif ($type === 'ordering'): ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? 'Arrange in order:'); ?>
                        </div>
                        <?php
                        $items = $q['items'] ?? [];
                        $shuffled_items = $items;
                        shuffle($shuffled_items);
                        ?>
                        <div style="display: flex; flex-direction: column; gap: 8px;">
                            <?php foreach ($shuffled_items as $iidx => $it): ?>
                                <div style="display: flex; align-items: center; gap: 10px; background: #ffffff; padding: 10px 14px; border: 1px solid #cbd5e1; border-radius: 8px;">
                                    <select name="ra_q_<?php echo $qidx; ?>_ord_<?php echo $iidx; ?>" style="padding: 6px; border-radius: 6px; border: 1px solid #94a3b8; font-weight: 700;">
                                        <?php for ($s = 1; $s <= count($shuffled_items); $s++): ?>
                                            <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span style="font-size: 0.95rem; color: #334155;" data-itemtext="<?php echo s($it); ?>"><?php echo s($it); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php else: ?>
                        <div style="font-size: 1.05rem; font-weight: 700; color: #1e293b; margin-bottom: 12px;">
                            <?php echo ($qidx + 1) . '. ' . s($q['question'] ?? ''); ?>
                        </div>
                        <input type="text" name="ra_q_<?php echo $qidx; ?>" placeholder="Your response..." style="width: 100%; padding: 10px 14px; border-radius: 8px; border: 1px solid #cbd5e1;">
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        </form>

        <div style="margin-top: 24px; text-align: right;">
            <button id="ra-btn-submit" class="ra-btn ra-btn-submit" style="padding: 12px 30px; font-size: 1.05rem;">
                <span>📤</span> Submit Assessment
            </button>
        </div>
    </div>
    <?php else: ?>
    <div style="margin-top: 24px; text-align: right;">
        <button id="ra-btn-submit" class="ra-btn ra-btn-submit">
            <span>📤</span> Submit Assessment
        </button>
    </div>
    <?php endif; ?>

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
            </div>
        <?php endif; endif; ?>

        <!-- Attempt History Collapsible / Summary -->
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

<script>
document.addEventListener("DOMContentLoaded", function() {
    <?php if ($is_nonreader): ?>
    NonReaderStudio.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        nonreader_data: <?php echo json_encode($nonreader_data); ?>,
        tts_voice: <?php echo json_encode($readingassessment->tts_voice ?? 'alloy'); ?>,
        tts_personality_prompt: <?php echo json_encode($readingassessment->tts_personality_prompt ?? ''); ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>
    });
    <?php elseif ($is_instructional): ?>
    InstructionalReader.init({
        readingassessmentid: <?php echo $readingassessment->id; ?>,
        cmid: <?php echo $cm->id; ?>,
        userid: <?php echo $USER->id; ?>,
        passage: <?php echo json_encode($readingassessment->passage); ?>,
        questions: <?php echo json_encode($custom_questions); ?>,
        tts_voice: <?php echo json_encode($readingassessment->tts_voice ?? 'alloy'); ?>,
        tts_personality_prompt: <?php echo json_encode($readingassessment->tts_personality_prompt ?? ''); ?>,
        mastery_repetitions: <?php echo $mastery_rep_value; ?>,
        sesskey: "<?php echo sesskey(); ?>",
        wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
        asr_service_url: <?php echo json_encode($asr_service_url); ?>
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
