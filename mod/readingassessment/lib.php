<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * Supports features
 */
function readingassessment_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Add a new Reading Assessment instance.
 */
function readingassessment_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;

    if (!isset($data->passage)) {
        $data->passage = '';
    }
    if (!isset($data->activitytype)) {
        $data->activitytype = 'assessment';
    }
    if (!isset($data->tts_voice) || $data->tts_voice === 'alloy') {
        $data->tts_voice = 'en-US-JennyNeural';
    }
    $data->mastery_repetitions = !empty($data->mastery_repetitions) ? intval($data->mastery_repetitions) : 2;
    $data->linked_quizid = 0;
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    $data->grademethod = 1;

    if (isset($data->questions_json) && is_string($data->questions_json)) {
        $data->questions_json = $data->questions_json;
    } else {
        $data->questions_json = json_encode([]);
    }

    if (isset($data->nonreader_data) && is_string($data->nonreader_data)) {
        $data->nonreader_data = $data->nonreader_data;
    } else {
        $data->nonreader_data = json_encode([
            'letters' => 'a, e, i, o, u',
            'words' => [
                ['word' => 'fish', 'image' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400', 'letters' => 'f, i, s, h'],
                ['word' => 'cat', 'image' => 'https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400', 'letters' => 'c, a, t'],
                ['word' => 'sun', 'image' => 'https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400', 'letters' => 's, u, n']
            ]
        ]);
    }

    if (!isset($data->tts_personality_prompt) || trim($data->tts_personality_prompt) === '') {
        $data->tts_personality_prompt = "Accent/Affect: Warm, refined, and gently instructive, reminiscent of a friendly tutor.\n" .
                                        "Tone: Calm, encouraging, and articulate, clearly describing each step with patience.\n" .
                                        "Pacing: Slow and deliberate, pausing often to allow the listener to follow instructions comfortably.\n" .
                                        "Emotion: Cheerful, supportive, and pleasantly enthusiastic; convey genuine enjoyment and appreciation of reading.\n" .
                                        "Pronunciation: Clearly articulate phonemes, syllables, and vocabulary words with gentle emphasis.\n" .
                                        "Personality Affect: Friendly and approachable with a hint of sophistication; speak confidently and reassuringly, guiding learners through each reading and blending step patiently and warmly.";
    }

    $id = $DB->insert_record('readingassessment', $data);
    $data->id = $id;

    readingassessment_grade_item_update($data);

    return $id;
}

/**
 * Update an existing Reading Assessment instance.
 */
function readingassessment_update_instance($data, $mform = null) {
    global $DB;

    $data->timemodified = time();
    $data->id = $data->instance;

    if (!isset($data->activitytype)) {
        $data->activitytype = 'assessment';
    }
    if (!isset($data->tts_voice) || $data->tts_voice === 'alloy') {
        $data->tts_voice = 'en-US-JennyNeural';
    }
    if (!isset($data->tts_personality_prompt)) {
        $data->tts_personality_prompt = '';
    }
    $data->mastery_repetitions = !empty($data->mastery_repetitions) ? intval($data->mastery_repetitions) : 2;
    $data->linked_quizid = 0;
    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }
    $data->grademethod = 1;

    if (isset($data->questions_json) && is_string($data->questions_json)) {
        $data->questions_json = $data->questions_json;
    }

    if (isset($data->nonreader_data) && is_string($data->nonreader_data)) {
        $data->nonreader_data = $data->nonreader_data;
    }

    $DB->update_record('readingassessment', $data);

    readingassessment_grade_item_update($data);

    return true;
}

/**
 * Delete a Reading Assessment instance.
 */
