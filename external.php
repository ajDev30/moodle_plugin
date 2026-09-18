<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$token = optional_param('token', '', PARAM_ALPHANUMEXT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url('/mod/readingassessment/external.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('ARAL Reading Program - External Screening Test');
$PAGE->set_heading('ARAL Reading Program - External Screening');

// Handle Intake Form Submission
if ($action === 'intake' && data_submitted()) {
    $fullname = optional_param('fullname', '', PARAM_TEXT);
    $lrn = optional_param('lrn', '', PARAM_TEXT);
    $gender = optional_param('gender', '', PARAM_TEXT);
    $age = optional_param('age', 0, PARAM_INT);
    $grade = optional_param('grade_level', 7, PARAM_INT);
    $consent = optional_param('consent', 0, PARAM_INT);

    if (empty($fullname) || empty($lrn) || empty($gender) || empty($age) || !$consent || empty($grade)) {
        redirect(new moodle_url('/mod/readingassessment/external.php'), 'Please fill all required fields and agree to the consent.', null, \core\output\notification::NOTIFY_ERROR);
    }

    $new_token = bin2hex(random_bytes(16));

    $profile = new stdClass();
    $profile->token = $new_token;
    $profile->fullname = $fullname;
    $profile->lrn = $lrn;
    $profile->gender = $gender;
    $profile->age = $age;
    $profile->grade_level = $grade;
    $profile->consent_agreed = $consent;
    $profile->timecreated = time();

    $DB->insert_record('readingassessment_ext_prof', $profile);

    redirect(new moodle_url('/mod/readingassessment/external.php', ['token' => $new_token]));
}

$PAGE->requires->css('/mod/readingassessment/styles.css');
echo $OUTPUT->header();

if (empty($token)) {
    // STATE 1: No Token -> Show Intake & Consent Form
    ?>
    <div class="container mt-5" style="max-width: 700px;">
        <div class="card shadow-lg border-0 rounded-lg">
            <div class="card-header bg-primary text-white text-center py-4">
                <h3 class="mb-0">📖 ARAL Program Screening</h3>
                <p class="mb-0 text-white-50">Reading Fluency & Comprehension Assessment</p>
            </div>
            <div class="card-body p-5">
                <div class="alert alert-info border-info mb-4" style="background-color: #f8fbff;">
                    <h5 class="alert-heading font-weight-bold">🎙️ Privacy Notice & Audio Consent</h5>
                    <p class="mb-0 text-dark">This assessment uses your microphone to analyze your reading fluency in real-time. Your voice is securely processed by the AI and is <strong>not permanently recorded or saved</strong>. Only your reading speed, accuracy score, and comprehension answers are stored to help teachers evaluate your progress.</p>
                </div>

                <form action="external.php" method="POST">
                    <input type="hidden" name="action" value="intake">
                    
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary">Full Name</label>
                        <input type="text" name="fullname" class="form-control form-control-lg" placeholder="Enter your full name" required>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary">Learner Reference Number (LRN)</label>
                        <input type="text" name="lrn" class="form-control form-control-lg" placeholder="12-digit LRN" required pattern="\d{12}">
                    </div>

                    <div class="row">
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Gender</label>
                            <select name="gender" class="form-control form-control-lg" required>
                                <option value="" disabled selected>Select gender...</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group mb-4">
                            <label class="font-weight-bold text-secondary">Age</label>
                            <input type="number" name="age" class="form-control form-control-lg" placeholder="Age" min="5" max="99" required>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-secondary">Grade Level</label>
                        <select name="grade_level" class="form-control form-control-lg" required>
                            <option value="" disabled selected>Select your grade...</option>
                            <option value="7">Grade 7</option>
                            <option value="8">Grade 8</option>
                            <option value="9">Grade 9</option>
                            <option value="10">Grade 10</option>
                        </select>
                    </div>

                    <div class="form-check mb-4">
                        <input type="checkbox" class="form-check-input" id="consentCheck" name="consent" value="1" required style="width: 1.2rem; height: 1.2rem; margin-top: 0.2rem;">
                        <label class="form-check-label font-weight-bold ml-2 text-dark" for="consentCheck">
                            I agree to allow microphone access for this reading assessment.
                        </label>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg btn-block font-weight-bold shadow-sm">Start Assessment ➔</button>
                </form>
            </div>
        </div>
    </div>
    <?php
} else {
    // STATE 2: Token Provided -> Assessment UI
    $profile = $DB->get_record('readingassessment_ext_prof', ['token' => $token]);
    if (!$profile) {
        print_error('Invalid or expired token.');
    }

    $passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $profile->grade_level]);
    if (!$passage_record || empty($passage_record->passage)) {
        echo '<div class="alert alert-warning m-4">No reading passage configured. Please contact your teacher.</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Check expiration
    if ($passage_record->expiration_time > 0 && time() > $passage_record->expiration_time) {
        echo '<div class="container mt-5 text-center">';
        echo '<h2 class="text-danger mb-3">⏳ Link Expired</h2>';
        echo '<p class="lead text-muted">The deadline for this reading assessment has passed.</p>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Check attempt limits using LRN and Grade
    $sql_attempts = "SELECT COUNT(*) FROM {readingassessment_ext_att} a 
                     JOIN {readingassessment_ext_prof} p ON a.profileid = p.id 
                     WHERE p.lrn = ? AND p.grade_level = ?";
    $attempt_count = $DB->count_records_sql($sql_attempts, [$profile->lrn, $profile->grade_level]);
    
    if ($attempt_count >= $passage_record->max_attempts) {
        echo '<div class="container mt-5 text-center">';
        echo '<h2 class="text-warning mb-3">⚠️ Maximum Attempts Reached</h2>';
        echo '<p class="lead text-muted">You have already completed the maximum allowed attempts (' . $passage_record->max_attempts . ') for this assessment.</p>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }

    // Check attempts for this profile
    $attempts = $DB->get_records('readingassessment_ext_att', ['profileid' => $profile->id], 'level_idx ASC');
    $starting_level = 0;
    
    if (!empty($attempts)) {
        $last_attempt = end($attempts);
        if ($last_attempt->classification === 'Independent' || $last_attempt->classification === 'Instructional' || count($attempts) >= 4) {
            echo '<div class="container mt-5 text-center">';
            echo '<h2 class="text-success mb-3">✅ Assessment Completed</h2>';
            echo '<p class="lead text-muted">You have already completed this screening test. Thank you!</p>';
            echo '</div>';
            echo $OUTPUT->footer();
            exit;
        } else {
            // They failed the last attempt, resume from next level
            $starting_level = $last_attempt->level_idx + 1;
        }
    }

    $custom_questions = json_decode($passage_record->questions_json, true) ?: [];

    // Determine Dynamic Service Endpoint URL
    $serviceport = get_config('readingassessment', 'service_port') ?: 8010;
    $server_host = parse_url($CFG->wwwroot, PHP_URL_HOST) ?: 'localhost';
    $asr_service_url = "http://{$server_host}:{$serviceport}";

    $PAGE->requires->js('/mod/readingassessment/js/external_assessment.js');
    ?>

    <div class="reading-assessment-container" style="max-width: 900px; margin: 0 auto;">
        <div class="alert alert-primary d-flex justify-content-between align-items-center mb-4">
            <div>
                <strong>Student:</strong> <?php echo s($profile->fullname); ?> (LRN: <?php echo s($profile->lrn); ?>)
            </div>
            <span class="badge badge-light p-2">Grade <?php echo $profile->grade_level; ?> Screening</span>
        </div>

        <div class="ra-card shadow-sm border-0">
            <div class="ra-card-title bg-light p-3 border-bottom mb-0">📖 Reading Passage</div>
            <div class="ra-passage-box p-4" id="ra-passage-text" style="font-size: 1.25rem; line-height: 1.8;">
                <?php echo nl2br(s($passage_record->passage)); ?>
            </div>
            <!-- Removed ra-pronunciation-results completely as it was confusing for students and is now in the teacher dashboard -->

            <!-- Coach Mode Isolation Card -->
            <div id="ra-isolation-card" class="ra-isolation-card" style="display: none; position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 9999; width: 90%; max-width: 500px; border: 2px solid #8b5cf6; border-radius: 12px; background: #f5f3ff; box-shadow: 0 15px 50px rgba(0,0,0,0.4);">
                <div class="ra-isolation-header" style="background: #8b5cf6; color: white; padding: 12px; font-weight: 700; text-align: center; border-radius: 10px 10px 0 0;">
                    👩‍🏫 Teacher Sound-Out Guide
                </div>
                <div style="padding: 24px; text-align: center;">
                    <div id="ra-iso-target-word" style="font-size: 3.5rem; font-weight: 900; color: #4c1d95; margin-bottom: 15px; letter-spacing: 0.05em;"></div>
                    <div class="ra-phonetic-badge-container" style="display: flex; justify-content: center; gap: 15px; margin-bottom: 25px;">
                        <span id="ra-iso-phonetic" style="background: white; padding: 6px 12px; border-radius: 8px; border: 1px solid #ddd; font-family: monospace; font-size: 1.2rem; color: #555;"></span>
                        <span id="ra-iso-ipa" style="background: #ede9fe; padding: 6px 12px; border-radius: 8px; border: 1px solid #c4b5fd; font-family: monospace; font-size: 1.2rem; color: #6d28d9;"></span>
                    </div>
                    <div id="ra-iso-syllables" style="display: flex; justify-content: center; gap: 10px; margin-bottom: 25px; flex-wrap: wrap;"></div>
                    <div id="ra-iso-transcript" style="text-align: center; color: #64748b; font-style: italic; min-height: 24px; margin-bottom: 15px;">(Listening...)</div>
                    <div class="ra-blending-actions" style="display: flex; justify-content: center; gap: 10px;">
                        <button class="btn btn-warning font-weight-bold shadow-sm" onclick="playCurrentCoachWord()">
                            🔊 Hear Word
                        </button>
                        <button class="btn btn-outline-danger font-weight-bold shadow-sm" onclick="forceDismissCoachMode()">
                            Skip ➡️
                        </button>
                    </div>
                </div>
            </div>

            <div class="ra-controls p-4 bg-light text-center border-top">
                <button id="ra-btn-start" class="btn btn-success btn-lg px-5 font-weight-bold shadow-sm rounded-pill">
                    🎙️ Start Reading
                </button>
            </div>

            <div class="ra-status-bar bg-white text-center p-3 border-top" id="ra-vad-indicator">
                <div class="ra-vad-dot d-inline-block bg-secondary rounded-circle mr-2" style="width: 12px; height: 12px;"></div>
                <span id="ra-status-text" class="text-muted font-weight-bold">Click [Start Reading] when you are ready.</span>
            </div>

            <div class="p-3 bg-light text-muted font-italic text-center" id="ra-live-transcript" style="min-height: 50px;"></div>
        </div>

        <!-- Custom Questions Section -->
        <?php echo readingassessment_render_questions($custom_questions, 'ra-quiz-form', 'ra-btn-submit'); ?>

    </div>

    <!-- Results Modal (replaces standard Moodle view.php reload) -->
    <div id="external-thank-you" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.85); z-index: 9999; justify-content: center; align-items: center; text-align: center;">
        <div style="background: white; padding: 40px; border-radius: 12px; max-width: 600px; width: 90%;">
            <h2 class="text-success mb-3">✅ Assessment Submitted!</h2>
            <p class="lead text-muted mb-4">Your reading fluency and comprehension answers have been securely recorded. You may now close this page.</p>
            <div style="margin: 20px 0; padding: 20px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0;">
                <h4 style="margin-bottom: 15px; color: #1e40af;">Oral Reading Profile</h4>
                <div style="font-size: 1.5rem; font-weight: bold; text-transform: uppercase; color: #0f172a; margin-bottom: 15px;" id="final-classification">PENDING</div>
                <div style="display: flex; justify-content: space-around; text-align: center;">
                    <div><div style="font-size: 0.9rem; color: #64748b;">Word Reading</div><div style="font-weight: bold; font-size: 1.2rem;" id="final-word-score">-</div></div>
                    <div><div style="font-size: 0.9rem; color: #64748b;">Comprehension</div><div style="font-weight: bold; font-size: 1.2rem;" id="final-comp-score">-</div></div>
                    <div><div style="font-size: 0.9rem; color: #64748b;">Speed (WPM)</div><div style="font-weight: bold; font-size: 1.2rem;" id="final-wpm-score">-</div></div>
                </div>
                <div id="aral-status-message" style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0;"></div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        if (typeof ExternalReadingAssessment !== 'undefined') {
            ExternalReadingAssessment.init({
                token: <?php echo json_encode($token); ?>,
                passages: <?php echo json_encode([$passage_record->passage, $passage_record->passage_2, $passage_record->passage_3, $passage_record->passage_4]); ?>,
                questions: <?php echo json_encode($custom_questions); ?>,
                starting_level: <?php echo $starting_level; ?>,
                wwwroot: <?php echo json_encode($CFG->wwwroot); ?>,
                asr_service_url: <?php echo json_encode($asr_service_url); ?>
            });
        }
    });
    </script>
    <?php
}

echo $OUTPUT->footer();
