<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_readingassessment_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        // General section
        $mform->addElement('header', 'general', get_string('general', 'form'));

        // Activity Name
        $mform->addElement('text', 'name', get_string('readingassessmentname', 'mod_readingassessment'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Description / Intro
        $this->standard_intro_elements();

        // Student Attempt Controls
        $mform->addElement('header', 'attempt_settings', get_string('attempt_history', 'mod_readingassessment'));
        $attempt_options = [
            0 => get_string('unlimited', 'mod_readingassessment'),
            1 => '1 attempt',
            2 => '2 attempts',
            3 => '3 attempts',
            5 => '5 attempts',
            10 => '10 attempts',
        ];
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_readingassessment'), $attempt_options);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_readingassessment');

        // Reading Passage Section
        $mform->addElement('header', 'passage_section', get_string('passage', 'mod_readingassessment'));
        $mform->addElement('textarea', 'passage', get_string('passage', 'mod_readingassessment'), 'wrap="virtual" rows="10" cols="80"');
        $mform->setType('passage', PARAM_TEXT);
        $mform->addRule('passage', null, 'required', null, 'client');
        $mform->addHelpButton('passage', 'passage', 'mod_readingassessment');

        // User-Friendly Comprehension Question Builder (5 Questions)
        $mform->addElement('header', 'questions_section', get_string('question_builder', 'mod_readingassessment'));

        for ($i = 0; $i < 5; $i++) {
            $num = $i + 1;
            $mform->addElement('static', "q_header_{$i}", "<strong>" . get_string('question_n', 'mod_readingassessment', $num) . "</strong>");

            $mform->addElement('text', "q_text_{$i}", "Question {$num} Text", ['size' => '60']);
            $mform->setType("q_text_{$i}", PARAM_TEXT);

            $mform->addElement('text', "q_opta_{$i}", get_string('option_a', 'mod_readingassessment'), ['size' => '50']);
            $mform->setType("q_opta_{$i}", PARAM_TEXT);

            $mform->addElement('text', "q_optb_{$i}", get_string('option_b', 'mod_readingassessment'), ['size' => '50']);
            $mform->setType("q_optb_{$i}", PARAM_TEXT);

            $mform->addElement('text', "q_optc_{$i}", get_string('option_c', 'mod_readingassessment'), ['size' => '50']);
            $mform->setType("q_optc_{$i}", PARAM_TEXT);

            $mform->addElement('text', "q_optd_{$i}", get_string('option_d', 'mod_readingassessment'), ['size' => '50']);
            $mform->setType("q_optd_{$i}", PARAM_TEXT);

            $correct_options = [
                0 => get_string('option_a', 'mod_readingassessment'),
                1 => get_string('option_b', 'mod_readingassessment'),
                2 => get_string('option_c', 'mod_readingassessment'),
                3 => get_string('option_d', 'mod_readingassessment'),
            ];
            $mform->addElement('select', "q_correct_{$i}", get_string('correct_answer', 'mod_readingassessment'), $correct_options);
            $mform->setDefault("q_correct_{$i}", 0);

            $mform->addElement('static', "q_sep_{$i}", '', '<hr style="border-top:1px dashed #cbd5e1; margin:15px 0;" />');
        }

        // Grade settings
        $this->standard_grading_coursemodule_elements();

        // Common module elements
        $this->standard_coursemodule_elements();

        // Standard buttons
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$default_values) {
        parent::data_preprocessing($default_values);

        // Unpack questions_json into individual form fields for editing
        if (!empty($default_values['questions_json'])) {
            $questions = json_decode($default_values['questions_json'], true);
            if (is_array($questions)) {
                foreach ($questions as $i => $q) {
                    if ($i >= 5) break;
                    $default_values["q_text_{$i}"] = $q['question'] ?? '';
                    if (isset($q['options']) && is_array($q['options'])) {
                        $default_values["q_opta_{$i}"] = $q['options'][0] ?? '';
                        $default_values["q_optb_{$i}"] = $q['options'][1] ?? '';
                        $default_values["q_optc_{$i}"] = $q['options'][2] ?? '';
                        $default_values["q_optd_{$i}"] = $q['options'][3] ?? '';
                    }
                    $default_values["q_correct_{$i}"] = $q['correct'] ?? 0;
                }
            }
        }
    }
}
