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
    if (!isset($data->tts_voice)) {
        $data->tts_voice = 'alloy';
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
    if (!isset($data->tts_voice)) {
        $data->tts_voice = 'alloy';
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
 * Extends course navigation to link to ARAL Program: Student Reading Progress.
 *
 * @param navigation_node $parentnode The parent navigation node.
 * @param stdClass $course The course object.
 * @param context_course $context The course context.
 */
function readingassessment_extend_navigation_course(navigation_node $parentnode, stdClass $course, context_course $context) {
    global $DB;

    if (has_capability('mod/readingassessment:grade', $context)) {
        $ra_count = $DB->count_records('readingassessment', ['course' => $course->id]);
        if ($ra_count > 0) {
            $url = new moodle_url('/mod/readingassessment/report.php', ['courseid' => $course->id]);
            $parentnode->add(
                get_string('aral_reading_progress', 'mod_readingassessment'),
                $url,
                navigation_node::TYPE_CUSTOM,
                null,
                'aral_reading_progress',
                new pix_icon('i/report', '')
            );
        }
    }
}

/**
 * Extends settings navigation for reading assessment activity.
 */
function readingassessment_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $readingassessmentnode = null) {
    global $PAGE;

    if (has_capability('mod/readingassessment:grade', $PAGE->cm->context)) {
        $url = new moodle_url('/mod/readingassessment/report.php', [
            'id' => $PAGE->cm->id,
            'courseid' => $PAGE->cm->course
        ]);
        if ($readingassessmentnode) {
            $readingassessmentnode->add(
                get_string('aral_reading_progress', 'mod_readingassessment'),
                $url,
                navigation_node::TYPE_CUSTOM,
                null,
                'aral_reading_progress',
                new pix_icon('i/report', '')
            );
        }
    }
}
