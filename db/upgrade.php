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

    return true;
}
