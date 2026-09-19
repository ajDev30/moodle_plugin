<?php
exec("pkill -9 -f 'service.py' 2>&1", $out);
echo implode("\n", $out);
$cmd = "/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/bin/python /var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/service.py > /tmp/asr_service.log 2>&1 &";
exec($cmd);
echo "\nRestarted!";
