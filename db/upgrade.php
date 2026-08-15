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

    return true;
}
