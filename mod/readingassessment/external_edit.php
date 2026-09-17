<?php
require_once(__DIR__ . '/../../config.php');

require_login();
if (isguestuser()) {
    print_error('noguest');
}
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

$grade = required_param('grade', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url('/mod/readingassessment/external_edit.php', ['grade' => $grade]);
$PAGE->set_context($context);
$PAGE->set_title('Edit External Passage');
$PAGE->set_heading('Edit External Passage');

$record = $DB->get_record('readingassessment_ext_pass', ['id' => $grade]);
if (!$record) {
    // Should not happen since we create the record first, but just in case
    print_error('invalidpassageid');
}

if ($action === 'save' && data_submitted() && confirm_sesskey()) {
    $record->title = optional_param('title', '', PARAM_TEXT);
    $record->passage = optional_param('passage', '', PARAM_TEXT);
    $record->passage_2 = optional_param('passage_2', '', PARAM_TEXT);
    $record->passage_3 = optional_param('passage_3', '', PARAM_TEXT);
    $record->passage_4 = optional_param('passage_4', '', PARAM_TEXT);
    $record->questions_json = optional_param('questions_json', '[]', PARAM_RAW);
    
    // Parse expiration date/time if provided
    $exp_date = optional_param('exp_date', '', PARAM_TEXT);
    $exp_time = optional_param('exp_time', '', PARAM_TEXT);
    if (!empty($exp_date) && !empty($exp_time)) {
        $record->expiration_time = strtotime($exp_date . ' ' . $exp_time);
    } else {
        $record->expiration_time = 0;
    }

    $record->max_attempts = optional_param('max_attempts', 1, PARAM_INT);
    $record->timemodified = time();

    if (empty($record->id)) {
        $record->timecreated = time();
        $record->id = $DB->insert_record('readingassessment_ext_pass', $record);
    } else {
        $DB->update_record('readingassessment_ext_pass', $record);
    }
    
    redirect(new moodle_url('/mod/readingassessment/external_manage.php'), 'Passage saved successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();

$exp_date_val = $record->expiration_time ? date('Y-m-d', $record->expiration_time) : '';
$exp_time_val = $record->expiration_time ? date('H:i', $record->expiration_time) : '';
?>

<div class="container-fluid mt-4" style="max-width: 1000px; margin: 0 auto;">
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">📝 Edit Passage: <?php echo !empty($record->title) ? s($record->title) : "New Passage"; ?></h5>
            <a href="external_manage.php" class="btn btn-sm btn-light">Back to Dashboard</a>
        </div>
        <div class="card-body">
            <form action="external_edit.php" method="POST" id="ext-edit-form">
                <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                <input type="hidden" name="grade" value="<?php echo $grade; ?>">
                <input type="hidden" name="action" value="save">

                <div class="form-group mb-4">
                    <label class="font-weight-bold">Passage Title</label>
                    <input type="text" class="form-control" name="title" value="<?php echo isset($record->title) ? s($record->title) : ''; ?>" placeholder="e.g. Grade 7 Pre-Test" required>
                </div>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="font-weight-bold">Link Expiration (Optional)</label>
                        <div class="input-group">
                            <input type="date" class="form-control" name="exp_date" value="<?php echo $exp_date_val; ?>">
                            <input type="time" class="form-control" name="exp_time" value="<?php echo $exp_time_val; ?>">
                        </div>
                        <small class="text-muted">Leave blank for no expiration.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="font-weight-bold">Max Attempts per Student</label>
                        <input type="number" class="form-control" name="max_attempts" value="<?php echo $record->max_attempts; ?>" min="1" max="100">
                    </div>
                </div>

                <div class="card mb-4 border-info">
                    <div class="card-header bg-info text-white font-weight-bold">
                        📖 Adaptive Reading Passages (Phil-IRI)
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-4">
                            Configure up to 4 passages. The student will start at Level 1 (Independent). If they score "Frustration" or "Non-Reader", the system will automatically drop them to Level 2 (Instructional), and so on.
                        </p>
                        
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Level 1: Independent Passage (Baseline)</label>
                            <textarea class="form-control" name="passage" rows="4" required><?php echo s($record->passage); ?></textarea>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Level 2: Instructional Passage (Step-down 1)</label>
                            <textarea class="form-control" name="passage_2" rows="4"><?php echo isset($record->passage_2) ? s($record->passage_2) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group mb-3">
                            <label class="font-weight-bold">Level 3: Frustration Passage (Step-down 2)</label>
                            <textarea class="form-control" name="passage_3" rows="4"><?php echo isset($record->passage_3) ? s($record->passage_3) : ''; ?></textarea>
                        </div>
                        
                        <div class="form-group mb-0">
                            <label class="font-weight-bold">Level 4: Non-Reader Passage (Step-down 3)</label>
                            <textarea class="form-control" name="passage_4" rows="4"><?php echo isset($record->passage_4) ? s($record->passage_4) : ''; ?></textarea>
                        </div>
                    </div>
                </div>
                <!-- QUESTION BUILDER UI -->
                <div class="form-group mb-4">
                    <label class="font-weight-bold">📋 Comprehension Questions</label>
                    <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 20px; border-radius: 8px;">
                        
                        <!-- Hidden JSON Field -->
                        <input type="hidden" name="questions_json" id="questions_json" value="<?php echo s($record->questions_json); ?>">

                        <div id="ra-q-list" style="display: flex; flex-direction: column; gap: 16px; margin-bottom: 20px;"></div>

                        <div style="display: flex; gap: 10px; align-items: center; border-top: 1px solid #cbd5e1; padding-top: 15px;">
                            <select id="ra-q-type-add" class="form-control" style="width: auto; font-weight: 600;">
                                <option value="description">ℹ️ Description / Instruction / Rubric</option>
                                <option value="multichoice">🔘 Multiple Choice</option>
                                <option value="truefalse">✅ True or False</option>
                                <option value="matching">🔄 Matching</option>
                                <option value="shortanswer">✏️ Short Answer (Manual Grading)</option>
                                <option value="essay">📝 Essay (Manual Grading)</option>
                            </select>
                            <button type="button" class="btn btn-primary font-weight-bold" onclick="addQ()">➕ Add Question</button>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success btn-lg btn-block font-weight-bold">💾 Save Grade <?php echo $grade; ?> Content</button>
            </form>
        </div>
    </div>
</div>

<script>
let questions = [];
try {
    questions = JSON.parse(document.getElementById('questions_json').value) || [];
} catch(e) {
    questions = [];
}

function escapeHtml(str) {
    if (!str) return "";
    return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function syncJSON() {
    document.getElementById('questions_json').value = JSON.stringify(questions);
}

function renderQ() {
    const list = document.getElementById('ra-q-list');
    list.innerHTML = '';

    if (questions.length === 0) {
        list.innerHTML = '<div class="text-muted font-italic text-center py-3">No questions added yet.</div>';
        return;
    }

    questions.forEach((q, idx) => {
        const card = document.createElement('div');
        card.style = "background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; padding: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);";
        
        let typeName = ""; let typeColor = "";
        let contentHtml = "";

        if (q.type === 'multichoice') {
            typeName = "Multiple Choice"; typeColor = "#3b82f6";
            const opts = q.options || ["", ""];
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Question:</label>
                    <textarea class="form-control" rows="2" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem;">Choices (Select the correct one):</div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;">
                    ${opts.map((opt, oidx) => `
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="radio" name="correct_${idx}" ${parseInt(q.correct) === oidx ? "checked" : ""} onchange="updateQProp(${idx}, 'correct', ${oidx})">
                            <input type="text" class="form-control" value="${escapeHtml(opt)}" oninput="updateQOption(${idx}, ${oidx}, this.value)" style="flex: 1;">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQOption(${idx}, ${oidx})">✖</button>
                        </div>
                    `).join('')}
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="addQOption(${idx})">➕ Add Choice</button>
                    <label style="font-weight: 600; font-size: 0.85rem; cursor: pointer;">
                        <input type="checkbox" ${q.shuffle ? "checked" : ""} onchange="updateQProp(${idx}, 'shuffle', this.checked)"> Shuffle Choices
                    </label>
                </div>
            `;
        } else if (q.type === 'truefalse') {
            typeName = "True/False"; typeColor = "#10b981";
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Statement:</label>
                    <textarea class="form-control" rows="2" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div style="font-weight: 600; font-size: 0.85rem;">Correct Answer: 
                    <select class="form-control d-inline-block w-auto ml-2" onchange="updateQProp(${idx}, 'correct', this.value === 'true')">
                        <option value="true" ${q.correct ? "selected" : ""}>True</option>
                        <option value="false" ${!q.correct ? "selected" : ""}>False</option>
                    </select>
                </div>
            `;
        } else if (q.type === 'matching') {
            typeName = "Matching"; typeColor = "#7c3aed";
            const pairs = q.pairs || [{question: "", answer: ""}, {question: "", answer: ""}];
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Instruction:</label>
                    <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, 'question', this.value)">
                </div>
                <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem;">Matching Pairs:</div>
                <div style="display: flex; flex-direction: column; gap: 8px; margin-bottom: 12px;">
                    ${pairs.map((p, pidx) => `
                        <div style="display: flex; gap: 8px; align-items: center;">
                            <input type="text" class="form-control" value="${escapeHtml(p.question)}" oninput="updateQPair(${idx}, ${pidx}, 'question', this.value)" placeholder="Item ${pidx+1}" style="flex: 1;">
                            <strong>➔</strong>
                            <input type="text" class="form-control" value="${escapeHtml(p.answer)}" oninput="updateQPair(${idx}, ${pidx}, 'answer', this.value)" placeholder="Match ${pidx+1}" style="flex: 1;">
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQPair(${idx}, ${pidx})">✖</button>
                        </div>
                    `).join('')}
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="addQPair(${idx})">➕ Add Pair</button>
            `;
        } else if (q.type === 'shortanswer') {
            typeName = "Short Answer (Manual Grading)"; typeColor = "#f59e0b";
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Question:</label>
                    <textarea class="form-control" rows="2" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div>
                    <label style="font-weight: 600; font-size: 0.85rem;">Teacher Rubric / Expected Answer (For reference only):</label>
                    <input type="text" class="form-control" value="${escapeHtml(q.rubric || "")}" oninput="updateQProp(${idx}, 'rubric', this.value)">
                </div>
            `;
        } else if (q.type === 'essay') {
            typeName = "Essay (Manual Grading)"; typeColor = "#ec4899";
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Essay Prompt:</label>
                    <textarea class="form-control" rows="2" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
                <div>
                    <label style="font-weight: 600; font-size: 0.85rem;">Teacher Rubric (For reference only):</label>
                    <textarea class="form-control" rows="2" oninput="updateQProp(${idx}, 'rubric', this.value)">${escapeHtml(q.rubric || "")}</textarea>
                </div>
            `;
        } else if (q.type === 'description') {
            typeName = "Description / Instruction"; typeColor = "#64748b";
            contentHtml = `
                <div style="margin-bottom: 12px;">
                    <label style="font-weight: 600; font-size: 0.9rem;">Content / Instruction Text:</label>
                    <textarea class="form-control" rows="3" oninput="updateQProp(${idx}, 'question', this.value)">${escapeHtml(q.question || "")}</textarea>
                </div>
            `;
        }

        let levelIdx = q.level_idx || 0;
        card.innerHTML = `
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <span style="font-weight: 800; color: #1e293b;">#${idx + 1}</span>
                    <span style="background: ${typeColor}; color: #ffffff; padding: 3px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">${typeName}</span>
                    <select class="form-control form-control-sm" style="width: auto; font-size: 0.8rem;" onchange="updateQProp(${idx}, 'level_idx', parseInt(this.value))">
                        <option value="0" ${levelIdx === 0 ? "selected" : ""}>Show on Level 1 (Independent)</option>
                        <option value="1" ${levelIdx === 1 ? "selected" : ""}>Show on Level 2 (Instructional)</option>
                        <option value="2" ${levelIdx === 2 ? "selected" : ""}>Show on Level 3 (Frustration)</option>
                        <option value="3" ${levelIdx === 3 ? "selected" : ""}>Show on Level 4 (Non-Reader)</option>
                    </select>
                </div>
                <div style="display: flex; gap: 6px;">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveQ(${idx}, -1)" ${idx === 0 ? "disabled" : ""}>⬆</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveQ(${idx}, 1)" ${idx === questions.length - 1 ? "disabled" : ""}>⬇</button>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteQ(${idx})">🗑️ Delete</button>
                </div>
            </div>
            ${contentHtml}
        `;
        list.appendChild(card);
    });
    syncJSON();
}

window.addQ = function() {
    const type = document.getElementById('ra-q-type-add').value;
    let newQ = { type: type, question: "" };
    if (type === 'multichoice') {
        newQ.options = ["Option 1", "Option 2"];
        newQ.correct = 0;
        newQ.shuffle = true;
    } else if (type === 'truefalse') {
        newQ.correct = true;
    } else if (type === 'matching') {
        newQ.pairs = [{question: "", answer: ""}, {question: "", answer: ""}];
    }
    questions.push(newQ);
    renderQ();
};

window.deleteQ = function(idx) {
    if (confirm("Delete question?")) {
        questions.splice(idx, 1);
        renderQ();
    }
};

window.moveQ = function(idx, dir) {
    const target = idx + dir;
    if (target >= 0 && target < questions.length) {
        const temp = questions[idx];
        questions[idx] = questions[target];
        questions[target] = temp;
        renderQ();
    }
};

window.updateQProp = function(idx, prop, val) {
    questions[idx][prop] = val;
    syncJSON();
};

window.addQOption = function(idx) {
    if (!questions[idx].options) questions[idx].options = [];
    questions[idx].options.push("");
    renderQ();
};
window.removeQOption = function(idx, oidx) {
    questions[idx].options.splice(oidx, 1);
    if (questions[idx].correct >= questions[idx].options.length) {
        questions[idx].correct = Math.max(0, questions[idx].options.length - 1);
    }
    renderQ();
};
window.updateQOption = function(idx, oidx, val) {
    questions[idx].options[oidx] = val;
    syncJSON();
};

window.addQPair = function(idx) {
    if (!questions[idx].pairs) questions[idx].pairs = [];
    questions[idx].pairs.push({question: "", answer: ""});
    renderQ();
};
window.removeQPair = function(idx, pidx) {
    questions[idx].pairs.splice(pidx, 1);
    renderQ();
};
window.updateQPair = function(idx, pidx, prop, val) {
    questions[idx].pairs[pidx][prop] = val;
    syncJSON();
};

renderQ();
</script>

<?php
echo $OUTPUT->footer();
