<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// For now, require site:config, but ideally this would use a module context
// if it were tied to a course. Since it's external, site:config or a specific capability is good.
require_login();
if (isguestuser()) {
    print_error('noguest');
}

// Allow if site admin or has a teacher/manager role anywhere
$is_admin = is_siteadmin();
$is_teacher = $DB->record_exists_sql("
    SELECT 1 FROM {role_assignments} ra 
    JOIN {role} r ON ra.roleid = r.id 
    WHERE ra.userid = ? AND r.shortname IN ('editingteacher', 'teacher', 'manager')
", [$USER->id]);

if (!$is_admin && !$is_teacher) {
    print_error('nopermissions', 'error', '', 'Access restricted to Teachers and Administrators.');
}

$context = context_system::instance();

$action = optional_param('action', '', PARAM_TEXT);
// Seed Grade Levels 7-12 if they don't exist
$grades_to_seed = [7, 8, 9, 10, 11, 12];
foreach ($grades_to_seed as $g) {
    if (!$DB->record_exists('readingassessment_ext_pass', ['grade_level' => $g])) {
        $new_pass = new stdClass();
        $new_pass->title = 'Grade ' . $g . ' Assessment';
        $new_pass->passage = 'Please enter reading text here.';
        $new_pass->questions_json = '[]';
        $new_pass->timecreated = time();
        $new_pass->grade_level = $g;
        $new_pass->sortorder = $g;
        $DB->insert_record('readingassessment_ext_pass', $new_pass);
    }
}


$PAGE->set_url('/mod/readingassessment/external_manage.php');
$PAGE->set_context($context);
$PAGE->set_title('External Screening Test Management');
$PAGE->set_heading('External Screening Test Dashboard');

echo $OUTPUT->header();

$public_url = new moodle_url('/mod/readingassessment/external.php');

// Get stats
$total_profiles = $DB->count_records('readingassessment_ext_prof');
$total_attempts = $DB->count_records('readingassessment_ext_att');

// Recent attempts
$sql = "SELECT att.id, prof.fullname, prof.lrn, prof.gender, prof.age, prof.grade_level, pass.title AS passage_title, pass.questions_json, att.accuracy_score, att.comprehension_score, att.classification, att.timecompleted, att.level_idx, att.answers_json, att.miscues_json 
        FROM {readingassessment_ext_att} att
        JOIN {readingassessment_ext_prof} prof ON att.profileid = prof.id
        LEFT JOIN {readingassessment_ext_pass} pass ON prof.grade_level = pass.id
        ORDER BY att.timecompleted DESC LIMIT 50";
$recent_attempts = $DB->get_records_sql($sql);

?>

<div class="container-fluid mt-4">
    <div class="row">
        <!-- Sidebar / Setup -->
        <div class="col-md-4">
            <div class="card mb-4 shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">⚙️ Setup Passages</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">Configure the reading texts and questions for each grade level.</p>
                    <div class="list-group">
                        <?php 
                        $passages = $DB->get_records('readingassessment_ext_pass', null, 'sortorder ASC, id ASC');
                        foreach ($passages as $p): 
                            $disp = !empty($p->title) ? s($p->title) : "Grade " . $p->grade_level . " Passage";
                        ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo $disp; ?></strong>
                                </div>
                                <div>
                                    <a href="external_edit.php?grade=<?php echo $p->id; ?>" class="btn btn-sm btn-primary ml-2">Edit ➔</a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($passages)): ?>
                            <div class="list-group-item text-muted text-center">No passages configured yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">🔗 Public Share Link</h5>
                </div>
                <div class="card-body text-center">
                    <p class="text-muted">Distribute this link to students. No Moodle login required.</p>
                    <div class="input-group mb-3">
                        <input type="text" id="publicLink" class="form-control" value="<?php echo $public_url; ?>" readonly>
                        <div class="input-group-append">
                            <button class="btn btn-outline-secondary" type="button" onclick="copyLink()">Copy</button>
                        </div>
                    </div>
                    <div class="alert alert-info py-2">
                        <strong>Stats:</strong> <?php echo $total_profiles; ?> Profiles created, <?php echo $total_attempts; ?> Assessments completed.
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content / Results -->
        <div class="col-md-8">
            <div class="card shadow-sm">
                <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📊 Recent Submissions</h5>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($recent_attempts)): ?>
                        <div class="p-4 text-center text-muted">No external test submissions yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Grade</th>
                                        <th>Acc.</th>
                                        <th>Comp.</th>
                                        <th>Classification</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_attempts as $att): ?>
                                        <tr>
                                            <td><?php echo userdate($att->timecompleted, '%b %d, %H:%M'); ?></td>
                                            <td>
                                                <strong><?php echo s($att->fullname); ?></strong><br>
                                                <small class="text-muted">LRN: <?php echo s($att->lrn); ?></small><br>
                                                <small class="text-muted"><?php echo s($att->gender); ?>, <?php echo s($att->age); ?> yrs old</small>
                                            </td>
                                            <td>
                                                <?php echo !empty($att->passage_title) ? s($att->passage_title) : 'Grade ' . $att->grade_level; ?>
                                                <br><small class="text-muted">Level <?php echo (isset($att->level_idx) ? $att->level_idx : 0) + 1; ?></small>
                                            </td>
                                            <td><?php echo $att->accuracy_score; ?>%</td>
                                            <td><?php echo $att->comprehension_score; ?>%</td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    if ($att->classification === 'Independent') echo 'success';
                                                    else if ($att->classification === 'Instructional') echo 'info';
                                                    else if ($att->classification === 'Frustration') echo 'warning';
                                                    else if ($att->classification === 'Non-Reader') echo 'danger';
                                                    else echo 'secondary';
                                                ?>">
                                                    <?php echo s($att->classification ?: 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewAnswers(this)" data-answers="<?php echo s($att->answers_json); ?>" data-questions="<?php echo s($att->questions_json); ?>" data-miscues="<?php echo s($att->miscues_json); ?>" data-student="<?php echo s($att->fullname); ?>">View</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="answersModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Student Submission: <span id="modalStudentName"></span></h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body" id="modalAnswersBody">
        <p class="text-muted">Loading...</p>
      </div>
    </div>
  </div>
</div>

<script>
function copyLink() {
    var copyText = document.getElementById("publicLink");
    copyText.select();
    copyText.setSelectionRange(0, 99999);
    document.execCommand("copy");
    alert("Copied link: " + copyText.value);
}

function viewAnswers(btn) {
    const student = btn.getAttribute('data-student');
    const answersRaw = btn.getAttribute('data-answers');
    const questionsRaw = btn.getAttribute('data-questions');
    
    document.getElementById('modalStudentName').textContent = student;
    const body = document.getElementById('modalAnswersBody');
    
    try {
        const answers = JSON.parse(answersRaw || '{}');
        const questions = JSON.parse(questionsRaw || '[]');
        
        function escapeHtml(str) {
            return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
        }
        
        let html = '<h6 class="font-weight-bold mb-3">Comprehension Answers</h6>';
        
        if (Object.keys(answers).length === 0) {
            html += '<p class="text-muted">No answers recorded.</p>';
        } else {
            html += '<table class="table table-bordered table-sm">';
            html += '<thead class="thead-light"><tr><th style="width: 40%">Question</th><th>Student\'s Answer</th><th>Status</th></tr></thead><tbody>';
            for (const [qIdx, ans] of Object.entries(answers)) {
                let displayAns = ans;
                let questionText = `Question ${parseInt(qIdx) + 1}`;
                let statusHtml = '-';
                
                const q = questions[qIdx];
                if (q) {
                    questionText = q.question || questionText;
                    
                    if (q.type === 'multichoice') {
                        let optIdx = parseInt(ans);
                        if (q.options && q.options[optIdx] !== undefined) {
                            displayAns = q.options[optIdx];
                        }
                        if (optIdx === parseInt(q.correct)) {
                            statusHtml = '<span class="badge badge-success">Correct</span>';
                        } else {
                            statusHtml = '<span class="badge badge-danger">Incorrect</span>';
                        }
                    } else if (q.type === 'truefalse') {
                        let boolAns = (ans === 'true' || ans === true);
                        displayAns = boolAns ? "True" : "False";
                        let boolCorrect = (q.correct === 'true' || q.correct === true);
                        if (boolAns === boolCorrect) {
                            statusHtml = '<span class="badge badge-success">Correct</span>';
                        } else {
                            statusHtml = '<span class="badge badge-danger">Incorrect</span>';
                        }
                    } else if (q.type === 'matching') {
                        if (typeof ans === 'object') {
                            displayAns = "<ul>";
                            let allCorrect = true;
                            for (const [pIdx, matchVal] of Object.entries(ans)) {
                                displayAns += `<li>Match ${parseInt(pIdx)+1}: ${escapeHtml(matchVal)}</li>`;
                                if (q.pairs && q.pairs[pIdx]) {
                                    if (matchVal !== q.pairs[pIdx].answer) {
                                        allCorrect = false;
                                    }
                                }
                            }
                            displayAns += "</ul>";
                            statusHtml = allCorrect ? '<span class="badge badge-success">Correct</span>' : '<span class="badge badge-warning">Partial/Incorrect</span>';
                        }
                    } else if (q.type === 'shortanswer' || q.type === 'essay') {
                        statusHtml = '<span class="badge badge-secondary">Manual Grade</span>';
                    }
                }
                
                // If it's matching, displayAns already has HTML. Otherwise escape it.
                if (q && q.type === 'matching') {
                    html += `<tr><td><small>${escapeHtml(questionText)}</small></td><td>${displayAns}</td><td>${statusHtml}</td></tr>`;
                } else {
                    html += `<tr><td><small>${escapeHtml(questionText)}</small></td><td><strong>${escapeHtml(displayAns)}</strong></td><td>${statusHtml}</td></tr>`;
                }
            }
            html += '</tbody></table>';
        }
        
        body.innerHTML = html;
    } catch(e) {
        body.innerHTML = '<p class="text-danger">Error parsing answers.</p>';
    }
    
    $('#answersModal').modal('show');
}
</script>

<?php
echo $OUTPUT->footer();
