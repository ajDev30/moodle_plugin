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

    // OpenAI API Key
    $settings->add(new admin_setting_configpasswordunmask(
        'readingassessment/openai_apikey',
        get_string('openai_apikey', 'mod_readingassessment'),
        get_string('openai_apikey_help', 'mod_readingassessment'),
        ''
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
        '8000',
        PARAM_INT
    ));
}
