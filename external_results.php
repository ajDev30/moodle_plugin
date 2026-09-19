<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$token = required_param('token', PARAM_ALPHANUMEXT);

$PAGE->set_url('/mod/readingassessment/external_results.php', ['token' => $token]);
$PAGE->set_context(context_system::instance());
$PAGE->set_title('ARAL Reading Program - Assessment Results');
$PAGE->set_heading('ARAL Reading Program - Assessment Results');

$PAGE->requires->css('/mod/readingassessment/styles.css');
echo $OUTPUT->header();

$profile = $DB->get_record('readingassessment_ext_prof', ['token' => $token]);
if (!$profile) {
    echo '<div class="alert alert-danger text-center">Invalid or expired token.</div>';
    echo $OUTPUT->footer();
    exit;
}

$attempts = $DB->get_records('readingassessment_ext_att', ['profileid' => $profile->id], 'level_idx ASC');

if (empty($attempts)) {
    echo '<div class="container mt-5 text-center">';
    echo '<h2 class="text-warning mb-3">⚠️ No Results Found</h2>';
    echo '<p class="lead text-muted">You have not completed any assessments yet.</p>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

$last_attempt = end($attempts);

// Check if 2 days have passed since completion
$timecompleted = $last_attempt->timecompleted ?? 0;
if (strpos($last_attempt->classification, 'Pending') === false && strpos($last_attempt->classification, 'Manual') === false) {
    if ($timecompleted > 0 && (time() - $timecompleted) > (2 * 24 * 60 * 60)) {
        echo '<div class="container mt-5 text-center">';
        echo '<h2 class="text-danger mb-3">❌ Link Expired</h2>';
        echo '<p class="lead text-muted">This assessment link has expired (2 days after grading).</p>';
        echo '</div>';
        echo $OUTPUT->footer();
        exit;
    }
}

if (strpos($last_attempt->classification, 'Pending') !== false || strpos($last_attempt->classification, 'Manual') !== false) {
    echo '<div class="container mt-5 text-center">';
    echo '<h2 class="text-warning mb-3" style="font-size: 3rem;">⏳ Pending Review</h2>';
    echo '<p class="lead text-muted mt-4">Your teacher is currently reviewing your essay/short answers.</p>';
    echo '<div class="alert alert-info d-inline-block p-4 mt-3 rounded shadow-sm border-info">';
    echo '<h5 class="text-secondary mb-1">Current Partial Score:</h5>';
    echo '<h1 class="display-4 font-weight-bold text-info mb-0">' . round($last_attempt->comprehension_score) . '%</h1>';
    echo '</div>';
    echo '<p class="text-muted mt-3">Please check back on this page later to see your final score.</p>';
    echo '</div>';
    echo $OUTPUT->footer();
    exit;
}

echo '<div class="container mt-5 text-center">';
echo '<h2 class="text-success mb-3">🎉 Assessment Completed</h2>';
echo '<p class="lead text-muted">Congratulations, you have finished the ARAL Screening Test!</p>';
echo '<div class="card shadow mt-4 p-5" style="max-width: 600px; margin: 0 auto; background: #f8fafc; border-radius: 15px;">';
echo '<h4 class="text-primary mb-3 text-uppercase" style="letter-spacing: 1px;">Final Official Grade</h4>';
echo '<h1 class="display-3 font-weight-bold text-dark mt-3 mb-4">' . s($last_attempt->classification) . '</h1>';
echo '<hr class="my-4">';
echo '<div class="row text-center mt-3">';
echo '<div class="col-6"><h5 class="text-muted mb-1">Comprehension</h5><h3 class="text-dark font-weight-bold">'.round($last_attempt->comprehension_score).'%</h3></div>';
echo '<div class="col-6"><h5 class="text-muted mb-1">Reading Speed</h5><h3 class="text-dark font-weight-bold">'.round($last_attempt->reading_speed).' WPM</h3></div>';
echo '</div>';
echo '<p class="text-muted mt-5 mb-0">You can safely close this window now.</p>';
echo '</div></div>';
echo $OUTPUT->footer();
