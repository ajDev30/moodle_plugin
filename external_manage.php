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
// Seed Grade Levels 7-10 if they don't exist
$grades_to_seed = [7, 8, 9, 10];
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

// Handle manual grading submission
$action_grade = optional_param('action', '', PARAM_ALPHA);
if ($action_grade === 'manual_grade' && data_submitted()) {
    require_sesskey();
    $att_id = required_param('att_id', PARAM_INT);
    
    $attempt = $DB->get_record('readingassessment_ext_att', ['id' => $att_id]);
    if ($attempt) {
        $answers = json_decode($attempt->answers_json, true) ?: [];
        $profile = $DB->get_record('readingassessment_ext_prof', ['id' => $attempt->profileid]);
        $passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $profile->grade_level]);
        
        $total_earned = 0;
        $total_max = 0;
        
        if ($passage_record && !empty($passage_record->questions_json)) {
            $all_questions = json_decode($passage_record->questions_json, true) ?: [];
            $custom_questions = [];
            foreach ($all_questions as $q) {
                $q_level = isset($q['level_idx']) ? (int)$q['level_idx'] : 0;
                if ($q_level === (int)$attempt->level_idx) {
                    $custom_questions[] = $q;
                }
            }
            
            foreach ($custom_questions as $qidx => $q) {
                $qtype = $q['type'] ?? 'multichoice';
                if ($qtype === 'description') continue;
                
                $ans = $answers[$qidx] ?? null;
                
                if ($qtype === 'shortanswer' || $qtype === 'essay') {
                    $total_max += 1.0;
                    $manual_pts = optional_param('manual_grade_' . $qidx, 0, PARAM_FLOAT);
                    $total_earned += $manual_pts;
                } else if ($qtype === 'multichoice') {
                    $total_max += 1.0;
                    if ($ans !== null && intval($ans) === intval($q['correct'] ?? 0)) $total_earned += 1.0;
                } else if ($qtype === 'truefalse') {
                    $total_max += 1.0;
                    $expected = ($q['correct'] === true || $q['correct'] === 'true' || $q['correct'] === 1);
                    $actual   = ($ans === true || $ans === 'true' || $ans === 1 || strtolower($ans) === 'true');
                    if ($ans !== null && $expected === $actual) $total_earned += 1.0;
                } else if ($qtype === 'matching') {
                    $pairs = $q['pairs'] ?? [];
                    foreach ($pairs as $pidx => $p) {
                        $total_max += 1.0; 
                        $expected_ans = trim(strtolower($p['answer'] ?? ''));
                        $student_val = $ans[$pidx] ?? '';
                        if ($expected_ans !== '' && $expected_ans === trim(strtolower($student_val))) $total_earned += 1.0;
                    }
                }
            }
        }
        
        $comprehension = ($total_max > 0) ? round(($total_earned / $total_max) * 100.0, 2) : 100.0;
        $attempt->comprehension_score = $comprehension;
        
        $current_passage_text = '';
        if ($attempt->level_idx == 0) $current_passage_text = $passage_record->passage;
        else if ($attempt->level_idx == 1) $current_passage_text = $passage_record->passage_2;
        else if ($attempt->level_idx == 2) $current_passage_text = $passage_record->passage_3;
        else if ($attempt->level_idx == 3) $current_passage_text = $passage_record->passage_4;
        
        $passage_words = count(preg_split('/\s+/', preg_replace('/[^a-z0-9]/', ' ', strtolower(trim($current_passage_text)))));
        if ($passage_words == 0) $passage_words = 1;
        
        $miscues_arr = json_decode($attempt->miscues_json, true) ?: [];
        $words_attempted = count($miscues_arr);
        $word_reading_score = round(max(0, (($passage_words - $words_attempted) / $passage_words) * 100), 2);
        
        if ($attempt->level_idx == 3) {
            if ($comprehension >= 80) $classification = 'Listening: Independent';
            else if ($comprehension >= 59) $classification = 'Listening: Instructional';
            else $classification = 'Listening: Frustration';
        } else {
            if ($word_reading_score < 40 || ($attempt->reading_time < 5 && $word_reading_score < 70)) {
                $classification = 'Non-Reader';
            } else if ($word_reading_score >= 97 && $comprehension >= 80) {
                $classification = 'Independent';
            } else if ($word_reading_score >= 90 && $comprehension >= 59) {
                $classification = 'Instructional';
            } else {
                $classification = 'Frustration';
            }
        }
        
        $attempt->classification = $classification;
        $attempt->timecompleted = time();
        $DB->update_record('readingassessment_ext_att', $attempt);
        redirect(new moodle_url('/mod/readingassessment/external_manage.php'), 'Grade saved successfully.', null, '\core\output\notification::NOTIFY_SUCCESS');
    }
}

