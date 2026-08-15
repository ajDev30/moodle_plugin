<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/gradelib.php');

/**
 * Indicates API features supported by Reading Assessment.
 */
function readingassessment_supports($feature) {
    switch ($feature) {
        case FEATURE_GROUPS:
            return false;
        case FEATURE_GROUPINGS:
            return false;
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
 * Helper to bundle form question fields into JSON.
 */
function readingassessment_process_questions_from_form($data) {
    $questions = [];
    for ($i = 0; $i < 5; $i++) {
        $qtext_key = "q_text_{$i}";
        if (!empty($data->$qtext_key)) {
            $qtext = trim($data->$qtext_key);
            $opta = trim($data->{"q_opta_{$i}"} ?? '');
            $optb = trim($data->{"q_optb_{$i}"} ?? '');
            $optc = trim($data->{"q_optc_{$i}"} ?? '');
            $optd = trim($data->{"q_optd_{$i}"} ?? '');
            $correct = intval($data->{"q_correct_{$i}"} ?? 0);

            $options = array_values(array_filter([$opta, $optb, $optc, $optd], function($val) {
                return $val !== '';
            }));

            if (!empty($options)) {
                $questions[] = [
                    'question' => $qtext,
                    'options'  => $options,
                    'correct'  => $correct
                ];
            }
        }
    }
    return json_encode($questions, JSON_PRETTY_PRINT);
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

    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }

    // Process questions from form inputs
    $data->questions_json = readingassessment_process_questions_from_form($data);

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

    if (!isset($data->maxattempts)) {
        $data->maxattempts = 0;
    }

    // Process questions from form inputs
    $data->questions_json = readingassessment_process_questions_from_form($data);

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
 * Update student grade in Gradebook for this activity.
 */
function readingassessment_update_grades($readingassessment, $userid = 0, $nullifnone = true) {
    global $DB;

    if ($userid != 0) {
        $attempts = $DB->get_records('readingassessment_attempts', [
            'readingassessmentid' => $readingassessment->id,
            'userid' => $userid
        ], 'timecompleted DESC', '*', 0, 1);

        if (!empty($attempts)) {
            $latest = reset($attempts);
            $grades = [
                'userid' => $userid,
                'rawgrade' => $latest->final_grade,
                'dategraded' => $latest->timecompleted
            ];
        } else {
            $grades = $nullifnone ? ['userid' => $userid, 'rawgrade' => null] : null;
        }
        readingassessment_grade_item_update($readingassessment, $grades);
    }
}
