<?php
defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // Register Admin Page in Site Admin menu
    $ADMIN->add('modules', new admin_externalpage(
        'readingassessment_dash',
        get_string('admin_dashboard', 'mod_readingassessment'),
        $CFG->wwwroot . '/mod/readingassessment/admin.php'
    ));

    // Link to Admin Management Dashboard
    $settings->add(new admin_setting_heading(
        'readingassessment_dash_heading',
        get_string('admin_dashboard', 'mod_readingassessment'),
        '<a class="btn btn-primary" href="' . $CFG->wwwroot . '/mod/readingassessment/admin.php">' . get_string('open_admin_dashboard', 'mod_readingassessment') . '</a>'
    ));

    // Link to External Screening Dashboard
    $settings->add(new admin_setting_heading(
        'readingassessment_ext_dash_heading',
        'External Screening Dashboard',
        '<a class="btn btn-success" href="' . $CFG->wwwroot . '/mod/readingassessment/external_manage.php">Open External Screening Dashboard</a>'
    ));

    // Azure Speech API Key
    $settings->add(new admin_setting_configpasswordunmask(
        'readingassessment/azure_speech_key',
        get_string('azure_speech_key', 'mod_readingassessment'),
        get_string('azure_speech_key_help', 'mod_readingassessment'),
        ''
    ));

    // Azure Speech Region
    $settings->add(new admin_setting_configtext(
        'readingassessment/azure_speech_region',
        get_string('azure_speech_region', 'mod_readingassessment'),
        get_string('azure_speech_region_help', 'mod_readingassessment'),
        'southeastasia',
        PARAM_TEXT
    ));

    // Operating System Selection
    $os_options = [
        'linux' => 'Linux / WSL (Windows Subsystem for Linux)',
        'windows' => 'Windows (XAMPP / WAMP)',
    ];
    $settings->add(new admin_setting_configselect(
        'readingassessment/operating_system',
        get_string('operating_system', 'mod_readingassessment'),
        get_string('operating_system_help', 'mod_readingassessment'),
        'linux',
        $os_options
    ));

    // ASR Service Port
    $settings->add(new admin_setting_configtext(
        'readingassessment/service_port',
        get_string('service_port', 'mod_readingassessment'),
        get_string('service_port_help', 'mod_readingassessment'),
        '8010',
        PARAM_INT
    ));
}