// Get all profiles with attempts, sorted by latest activity
$sql = "SELECT p.*, MAX(a.timecompleted) as last_completed 
        FROM {readingassessment_ext_prof} p
        JOIN {readingassessment_ext_att} a ON a.profileid = p.id
        GROUP BY p.id, p.token, p.fullname, p.lrn, p.gender, p.age, p.grade_level, p.consent_agreed, p.timecreated
        ORDER BY last_completed DESC LIMIT 50";
$recent_profiles = $DB->get_records_sql($sql);


?>

<div class="container-fluid mt-4">
    <div class="row" id="dashboard-main-view">
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
                                        <?php if (empty($recent_profiles)): ?>
                        <div class="p-4 text-center text-muted">No external test submissions yet.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover table-striped mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Student</th>
                                        <th>Classification</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent_profiles as $prof): 
                                        // Get all attempts for this profile ordered by level
                                        $attempts = $DB->get_records('readingassessment_ext_att', ['profileid' => $prof->id], 'level_idx ASC');
                                        if (empty($attempts)) continue;
                                        $last_att = end($attempts);
                                        
                                        $passage_record = $DB->get_record('readingassessment_ext_pass', ['grade_level' => $prof->grade_level]);
                                        $levelNames = [0 => 'Independent', 1 => 'Instructional', 2 => 'Frustration', 3 => 'Non-Reader'];
