<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Reading Assessment';
$string['modulename'] = 'Reading Assessment';
$string['modulenameplural'] = 'Reading Assessments';
$string['modulename_help'] = 'The Reading Assessment module enables teachers to create reading passages and comprehension questions. Students read the passage aloud using ASR, and their fluency and comprehension are scored automatically.';
$string['readingassessmentname'] = 'Assessment Name';
$string['passage'] = 'Reading Passage';
$string['passage_help'] = 'Enter the story or passage that students will read aloud.';

$string['maxattempts'] = 'Maximum Allowed Attempts';
$string['maxattempts_help'] = 'Set the maximum number of attempts a student can make. Select 0 for unlimited attempts.';
$string['unlimited'] = 'Unlimited';
$string['attempts_exhausted'] = 'You have reached the maximum number of allowed attempts ({$a}) for this assessment.';

$string['grademethod'] = 'Grading Method for Multiple Attempts';
$string['grademethod_help'] = 'When multiple attempts are allowed, choose how the final score in the Gradebook is calculated.';
$string['gradehighest'] = 'Highest Grade';
$string['gradeaverage'] = 'Average Grade';
$string['gradefirst'] = 'First Attempt';
$string['gradelast'] = 'Last Attempt';

$string['numquestions'] = 'Number of Comprehension Questions';
$string['question_builder'] = 'Comprehension Question Builder';
$string['question_n'] = 'Question {$a}';
$string['option_a'] = 'Option A';
$string['option_b'] = 'Option B';
$string['option_c'] = 'Option C';
$string['option_d'] = 'Option D';
$string['correct_answer'] = 'Correct Choice';
$string['unanswered_questions_warning'] = 'Please answer all comprehension questions before submitting.';
$string['question_required_notice'] = 'All comprehension questions must be answered.';

$string['admin_dashboard'] = 'ASR Service Administration Dashboard';
$string['open_admin_dashboard'] = 'Open ASR Controller Dashboard';
$string['openai_apikey'] = 'OpenAI API Key';
$string['openai_apikey_help'] = 'Enter your OpenAI API key (sk-...) used for Realtime WebRTC transcription and Whisper fallback.';
$string['operating_system'] = 'Host Operating System';
$string['operating_system_help'] = 'Select whether your Moodle server is hosted on Windows (XAMPP/WAMP) or Linux.';
$string['python_path'] = 'Python Environment Directory Path';
$string['python_path_help'] = 'Absolute path to the Python environment directory (e.g., /home/andrew/venvASR or C:\\venvASR).';
$string['service_port'] = 'ASR Microservice Port';
$string['service_port_help'] = 'Port number for the Python ASR microservice (Default: 8000).';

$string['install_deps'] = 'Install Dependencies';
$string['start_service'] = 'Start ASR Service';
$string['stop_service'] = 'Stop ASR Service';
$string['service_running'] = 'ASR Service is Running (Port {$a})';
$string['service_stopped'] = 'ASR Service is Stopped';

$string['start_reading'] = 'Start';
$string['done_reading'] = 'Done';
$string['retry_reading'] = 'Retry';
$string['submit_assessment'] = 'Submit Assessment';
$string['reading_not_completed'] = 'Please read the passage and click [Done] before submitting.';

$string['service_online'] = 'Python ASR Service Online';
$string['service_offline'] = 'Python ASR Service Offline (http://localhost:8000)';

$string['start_test'] = 'Start';
$string['pause_test'] = 'Pause';
$string['stop_submit'] = 'Submit Assessment';
$string['recording_status'] = 'Recording Status';
$string['reading_passage_heading'] = 'Read the following passage aloud:';
$string['comprehension_heading'] = 'Reading Comprehension Questions';
$string['results_heading'] = 'Assessment Results';
$string['accuracy_score'] = 'Reading Accuracy Score';
$string['comprehension_score'] = 'Comprehension Score';
$string['final_grade'] = 'Final Composite Grade';
$string['miscues_count'] = 'Miscues / Errors';
$string['attempt_history'] = 'Your Previous Attempts';
$string['no_attempts'] = 'No attempts recorded yet.';
$string['pluginadministration'] = 'Reading Assessment Administration';
$string['readingassessment:addinstance'] = 'Add a new Reading Assessment';
$string['readingassessment:view'] = 'View Reading Assessment';
$string['readingassessment:submit'] = 'Submit Reading Assessment attempt';
$string['readingassessment:grade'] = 'Grade Reading Assessment';
