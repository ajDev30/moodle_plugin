<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Reading Assessment';
$string['modulename'] = 'Reading Assessment';
$string['modulenameplural'] = 'Reading Assessments';
$string['modulename_help'] = 'The Reading Assessment module enables teachers to create reading passages and comprehension questions. Students read the passage aloud using ASR, and their fluency, reading speed (WPM), and comprehension are scored automatically.';
$string['readingassessmentname'] = 'Assessment Name';
$string['passage'] = 'Reading Passage';
$string['passage_help'] = 'Enter the story or passage that students will read aloud.';

$string['activitytype'] = 'Activity Mode';
$string['activitytype_help'] = 'Choose between Independent Assessment, Instructional Guided Reader, or Non-Reader & Early Phonics (Letter Sounds & Picture Word Blending).';
$string['activitytype_assessment'] = 'Independent Assessment (Standard Fluency Test)';
$string['activitytype_instructional'] = 'Instructional Reader (Guided Coaching Mode)';
$string['activitytype_nonreader'] = 'Non-Reader & Early Phonics (Letter Sounds & Picture Word Blending)';

$string['mastery_repetitions'] = 'Sound-Out Mastery Repetitions';
$string['mastery_repetitions_help'] = 'In Instructional Reader mode, set how many times the student must successfully read/blend a coached word before returning to the passage.';
$string['mastery_1x'] = '1x (Single Pass)';
$string['mastery_2x'] = '2x (Standard Mastery - Recommended)';
$string['mastery_3x'] = '3x (Reinforced Mastery)';
$string['mastery_4x'] = '4x (High Repetition)';
$string['mastery_5x'] = '5x (Intensive Mastery)';

$string['linked_quiz'] = 'Linked Moodle Comprehension Quiz';
$string['linked_quiz_help'] = 'Select an existing Quiz in this course built using Moodle\'s native Question Bank. If selected, students will be directed to this quiz upon finishing the reading passage.';
$string['no_linked_quiz'] = 'None (Reading & Fluency Only)';

$string['aral_reading_progress'] = 'ARAL Program: Student Reading Progress';
$string['reading_speed'] = 'Reading Speed';
$string['reading_time'] = 'Reading Time';
$string['wpm'] = 'WPM';

$string['tts_voice'] = 'Teacher Audio Voice';
$string['tts_voice_help'] = 'Select the voice model used to pronounce and correct words for Instructional Readers and Non-Reader Phonics Studio.';
$string['tts_personality_preset'] = 'Teacher Voice Personality Preset';
$string['tts_personality_preset_help'] = 'Choose a preset personality style or customize your own prompt below to define how the teacher voice speaks, instructs, and encourages the student.';
$string['tts_personality_prompt'] = 'Teacher Voice Personality Prompt';
$string['tts_personality_prompt_help'] = 'Define the accent, affect, tone, pacing, emotion, and pedagogical persona used by the AI voice.';

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

$string['how_many_items'] = 'How many item';
$string['questionnaire'] = 'Questionnaire';
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

$string['instructional_title'] = '📖 Guided Instructional Reader';
$string['line_progress'] = 'Line {$a->current} of {$a->total}';
$string['say_word_prompt'] = 'Listen: "{$a}" — Now your turn!';
$string['teacher_coaching'] = '👩‍🏫 Teacher Guided Assistance';

$string['browser_speech_notice'] = 'Scored via Browser Speech Engine (Practice Mode — not recorded in official Gradebook)';
$string['ai_speech_notice'] = 'Official AI-Evaluated Attempt (Recorded in Gradebook)';

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