?>
                                        <tr>
                                            <td><?php echo userdate($last_att->timecompleted, '%b %d, %H:%M'); ?></td>
                                            <td>
                                                <strong><?php echo s($prof->fullname); ?></strong><br>
                                                <small class="text-muted">LRN: <?php echo s($prof->lrn); ?></small><br>
                                                <small class="text-muted"><?php echo s($prof->gender); ?>, <?php echo s($prof->age); ?> yrs old</small>
                                            </td>
                                            <td>
                                                <span class="badge badge-<?php 
                                                    if (strpos($last_att->classification, 'Independent') !== false) echo 'success';
                                                    else if (strpos($last_att->classification, 'Instructional') !== false) echo 'info';
                                                    else if (strpos($last_att->classification, 'Frustration') !== false) echo 'warning';
                                                    else if (strpos($last_att->classification, 'Non-Reader') !== false) echo 'danger';
                                                    else echo 'secondary';
                                                ?>">
                                                    <?php echo s($last_att->classification ?: 'Pending'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                    $att_data = [];
                                                    for ($i=0; $i<4; $i++) {
                                                        $has_attempt = false;
                                                        foreach ($attempts as $a) {
                                                            if ((int)$a->level_idx === $i) {
                                                                $ptext = '';
                                                                if ($passage_record) {
                                                                    if ($i === 0) $ptext = $passage_record->passage;
                                                                    else if ($i === 1) $ptext = $passage_record->passage_2;
                                                                    else if ($i === 2) $ptext = $passage_record->passage_3;
                                                                    else if ($i === 3) $ptext = $passage_record->passage_4;
                                                                }
                                                                $att_data[] = [
                                                                    'id' => $a->id,
                                                                    'level_idx' => $i,
                                                                    'test_name' => $levelNames[$i],
                                                                    'passage_text' => $ptext,
                                                                    'questions_json' => $passage_record->questions_json ?? '[]',
                                                                    'answers_json' => $a->answers_json,
                                                                    'miscues_json' => $a->miscues_json,
                                                                    'evaluation_data' => $a->evaluation_data,
                                                                    'classification' => $a->classification ?: 'Pending',
                                                                    'transcript' => $a->transcript,
                                                                    'wpm' => $a->reading_speed,
                                                                    'comp' => $a->comprehension_score,
                                                                    'student_name' => $prof->fullname
                                                                ];
                                                                $has_attempt = true;
                                                                break;
                                                            }
                                                        }
                                                    }
                                                    $att_json = json_encode($att_data);
                                                ?>
                                                <button class=\"btn btn-sm btn-outline-primary\" onclick='viewStudentAnswers(this, <?php echo $prof->id; ?>)' data-attempts='<?php echo s($att_json); ?>'>
                                                    View
                                                </button>
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


    <div class="row d-none" id="dashboard-details-view">
        <div class="col-12">
            <div class="card shadow-sm border-0 mb-5">
                <div class="card-header bg-white border-bottom p-3 d-flex align-items-center">
                    <button class="btn btn-outline-secondary mr-3" onclick="closeDetailsView()">⬅️ Back to Submissions</button>
                    <h4 class="mb-0 text-primary" id="details-student-name">Student Name</h4>
                </div>
                <div class="card-body p-4" id="details-content-area" style="background: #f8f9fa;">
                    <!-- JS will render here -->
                </div>
            </div>
        </div>
    </div>

<!-- Modal -->

</div>

<script>
let stateData = {};

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe.toString()
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function viewStudentAnswers(btn, profId) {
    const attemptRaw = btn.getAttribute('data-attempts');
    if (!attemptRaw) return;
    const attempts = JSON.parse(attemptRaw);
    if (attempts.length === 0) return;
    
    stateData['current_prof'] = profId;
    stateData[profId] = attempts;
    
    document.getElementById('details-student-name').innerText = attempts[0].student_name;
    
    document.getElementById('dashboard-main-view').classList.add('d-none');
    document.getElementById('dashboard-details-view').classList.remove('d-none');
    window.scrollTo(0, 0);
    
    renderDetailsBody(0, profId);
}

function closeDetailsView() {
    document.getElementById('dashboard-details-view').classList.add('d-none');
    document.getElementById('dashboard-main-view').classList.remove('d-none');
    window.scrollTo(0, 0);
}

function renderDetailsBody(idx, profId) {
    const container = document.getElementById('details-content-area');
    const attempts = stateData[profId];
    const att = attempts[idx];
    
    let html = '<div class="d-flex w-100 border-bottom mb-4 pb-2 justify-content-center">';
    
    // Navbar for tests
    attempts.forEach((a, i) => {
        const isActive = (i === idx);
        let color = isActive ? '#fff' : '#495057';
        let bg = isActive ? '#0d6efd' : '#e9ecef';
        let shadow = isActive ? 'shadow-sm' : '';
        html += `<div onclick="renderDetailsBody(${i}, ${profId})" class="${shadow}" style="padding: 10px 30px; cursor: pointer; font-weight: bold; border-radius: 5px; color: ${color}; background: ${bg}; margin: 0 10px; transition: all 0.2s;">
                    ${escapeHtml(a.test_name)}
                 </div>`;
    });
    html += '</div>';
    
    // Check if it's Non-Reader (Level 3)
    if (att.level_idx === 3) {
        html += `<div class="row justify-content-center"><div class="col-md-10">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-white"><h5 class="font-weight-bold mb-0 text-primary">📝 Comprehension Test Result</h5></div>
                        <div class="card-body">
                            ${renderComprehension(att)}
                        </div>
                    </div>
                 </div></div>`;
        container.innerHTML = html;
        return;
    }
    
    // STANDARD VIEW
    
    // Metrics Banner
    let evaluationData = null;
    try { evaluationData = JSON.parse(att.evaluation_data || '{}'); } catch(e) {}
    const wordsCount = evaluationData && evaluationData.word_results ? evaluationData.word_results.length : 0;
    
    let miscuesCount = 0;
    if (evaluationData && evaluationData.word_results) {
        evaluationData.word_results.forEach(w => {
            let et = w.error_type || "None";
            if (et === "None" && w.accuracy_score !== undefined && w.accuracy_score < 60) et = "Mispronunciation";
            if (et !== "None" && et !== "Insertion") miscuesCount++;
        });
    }
    let wordsCorrectlyRead = wordsCount > 0 ? (wordsCount - miscuesCount) : 0;
    let percentageCorrect = wordsCount > 0 ? ((wordsCorrectlyRead / wordsCount) * 100).toFixed(2) : 0;
    
    html += `<div class="row justify-content-center mb-4">
                <div class="col-md-10">
                    <div class="d-flex justify-content-around bg-white p-4 border rounded shadow-sm text-center">
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Reading Speed</div><div class="font-weight-bold" style="font-size: 2rem; color: #0d6efd;">${att.wpm || 0} <span style="font-size: 1rem;">WPM</span></div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Words Correct</div><div class="font-weight-bold" style="font-size: 2rem; color: #198754;">${wordsCorrectlyRead} <span style="font-size: 1rem;">/ ${wordsCount}</span></div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Word Accuracy</div><div class="font-weight-bold" style="font-size: 2rem; color: #6610f2;">${percentageCorrect}%</div></div>
                        <div><div class="text-muted small text-uppercase font-weight-bold tracking-wide">Comprehension</div><div class="font-weight-bold" style="font-size: 2rem; color: #fd7e14;">${att.comp || 0}%</div></div>
                    </div>
                </div>
             </div>`;
             
    // Audio Player
    html += `<div class="row justify-content-center mb-4">
                <div class="col-md-10">
                    <audio controls src="serve_audio.php?id=${att.id}" class="w-100 shadow-sm" style="border-radius: 50px;"></audio>
                </div>
             </div>`;
             
    // DROPDOWN SELECTOR
    const accId = 'acc-' + att.id;
    html += `<div class="row justify-content-center mb-3">
                <div class="col-md-10">
                    <select class="form-control form-control-lg shadow-sm font-weight-bold" style="border-radius: 10px; cursor: pointer; border: 2px solid #0d6efd; color: #0d6efd;" onchange="switchSection(this.value, '${att.id}')">
                        <option value="transcript">🎙️ Transcript</option>
                        <option value="pronounce">📊 Pronunciation Assessment</option>
                        <option value="philiri">🗣️ Phil-IRI Style</option>
                        <option value="comprehension">📝 Comprehension Test</option>
                    </select>
                </div>
             </div>`;
             
    html += `<div class="row justify-content-center"><div class="col-md-10">`;
    
    // 1. Transcript
    html += `
        <div id="sec-transcript-${att.id}" class="card border-0 shadow-sm rounded">
            <div class="card-body bg-white rounded" style="font-size: 1.1rem; line-height: 1.8;">
                <h5 class="border-bottom pb-2 mb-3 text-primary">🎙️ Transcript</h5>
                <span class="badge badge-info p-2 mr-2">[00:00]</span> ${escapeHtml(att.transcript || "No transcript available")}
            </div>
        </div>
    `;
    
    // 2. Pronounce Assessment
    html += `
        <div id="sec-pronounce-${att.id}" class="card border-0 shadow-sm rounded d-none">
            <div class="card-body bg-white rounded">
                <h5 class="border-bottom pb-2 mb-3 text-primary">📊 Pronunciation Assessment</h5>
                ${renderAzureDashboard(att)}
            </div>
        </div>
    `;
    
    // 3. Phil-IRI Style
    html += `
        <div id="sec-philiri-${att.id}" class="card border-0 shadow-sm rounded d-none">
            <div class="card-body bg-white rounded">
                <h5 class="border-bottom pb-2 mb-3 text-primary">🗣️ Phil-IRI Style</h5>
                ${renderPhilIri(att)}
            </div>
        </div>
    `;
    
    // 4. Comprehension
    html += `
        <div id="sec-comprehension-${att.id}" class="card border-0 shadow-sm rounded d-none">
            <div class="card-body bg-white rounded p-4">
                <h5 class="border-bottom pb-3 mb-4 text-primary">📝 Comprehension Test</h5>
                ${renderComprehension(att)}
            </div>
        </div>
    `;
    
    html += `</div></div>`; // End sections
    container.innerHTML = html;
}

function switchSection(sectionVal, attId) {
    document.getElementById('sec-transcript-' + attId).classList.add('d-none');
    document.getElementById('sec-pronounce-' + attId).classList.add('d-none');
    document.getElementById('sec-philiri-' + attId).classList.add('d-none');
    document.getElementById('sec-comprehension-' + attId).classList.add('d-none');
    
    document.getElementById('sec-' + sectionVal + '-' + attId).classList.remove('d-none');
}

function renderAzureDashboard(att) {
    let evaluationData = null;
    try { evaluationData = JSON.parse(att.evaluation_data || '{}'); } catch(e) {}
    const finalWords = (evaluationData && evaluationData.word_results) ? evaluationData.word_results : [];
    
    let html = `<div style="font-size: 1.1rem; line-height: 2.2;">`;
    if (finalWords.length > 0) {
        finalWords.forEach(w => {
            let et = w.error_type || "None";
            if (et === "None" && w.accuracy_score !== undefined && w.accuracy_score < 60) et = "Mispronunciation";
            let text = escapeHtml(w.word);
            if (et === "Mispronunciation") {
                html += `<span style="background-color: #ffe082; text-decoration: underline; padding: 2px 5px; border-radius: 4px; font-weight: bold;">${text}</span> `;
            } else if (et === "Omission") {
                html += `<span style="background-color: #9e9e9e; color: white; padding: 2px 5px; border-radius: 4px;">${text}</span> `;
            } else if (et === "Insertion") {
                html += `<span style="background-color: #ef5350; color: white; padding: 2px 5px; border-radius: 4px;">[+] ${escapeHtml(w.spoken_word || w.word)}</span> `;
            } else {
                html += text + " ";
            }
        });
    } else {
        html += `<span class="text-muted small">No data</span>`;
    }
    html += `</div>`;
    return html;
}

function renderPhilIri(att) {
    let evaluationData = null;
    try { evaluationData = JSON.parse(att.evaluation_data || '{}'); } catch(e) {}
    const finalWords = (evaluationData && evaluationData.word_results) ? evaluationData.word_results : [];
    
    let html = `<div style="font-size: 1.25rem; line-height: 2.5; font-family: 'Times New Roman', serif; padding: 15px;">`;
    if (finalWords.length > 0) {
        finalWords.forEach(w => {
            let et = w.error_type || "None";
            if (et === "None" && w.accuracy_score !== undefined && w.accuracy_score < 60) et = "Mispronunciation";
            let word = escapeHtml(w.word);
            let spoken = escapeHtml(w.spoken_word || "");
            
            if (et === "Mispronunciation") {
                html += `<span style="display:inline-block; text-align:center; margin: 0 4px; vertical-align: bottom;">
                            <span style="display:block; font-size:0.8rem; color:#d32f2f; line-height:1; margin-bottom:-4px;">${spoken || '?'}</span>
                            <span style="text-decoration:underline;">${word}</span>
                         </span>`;
            } else if (et === "Omission") {
                html += `<span style="display:inline-block; margin: 0 4px; border: 2px solid #d32f2f; border-radius: 50%; padding: 0 4px;">${word}</span>`;
            } else if (et === "Substitution") {
                html += `<span style="display:inline-block; text-align:center; margin: 0 4px; vertical-align: bottom;">
                            <span style="display:block; font-size:0.8rem; color:#1976d2; line-height:1; margin-bottom:-4px;">${spoken}</span>
                            <span>${word}</span>
                         </span>`;
            } else if (et === "Insertion") {
                html += `<span style="display:inline-block; text-align:center; margin: 0 4px; vertical-align: bottom;">
                            <span style="display:block; font-size:0.8rem; color:#388e3c; line-height:1; margin-bottom:-4px;">${spoken}</span>
                            <span style="color:#388e3c; font-weight:bold;">^</span> ${word}
                         </span>`;
            } else if (et === "Repetition") {
                html += `<span style="display:inline-block; margin: 0 4px; border-bottom: 2px wavy #fbc02d;">${word}</span>`;
            } else {
                html += `<span style="margin: 0 4px;">${word}</span>`;
            }
        });
    } else {
        html += `<span class="text-muted small">No data</span>`;
    }
    html += `</div>`;
    return html;
}

function renderComprehension(att) {
    const answers = JSON.parse(att.answers_json || '{}');
    const questions = JSON.parse(att.questions_json || '[]');
    const isPending = !att.classification || att.classification.includes("Pending");
    
    if (Object.keys(answers).length === 0) return '<div class="text-muted small">No answers.</div>';
    
    let html = '<div style="font-size: 1rem;">';
    html += `<form id="manualGradeForm" method="POST" action="external_manage.php">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="action" value="manual_grade">
                <input type="hidden" name="att_id" value="${att.id}">`;
                
    for (const [qIdx, ans] of Object.entries(answers)) {
        let displayAns = ans;
        let questionText = `Q${parseInt(qIdx) + 1}`;
        let statusHtml = '';
        
        let q = null;
        if (questions.length > 0) {
            let actualQIdx = 0;
            for (let k=0; k<questions.length; k++) {
                if (parseInt(questions[k].level_idx || 0) === att.level_idx) {
                    if (actualQIdx === parseInt(qIdx)) { q = questions[k]; break; }
                    actualQIdx++;
                }
            }
        }
        
        if (q && q.text) questionText = q.text;
        
        if (q) {
            if (q.type === 'description') {
                continue;
            }
            if (q.type === 'multichoice') {
                let optIdx = parseInt(ans);
                if (!isNaN(optIdx) && q.options && q.options[optIdx] !== undefined) {
                    displayAns = escapeHtml(q.options[optIdx]);
                }
                
                if (!isNaN(optIdx) && optIdx === parseInt(q.correct)) {
                    statusHtml = '<span class="badge badge-success p-2">Correct</span>';
                } else if (!isNaN(optIdx)) {
                    statusHtml = '<span class="badge badge-danger p-2">Incorrect</span>';
                }
            } else if (q.type === 'truefalse') {
                let boolAns = (ans === 'true' || ans === true || String(ans).toLowerCase() === 'true');
                displayAns = boolAns ? "True" : "False";
                let boolCorrect = (q.correct === 'true' || q.correct === true || String(q.correct).toLowerCase() === 'true');
                
                if (boolAns === boolCorrect) {
                    statusHtml = '<span class="badge badge-success p-2">Correct</span>';
                } else {
                    statusHtml = '<span class="badge badge-danger p-2">Incorrect</span>';
                }
            } else if (q.type === 'matching') {
                if (q.pairs) {
                    let allCorrect = true;
                    displayAns = "<ul style='margin:10px 0; padding-left:1.5rem;'>";
                    for (const [pIdx, matchVal] of Object.entries(ans)) {
                        displayAns += `<li>${escapeHtml(q.pairs[pIdx].question)} <span class="text-muted">➔</span> <strong>${escapeHtml(matchVal)}</strong></li>`;
                        if (matchVal !== q.pairs[pIdx].answer) allCorrect = false;
                    }
                    displayAns += "</ul>";
                    statusHtml = allCorrect ? '<span class="badge badge-success p-2">Correct</span>' : '<span class="badge badge-warning p-2">Partial/Incorrect</span>';
                }
            } else if (q.type === 'shortanswer' || q.type === 'essay') {
                if (isPending) {
                    statusHtml = `<select name="manual_grade_${qIdx}" class="form-control mt-2" style="max-width: 200px;">
                                    <option value="1">Correct (1 pt)</option>
                                    <option value="0" selected>Incorrect (0 pt)</option>
                                  </select>`;
                } else {
                    statusHtml = '<span class="badge badge-info p-2">Manually Graded</span>';
                }
            }
        }
        
        if (!statusHtml) statusHtml = '<span class="badge badge-secondary p-2">Pending</span>';
        
        html += `<div class="mb-4 pb-3 border-bottom">
                    <strong style="font-size: 1.1rem; color: #343a40;">${escapeHtml(questionText)}</strong><br>
                    <div class="mt-2 text-dark" style="font-size: 1.05rem;">${q && q.type === 'matching' ? displayAns : escapeHtml(displayAns)}</div>
                    <div class="mt-2">${statusHtml}</div>
                 </div>`;
    }
    
    if (isPending) {
        html += `<button type="submit" class="btn btn-primary btn-lg mt-3 w-100 font-weight-bold shadow-sm">Save Final Grade</button>`;
    }
    
    html += '</form></div>';
    return html;
}
</script>

<?php
echo $OUTPUT->footer();
