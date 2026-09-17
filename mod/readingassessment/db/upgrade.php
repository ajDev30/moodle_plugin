<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_readingassessment_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2024081401) {
        $table = new xmldb_table('readingassessment');
        $field = new xmldb_field('maxattempts', XMLDB_TYPE_INTEGER, '6', null, XMLDB_NOTNULL, null, '0', 'questions_json');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2024081401, 'readingassessment');
    }

    if ($oldversion < 2024081402) {
        $table = new xmldb_table('readingassessment');
        $field = new xmldb_field('grademethod', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'maxattempts');

        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2024081402, 'readingassessment');
    }

    if ($oldversion < 2024081403) {
        $table = new xmldb_table('readingassessment');
        
        $field_type = new xmldb_field('activitytype', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'assessment', 'introformat');
        if (!$dbman->field_exists($table, $field_type)) {
            $dbman->add_field($table, $field_type);
        }

        $field_voice = new xmldb_field('tts_voice', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'alloy', 'activitytype');
        if (!$dbman->field_exists($table, $field_voice)) {
            $dbman->add_field($table, $field_voice);
        }

        upgrade_mod_savepoint(true, 2024081403, 'readingassessment');
    }

    if ($oldversion < 2024081404) {
        $table_ra = new xmldb_table('readingassessment');
        
        $field_mastery = new xmldb_field('mastery_repetitions', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '2', 'tts_voice');
        if (!$dbman->field_exists($table_ra, $field_mastery)) {
            $dbman->add_field($table_ra, $field_mastery);
        }

        $field_quiz = new xmldb_field('linked_quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'questions_json');
        if (!$dbman->field_exists($table_ra, $field_quiz)) {
            $dbman->add_field($table_ra, $field_quiz);
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

        upgrade_mod_savepoint(true, 2024081404, 'readingassessment');
    }

    if ($oldversion < 2024091705) {
        $table = new xmldb_table('readingassessment_ext_pass');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('grade_level', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '7');
        $table->add_field('passage', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('questions_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('grade_level', XMLDB_INDEX_UNIQUE, ['grade_level']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('readingassessment_ext_prof');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('token', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
        $table->add_field('fullname', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
        $table->add_field('lrn', XMLDB_TYPE_CHAR, '50', null, XMLDB_NOTNULL, null, null);
        $table->add_field('grade_level', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '7');
        $table->add_field('consent_agreed', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_index('token', XMLDB_INDEX_UNIQUE, ['token']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        $table = new xmldb_table('readingassessment_ext_att');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('profileid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('transcript', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('accuracy_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('comprehension_score', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('reading_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('reading_speed', XMLDB_TYPE_NUMBER, '10, 2', null, XMLDB_NOTNULL, null, '0.00');
        $table->add_field('miscues_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('answers_json', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('classification', XMLDB_TYPE_CHAR, '50', null, null, null, null);
        $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('profileid', XMLDB_KEY_FOREIGN, ['profileid'], 'readingassessment_ext_prof', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_mod_savepoint(true, 2024091705, 'readingassessment');
    }

    if ($oldversion < 2024091706) {
        $table = new xmldb_table('readingassessment_ext_pass');
        
        $field_exp = new xmldb_field('expiration_time', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'questions_json');
        if (!$dbman->field_exists($table, $field_exp)) {
            $dbman->add_field($table, $field_exp);
        }

        $field_att = new xmldb_field('max_attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '1', 'expiration_time');
        if (!$dbman->field_exists($table, $field_att)) {
            $dbman->add_field($table, $field_att);
        }

        upgrade_mod_savepoint(true, 2024091706, 'readingassessment');
    }

    if ($oldversion < 2024091707) {
        $table = new xmldb_table('readingassessment_ext_prof');
        
        $field_gender = new xmldb_field('gender', XMLDB_TYPE_CHAR, '20', null, null, null, null, 'lrn');
        if (!$dbman->field_exists($table, $field_gender)) {
            $dbman->add_field($table, $field_gender);
        }

        $field_age = new xmldb_field('age', XMLDB_TYPE_INTEGER, '3', null, null, null, null, 'gender');
        if (!$dbman->field_exists($table, $field_age)) {
            $dbman->add_field($table, $field_age);
        }

        upgrade_mod_savepoint(true, 2024091707, 'readingassessment');
    }

    return true;
}
