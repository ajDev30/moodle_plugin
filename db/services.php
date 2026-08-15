<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_readingassessment_submit_attempt' => [
        'classname'   => 'mod_readingassessment\external\submit_attempt',
        'methodname'  => 'execute',
        'description' => 'Submit student reading assessment attempt scores and update gradebook',
        'type'        => 'write',
        'ajax'        => true,
        'loginrequired' => true,
    ],
];

$services = [
    'Reading Assessment Service' => [
        'functions' => [
            'mod_readingassessment_submit_attempt',
        ],
        'restrictedusers' => 0,
        'enabled' => 1,
    ],
];