function readingassessment_delete_instance($id) {
    global $DB;

    if (!$record = $DB->get_record('readingassessment', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('readingassessment_attempts', ['readingassessmentid' => $id]);
    $DB->delete_records('readingassessment', ['id' => $id]);

    readingassessment_grade_item_delete($record);

    return true;
}

/**
 * Update Gradebook grade item definition.
 */
function readingassessment_grade_item_update($readingassessment, $grades = null) {
    global $CFG;

    $params = [
        'itemname' => $readingassessment->name,
        'idnumber' => $readingassessment->cmidnumber ?? '',
        'gradetype' => GRADE_TYPE_VALUE,
        'grademax'  => $readingassessment->grade ?? 100,
        'grademin'  => 0,
    ];

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/readingassessment', $readingassessment->course, 'mod', 'readingassessment', $readingassessment->id, 0, $grades, $params);
}

/**
 * Delete Gradebook grade item.
 */
function readingassessment_grade_item_delete($readingassessment) {
    return grade_update('mod/readingassessment', $readingassessment->course, 'mod', 'readingassessment', $readingassessment->id, 0, null, ['deleted' => 1]);
}

/**
 * Update student grade in Gradebook based on activity grademethod.
 * Default: Highest Grade
 */
function readingassessment_update_grades($readingassessment, $userid = 0, $nullifnone = true) {
    global $DB;

    if ($userid != 0) {
        $attempts = $DB->get_records('readingassessment_attempts', [
            'readingassessmentid' => $readingassessment->id,
            'userid' => $userid
        ], 'attempt ASC');

        if (!empty($attempts)) {
            $scores = array_map(function($a) {
                return (float)$a->final_grade;
            }, array_values($attempts));

            $dategraded = end($attempts)->timecompleted;
            $calculated_grade = max($scores);

            $grades = [
                'userid' => $userid,
                'rawgrade' => round($calculated_grade, 2),
                'dategraded' => $dategraded
            ];
        } else {
            $grades = $nullifnone ? ['userid' => $userid, 'rawgrade' => null] : null;
        }

        readingassessment_grade_item_update($readingassessment, $grades);
    }
}

/**
 * Self-healing database schema verification.
 */
function readingassessment_ensure_schema() {
    global $DB;
    $dbman = $DB->get_manager();

    $table_ra = new xmldb_table('readingassessment');
    $field_voice = new xmldb_field('tts_voice', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, 'en-US-JennyNeural', 'activitytype');
    if ($dbman->field_exists($table_ra, $field_voice)) {
        $dbman->change_field_precision($table_ra, $field_voice);
    }

    $field_mastery = new xmldb_field('mastery_repetitions', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'tts_voice');
    if (!$dbman->field_exists($table_ra, $field_mastery)) {
        $dbman->add_field($table_ra, $field_mastery);
    }
    $field_nr = new xmldb_field('nonreader_data', XMLDB_TYPE_TEXT, null, null, null, null, null, 'questions_json');
    if (!$dbman->field_exists($table_ra, $field_nr)) {
        $dbman->add_field($table_ra, $field_nr);
    }
    $field_personality = new xmldb_field('tts_personality_prompt', XMLDB_TYPE_TEXT, null, null, null, null, null, 'tts_voice');
    if (!$dbman->field_exists($table_ra, $field_personality)) {
        $dbman->add_field($table_ra, $field_personality);
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
}

/**
 * Render custom comprehension questions cleanly.
 *
 * @param array $custom_questions
 * @param string $form_id
 * @param string $button_id
 * @return string HTML output
 */
function readingassessment_render_questions(array $custom_questions, string $form_id = 'ra-quiz-form', string $button_id = 'ra-btn-submit') {
    if (empty($custom_questions)) {
        return '
        <div class="ra-quiz-submit-container">
            <button id="' . $button_id . '" class="ra-btn ra-btn-submit">
                <span>📤</span> Submit Reading Assessment
            </button>
        </div>';
    }

    $html = '<div class="ra-card ra-quiz-card">';
    $html .= '<div class="ra-card-title">📝 Comprehension & Reflection Questions</div>';
    $html .= '<p class="ra-quiz-intro">Please complete the items below based on the reading passage:</p>';
    $html .= '<form id="' . $form_id . '">';

    foreach ($custom_questions as $qidx => $q) {
        $type = $q['type'] ?? 'multichoice';
        $html .= '<div class="ra-question-item">';

        if ($type === 'description') {
            $html .= '<div class="ra-question-description">';
            $html .= '<div class="ra-q-desc-title">' . s($q['title'] ?? 'Instructions') . '</div>';
            $html .= '<div class="ra-q-desc-text">' . nl2br(s($q['question'] ?? '')) . '</div>';
            $html .= '</div>';

        } else if ($type === 'multichoice') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? '') . '</div>';
            if (!empty($q['options'])) {
                $html .= '<div class="ra-options-stack">';
                $opts_to_render = $q['options'];
                $keys = array_keys($opts_to_render);
                if (!empty($q['shuffle'])) {
                    shuffle($keys);
                }
                foreach ($keys as $display_idx => $original_oidx) {
                    $opt = $opts_to_render[$original_oidx];
                    $html .= '<label class="ra-option-label">';
                    $html .= '<input type="radio" name="ra_q_' . $qidx . '" value="' . $original_oidx . '">';
                    $html .= '<span><strong>' . chr(65 + $display_idx) . '.</strong> ' . s($opt) . '</span>';
                    $html .= '</label>';
                }
                $html .= '</div>';
            }

        } else if ($type === 'truefalse') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? '') . '</div>';
            $html .= '<div class="ra-options-inline">';
            $html .= '<label class="ra-option-label ra-tf-true">';
            $html .= '<input type="radio" name="ra_q_' . $qidx . '" value="true">';
            $html .= '<span>True</span>';
            $html .= '</label>';
            $html .= '<label class="ra-option-label ra-tf-false">';
            $html .= '<input type="radio" name="ra_q_' . $qidx . '" value="false">';
            $html .= '<span>False</span>';
            $html .= '</label>';
            $html .= '</div>';

        } else if ($type === 'matching' || $type === 'randommatch') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? 'Match the items:') . '</div>';
            $pairs = $q['pairs'] ?? [];
            $all_answers = array_filter(array_map(function($p) { return $p['answer'] ?? ''; }, $pairs));
            shuffle($all_answers);
            $html .= '<div class="ra-matching-stack">';
            foreach ($pairs as $pidx => $p) {
                $html .= '<div class="ra-matching-row">';
                $html .= '<span class="ra-matching-q">' . s($p['question'] ?? '') . '</span>';
                $html .= '<select name="ra_q_' . $qidx . '_p_' . $pidx . '" class="ra-matching-select">';
                $html .= '<option value="">-- Choose matching answer --</option>';
                foreach ($all_answers as $ans_opt) {
                    $html .= '<option value="' . s($ans_opt) . '">' . s($ans_opt) . '</option>';
                }
                $html .= '</select>';
                $html .= '</div>';
            }
            $html .= '</div>';

        } else if ($type === 'shortanswer' || $type === 'numerical' || $type === 'calculated' || $type === 'calculatedsimple' || $type === 'calculatedmulti') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? '') . '</div>';
            $html .= '<input type="text" name="ra_q_' . $qidx . '" placeholder="Type your answer here..." class="ra-input-text">';

        } else if ($type === 'essay') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? '') . '</div>';
            $html .= '<textarea name="ra_q_' . $qidx . '" rows="4" placeholder="Write your essay response here..." class="ra-textarea"></textarea>';

        } else if ($type === 'ordering') {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? 'Arrange in order:') . '</div>';
            $items = $q['items'] ?? [];
            $shuffled_items = $items;
            shuffle($shuffled_items);
            $html .= '<div class="ra-ordering-stack">';
            foreach ($shuffled_items as $iidx => $it) {
                $html .= '<div class="ra-ordering-row">';
                $html .= '<select name="ra_q_' . $qidx . '_ord_' . $iidx . '" class="ra-ordering-select">';
                for ($s = 1; $s <= count($shuffled_items); $s++) {
                    $html .= '<option value="' . $s . '">' . $s . '</option>';
                }
                $html .= '</select>';
                $html .= '<span data-itemtext="' . s($it) . '">' . s($it) . '</span>';
                $html .= '</div>';
            }
            $html .= '</div>';

        } else {
            $html .= '<div class="ra-question-title">' . ($qidx + 1) . '. ' . s($q['question'] ?? '') . '</div>';
            $html .= '<input type="text" name="ra_q_' . $qidx . '" placeholder="Your response..." class="ra-input-text">';
        }

        $html .= '</div>';
    }

    $html .= '</form>';
    $html .= '<div class="ra-quiz-submit-container">';
    $html .= '<button id="' . $button_id . '" class="ra-btn ra-btn-submit"><span>📤</span> Submit Assessment</button>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

