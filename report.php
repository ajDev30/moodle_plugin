<?php
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$cmid = optional_param('id', 0, PARAM_INT); // Course Module ID (optional)
$courseid = optional_param('courseid', 0, PARAM_INT); // Course ID (optional)
$selected_raid = optional_param('raid', 0, PARAM_INT); // Specific reading assessment filter
$action = optional_param('action', '', PARAM_ALPHA);

if ($cmid > 0) {
    $cm = get_coursemodule_from_id('readingassessment', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $readingassessment = $DB->get_record('readingassessment', ['id' => $cm->instance], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);
    $coursecontext = context_course::instance($course->id);
    $current_raid = $readingassessment->id;
} else if ($courseid > 0) {
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $coursecontext = context_course::instance($course->id);
    $context = $coursecontext;
    $current_raid = $selected_raid;
    $readingassessment = ($selected_raid > 0) ? $DB->get_record('readingassessment', ['id' => $selected_raid]) : null;
    $cm = null;
} else {
    throw new \moodle_exception('invalidcoursemodule');
}

require_login($course);
require_capability('mod/readingassessment:grade', $context);

// Fetch all reading assessments in this course for filter selector
$all_course_ras = $DB->get_records('readingassessment', ['course' => $course->id], 'name ASC');

// Build SQL query for attempts
$params = ['courseid' => $course->id];
$where_clauses = ["ra.course = :courseid"];

if ($current_raid > 0) {
    $where_clauses[] = "a.readingassessmentid = :raid";
    $params['raid'] = $current_raid;
}

$where_sql = implode(" AND ", $where_clauses);

// Handle CSV Export
if ($action === 'export_csv') {
    $sql = "SELECT a.*, u.firstname, u.lastname, u.email, ra.name as activityname
            FROM {readingassessment_attempts} a
            JOIN {user} u ON a.userid = u.id
            JOIN {readingassessment} ra ON a.readingassessmentid = ra.id
            WHERE {$where_sql}
            ORDER BY a.timecompleted DESC";
    $attempts = $DB->get_records_sql($sql, $params);

    $title_part = $readingassessment ? clean_filename($readingassessment->name) : 'all_activities';
    $filename = 'ARAL_reading_progress_' . $title_part . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Attempt ID', 'Activity Name', 'First Name', 'Last Name', 'Email', 'Attempt #', 'Reading Speed (WPM)', 'Accuracy (%)', 'Reading Time (s)', 'Comprehension (%)', 'Final Grade (%)', 'Date Completed']);

    foreach ($attempts as $att) {
        fputcsv($out, [
            $att->id,
            $att->activityname,
            $att->firstname,
            $att->lastname,
            $att->email,
            $att->attempt,
            $att->reading_speed ?? 0,
            $att->accuracy_score,
            $att->reading_time ?? 0,
            $att->comprehension_score,
            $att->final_grade,
            userdate($att->timecompleted)
        ]);
    }
    fclose($out);
    exit;
}

// Set up page
$page_params = $cmid > 0 ? ['id' => $cmid] : ['courseid' => $course->id];
if ($selected_raid > 0) {
    $page_params['raid'] = $selected_raid;
}
$PAGE->set_url('/mod/readingassessment/report.php', $page_params);
$PAGE->set_title('ARAL Program: Student Reading Progress - ' . format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->requires->css('/mod/readingassessment/styles.css');

// Fetch attempts
$sql = "SELECT a.*, u.firstname, u.lastname, u.email, u.picture, u.imagealt, ra.name as activityname
        FROM {readingassessment_attempts} a
        JOIN {user} u ON a.userid = u.id
        JOIN {readingassessment} ra ON a.readingassessmentid = ra.id
        WHERE {$where_sql}
        ORDER BY a.timecompleted DESC";
$attempts = $DB->get_records_sql($sql, $params);

// Calculate Class KPIs
$total_attempts = count($attempts);
$unique_students = [];
$total_wpm = 0;
$valid_wpm_count = 0;
$total_acc = 0;
$total_grade = 0;

foreach ($attempts as $att) {
    $unique_students[$att->userid] = true;
    if (!empty($att->reading_speed) && (float)$att->reading_speed > 0) {
        $total_wpm += (float)$att->reading_speed;
        $valid_wpm_count++;
    }
    $total_acc += (float)$att->accuracy_score;
    $total_grade += (float)$att->final_grade;
}

$class_avg_wpm = ($valid_wpm_count > 0) ? round($total_wpm / $valid_wpm_count, 1) : 0;
$class_avg_acc = ($total_attempts > 0) ? round($total_acc / $total_attempts, 1) : 0;
$class_avg_grade = ($total_attempts > 0) ? round($total_grade / $total_attempts, 1) : 0;
$student_count = count($unique_students);

echo $OUTPUT->header();
?>

<div class="reading-assessment-container">

    <!-- Top Navigation Breadcrumbs -->
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
        <div>
            <a href="<?php echo new moodle_url('/grade/report/grader/index.php', ['id' => $course->id]); ?>" class="ra-btn ra-btn-retry" style="display: inline-flex; align-items: center; gap: 6px; text-decoration: none; padding: 8px 16px; margin-bottom: 8px; font-weight: 600;">
                <span>←</span> Back to Grader Report
            </a>
            <h2 style="margin: 0; font-size: 1.85rem; color: #1e293b; display: flex; align-items: center; gap: 10px;">
                <span>📖</span> ARAL Program: Student Reading Progress
            </h2>
            <div style="color: #64748b; font-size: 1.05rem; margin-top: 4px;">
                <?php echo format_string($course->fullname); ?>
                <?php if ($readingassessment): ?>
                    &bull; <strong><?php echo format_string($readingassessment->name); ?></strong>
                <?php endif; ?>
            </div>
        </div>
        <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
            <!-- Activity Filter Selector -->
            <?php if (count($all_course_ras) > 1): ?>
                <form method="get" action="<?php echo new moodle_url('/mod/readingassessment/report.php'); ?>" style="display: inline-flex; align-items: center; gap: 8px;">
                    <input type="hidden" name="courseid" value="<?php echo $course->id; ?>">
                    <select name="raid" onchange="this.form.submit()" style="padding: 9px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #ffffff; font-weight: 600; color: #334155;">
                        <option value="0">📚 All Reading Activities (<?php echo count($all_course_ras); ?>)</option>
                        <?php foreach ($all_course_ras as $ra_opt): ?>
                            <option value="<?php echo $ra_opt->id; ?>" <?php echo ($current_raid == $ra_opt->id) ? 'selected' : ''; ?>>
                                <?php echo s($ra_opt->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>

            <?php
            $export_params = $page_params;
            $export_params['action'] = 'export_csv';
            ?>
            <a href="<?php echo new moodle_url('/mod/readingassessment/report.php', $export_params); ?>" class="ra-btn ra-btn-start" style="text-decoration: none; display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px;">
                <span>📥</span> Export CSV Report
            </a>
        </div>
    </div>

    <!-- Class KPI Summary Grid -->
    <div class="ra-kpi-grid">
        <div class="ra-kpi-card">
            <div class="ra-kpi-icon" style="background: #e0f2fe; color: #0284c7;">⚡</div>
            <div class="ra-kpi-val"><?php echo $class_avg_wpm; ?> <span style="font-size: 1rem; font-weight: 500;">WPM</span></div>
            <div class="ra-kpi-label">Class Average Speed</div>
        </div>
        <div class="ra-kpi-card">
            <div class="ra-kpi-icon" style="background: #dcfce7; color: #15803d;">🎯</div>
            <div class="ra-kpi-val"><?php echo $class_avg_acc; ?>%</div>
            <div class="ra-kpi-label">Class Average Accuracy</div>
        </div>
        <div class="ra-kpi-card">
            <div class="ra-kpi-icon" style="background: #fef3c7; color: #b45309;">👥</div>
            <div class="ra-kpi-val"><?php echo $student_count; ?></div>
            <div class="ra-kpi-label">Students Completed</div>
        </div>
        <div class="ra-kpi-card">
            <div class="ra-kpi-icon" style="background: #ede9fe; color: #7c3aed;">📝</div>
            <div class="ra-kpi-val"><?php echo $total_attempts; ?></div>
            <div class="ra-kpi-label">Total Attempts Submitted</div>
        </div>
    </div>

    <!-- Submissions Table -->
    <div class="ra-card" style="padding: 24px; margin-top: 24px;">
        <div class="ra-card-title" style="margin-bottom: 16px;">📋 Student Reading Performance & Spoken Breakdown</div>

        <?php if (empty($attempts)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <div style="font-size: 3rem; margin-bottom: 12px;">📭</div>
                <div style="font-size: 1.2rem; font-weight: 600;">No student reading attempts recorded yet.</div>
                <p>When students complete their reading passages, their analytics, WPM speed, and spoken word breakdowns will appear here.</p>
            </div>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table class="ra-report-table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Activity</th>
                            <th>Attempt</th>
                            <th>Reading Speed</th>
                            <th>Accuracy</th>
                            <th>Duration</th>
                            <th>Comprehension</th>
                            <th>Final Grade</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attempts as $att): ?>
                            <?php
                            $user = (object)[
                                'id' => $att->userid,
                                'firstname' => $att->firstname,
                                'lastname' => $att->lastname,
                                'email' => $att->email,
                                'picture' => $att->picture,
                                'imagealt' => $att->imagealt
                            ];
                            $userpic = $OUTPUT->user_picture($user, ['size' => 36]);
                            $fullname = fullname($user);
                            $detail_id = "detail-att-" . $att->id;
                            ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <?php echo $userpic; ?>
                                        <div>
                                            <div style="font-weight: 600; color: #1e293b;"><?php echo s($fullname); ?></div>
                                            <div style="font-size: 0.8rem; color: #64748b;"><?php echo s($att->email); ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td style="font-weight: 500; color: #475569;"><?php echo s($att->activityname); ?></td>
                                <td><span class="ra-pill-badge" style="background: #f1f5f9; color: #475569;">#<?php echo $att->attempt; ?></span></td>
                                <td>
                                    <?php if (!empty($att->reading_speed) && (float)$att->reading_speed > 0): ?>
                                        <strong style="color: #0284c7;">⚡ <?php echo $att->reading_speed; ?> WPM</strong>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong style="color: #16a34a;">🎯 <?php echo $att->accuracy_score; ?>%</strong>
                                </td>
                                <td>
                                    <?php echo !empty($att->reading_time) ? $att->reading_time . 's' : 'N/A'; ?>
                                </td>
                                <td><?php echo $att->comprehension_score; ?>%</td>
                                <td>
                                    <span class="ra-score-badge" style="font-size: 0.9rem; padding: 4px 10px;">
                                        <?php echo $att->final_grade; ?>%
                                    </span>
                                </td>
                                <td style="font-size: 0.85rem; color: #64748b;"><?php echo userdate($att->timecompleted, get_string('strftimedatetimeshort', 'core_langconfig')); ?></td>
                                <td>
                                    <button class="ra-btn-detail" onclick="toggleDetailDrawer('<?php echo $detail_id; ?>')">
                                        🔍 View Breakdown
                                    </button>
                                </td>
                            </tr>
                            <!-- Expandable Student Breakdown Row -->
                            <tr id="<?php echo $detail_id; ?>" class="ra-detail-row" style="display: none;">
                                <td colspan="10">
                                    <div class="ra-detail-drawer">
                                        <div style="font-weight: 700; color: #1e293b; margin-bottom: 8px;">
                                            📖 Passage Word Breakdown for <?php echo s($fullname); ?> (<?php echo s($att->activityname); ?> &bull; Attempt #<?php echo $att->attempt; ?>):
                                        </div>
                                        <?php
                                        if (!empty($att->miscues_json)):
                                            $miscues = json_decode($att->miscues_json, true);
                                            if (is_array($miscues)):
                                        ?>
                                            <div style="font-size: 1.15rem; line-height: 2.2; letter-spacing: 0.01em; padding: 14px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 12px;">
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

                                        <?php if (!empty($att->transcript)): ?>
                                            <div style="font-size: 0.9rem; color: #64748b; background: #f8fafc; padding: 10px; border-radius: 6px; border-left: 3px solid #0284c7;">
                                                <strong>🎙️ Spoken ASR Transcript:</strong> "<?php echo s($att->transcript); ?>"
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<script>
function toggleDetailDrawer(id) {
    const row = document.getElementById(id);
    if (row) {
        if (row.style.display === 'none' || row.style.display === '') {
            row.style.display = 'table-row';
        } else {
            row.style.display = 'none';
        }
    }
}
</script>

<?php
echo $OUTPUT->footer();
