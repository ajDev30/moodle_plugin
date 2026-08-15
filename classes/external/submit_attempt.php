<?php
namespace mod_readingassessment\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/readingassessment/lib.php');

use external_api;
use external_function_parameters;
use external_value;
use external_single_structure;

class submit_attempt extends external_api {

    public static function execute_parameters() {
        return new external_function_parameters([
            'readingassessmentid' => new external_value(PARAM_INT, 'Reading Assessment ID'),
            'transcript'          => new external_value(PARAM_RAW, 'Spoken transcript text'),
            'accuracy_score'      => new external_value(PARAM_FLOAT, 'Reading Accuracy percentage score'),
            'comprehension_score' => new external_value(PARAM_FLOAT, 'Comprehension percentage score'),
            'final_grade'         => new external_value(PARAM_FLOAT, 'Composite final grade'),
            'miscues_json'        => new external_value(PARAM_RAW, 'Miscues feedback JSON', VALUE_DEFAULT, '[]'),
            'answers_json'        => new external_value(PARAM_RAW, 'Student answers JSON', VALUE_DEFAULT, '[]'),
        ]);
    }

    public static function execute($readingassessmentid, $transcript, $accuracy_score, $comprehension_score, $final_grade, $miscues_json = '[]', $answers_json = '[]') {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'readingassessmentid' => $readingassessmentid,
            'transcript'          => $transcript,
            'accuracy_score'      => $accuracy_score,
            'comprehension_score' => $comprehension_score,
            'final_grade'         => $final_grade,
            'miscues_json'        => $miscues_json,
            'answers_json'        => $answers_json,
        ]);

        $context = \context_module::instance($DB->get_field('course_modules', 'id', [
            'module' => $DB->get_field('modules', 'id', ['name' => 'readingassessment']),
            'instance' => $params['readingassessmentid']
        ], MUST_EXIST));

        self::validate_context($context);

        $assessment = $DB->get_record('readingassessment', ['id' => $params['readingassessmentid']], '*', MUST_EXIST);

        // Count previous attempts
        $attemptcount = $DB->count_records('readingassessment_attempts', [
            'readingassessmentid' => $params['readingassessmentid'],
            'userid' => $USER->id
        ]);

        $attempt = new \stdClass();
        $attempt->readingassessmentid = $params['readingassessmentid'];
        $attempt->userid              = $USER->id;
        $attempt->attempt             = $attemptcount + 1;
        $attempt->transcript          = $params['transcript'];
        $attempt->accuracy_score      = $params['accuracy_score'];
        $attempt->comprehension_score = $params['comprehension_score'];
        $attempt->final_grade         = $params['final_grade'];
        $attempt->miscues_json        = $params['miscues_json'];
        $attempt->answers_json        = $params['answers_json'];
        $attempt->timecompleted       = time();

        $attemptid = $DB->insert_record('readingassessment_attempts', $attempt);

        // Sync with Moodle Gradebook
        readingassessment_update_grades($assessment, $USER->id);

        return [
            'status'      => true,
            'attemptid'   => $attemptid,
            'final_grade' => $params['final_grade'],
            'message'     => 'Attempt saved and gradebook updated successfully'
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'status'      => new external_value(PARAM_BOOL, 'Status of submission'),
            'attemptid'   => new external_value(PARAM_INT, 'ID of recorded attempt'),
            'final_grade' => new external_value(PARAM_FLOAT, 'Final composite grade saved'),
            'message'     => new external_value(PARAM_TEXT, 'Result message'),
        ]);
    }
}
