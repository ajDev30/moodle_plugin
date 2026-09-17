<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_readingassessment_mod_form extends moodleform_mod {

    public function definition() {
        global $DB, $COURSE, $CFG;
        $mform = $this->_form;

        // =========================================================================
        // SECTION 1: GENERAL ACTIVITY DETAILS
        // =========================================================================
        $mform->addElement('header', 'general', '📌 ' . get_string('general', 'form'));

        if ($this->current && !empty($this->current->id)) {
            $report_url = new moodle_url('/mod/readingassessment/report.php', ['courseid' => $COURSE->id, 'raid' => $this->current->id]);
            $html_dash = '
            <div class="alert alert-info d-flex justify-content-between align-items-center" style="border-radius: 10px; margin-bottom: 18px; padding: 14px 20px; background: linear-gradient(135deg, #e0f2fe, #f0fdf4); border: 1px solid #bae6fd; color: #0369a1; box-shadow: 0 2px 6px rgba(0,0,0,0.03);">' .
                '<div>' .
                    '<strong style="font-size: 1.05rem;">📊 ARAL Program: Student Reading Analytics Dashboard</strong>' .
                    '<div style="font-size: 0.85rem; color: #0284c7; margin-top: 2px;">View class reading speed (WPM), accuracy, duration, and comprehension answers in real time.</div>' .
                '</div>' .
                '<a href="' . $report_url . '" target="_blank" class="btn btn-primary btn-sm font-weight-bold" style="background: #0284c7; border: none; border-radius: 8px; padding: 8px 18px; text-decoration: none; color: #ffffff; box-shadow: 0 3px 8px rgba(2,132,199,0.3);">' .
                    'Open Dashboard ↗' .
                '</a>' .
            '</div>';
            $mform->addElement('static', 'aral_quick_report_link', '', $html_dash);
        }

        // Activity Name
        $mform->addElement('text', 'name', get_string('readingassessmentname', 'mod_readingassessment'), ['size' => '64']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        // Description / Intro
        $this->standard_intro_elements();

        // Activity Mode Selector
        $activity_type_options = [
            'assessment'    => '📊 ' . get_string('activitytype_assessment', 'mod_readingassessment'),
            'instructional' => '📖 ' . get_string('activitytype_instructional', 'mod_readingassessment'),
            'nonreader'     => '🎨 ' . get_string('activitytype_nonreader', 'mod_readingassessment'),
            'struggling'    => '🆘 Struggling Reader (Intervention & Support)',
        ];
        $mform->addElement('select', 'activitytype', get_string('activitytype', 'mod_readingassessment'), $activity_type_options);
        $mform->setDefault('activitytype', 'assessment');
        $mform->addHelpButton('activitytype', 'activitytype', 'mod_readingassessment');


        // =========================================================================
        // SECTION 2: TEACHER VOICE & AI COACHING CONFIGURATION
        // =========================================================================
        $mform->addElement('header', 'tts_coaching_section', '🎙️ Teacher Voice & AI Coaching Configuration');
        $mform->hideIf('tts_coaching_section', 'activitytype', 'eq', 'assessment');

        // Voice Model Selector (Azure Speech SDK Neural Voices)
        $voice_options = [
            'en-US-JennyNeural'       => 'Jenny (US - Warm & Friendly Female) [Default]',
            'en-US-GuyNeural'         => 'Guy (US - Clear & Natural Male)',
            'en-US-AriaNeural'        => 'Aria (US - Expressive & Positive Female)',
            'en-US-AnaNeural'         => 'Ana (US - Young Child Female)',
            'en-US-ChristopherNeural' => 'Christopher (US - Warm & Trustworthy Male)',
            'en-US-EricNeural'        => 'Eric (US - Conversational & Friendly Male)',
            'en-US-MichelleNeural'    => 'Michelle (US - Gentle & Clear Female)',
            'en-GB-SoniaNeural'       => 'Sonia (UK - British Expressive Female)',
            'en-GB-RyanNeural'        => 'Ryan (UK - British Professional Male)',
            'en-AU-NatashaNeural'     => 'Natasha (Australia - Friendly Female)',
            'en-PH-RosaNeural'        => 'Rosa (Philippines - Clear & Engaging Female)',
            'en-PH-JamesNeural'       => 'James (Philippines - Articulate Male)',
        ];
        $mform->addElement('select', 'tts_voice', get_string('tts_voice', 'mod_readingassessment'), $voice_options);
        $mform->setDefault('tts_voice', 'en-US-JennyNeural');
        $mform->addHelpButton('tts_voice', 'tts_voice', 'mod_readingassessment');
        $mform->hideIf('tts_voice', 'activitytype', 'eq', 'assessment');

        // Mastery Repetition Target
        $mastery_options = [
            1 => get_string('mastery_1x', 'mod_readingassessment'),
            2 => get_string('mastery_2x', 'mod_readingassessment'),
            3 => get_string('mastery_3x', 'mod_readingassessment'),
            4 => get_string('mastery_4x', 'mod_readingassessment'),
            5 => get_string('mastery_5x', 'mod_readingassessment'),
        ];
        $mform->addElement('select', 'mastery_repetitions', get_string('mastery_repetitions', 'mod_readingassessment'), $mastery_options);
        $mform->setType('mastery_repetitions', PARAM_INT);
        $mform->setDefault('mastery_repetitions', 2);
        $mform->addHelpButton('mastery_repetitions', 'mastery_repetitions', 'mod_readingassessment');
        $mform->hideIf('mastery_repetitions', 'activitytype', 'neq', 'instructional');

        // Voice Personality Preset
        $preset_options = [
            'warm_tutor'         => 'Warm & Instructive (Friendly Tutor / Art Instructor style)',
            'calm_specialist'    => 'Calm & Patient Reading Specialist',
            'cheerful_coach'     => 'Cheerful & Energetic Reading Cheerleader',
            'storybook_narrator' => 'Expressive Storybook Narrator',
            'custom'             => 'Custom Personality (Edit Below)'
        ];
        $mform->addElement('select', 'tts_personality_preset', get_string('tts_personality_preset', 'mod_readingassessment'), $preset_options);
        $mform->setDefault('tts_personality_preset', 'warm_tutor');
        $mform->addHelpButton('tts_personality_preset', 'tts_personality_preset', 'mod_readingassessment');
        $mform->hideIf('tts_personality_preset', 'activitytype', 'eq', 'assessment');

        // Voice Personality Prompt Textarea
        $default_personality_prompt = "Accent/Affect: Warm, refined, and gently instructive, reminiscent of a friendly tutor.\n" .
                                      "Tone: Calm, encouraging, and articulate, clearly describing each step with patience.\n" .
                                      "Pacing: Slow and deliberate, pausing often to allow the listener to follow instructions comfortably.\n" .
                                      "Emotion: Cheerful, supportive, and pleasantly enthusiastic; convey genuine enjoyment and appreciation of reading.\n" .
                                      "Pronunciation: Clearly articulate phonemes, syllables, and vocabulary words with gentle emphasis.\n" .
                                      "Personality Affect: Friendly and approachable with a hint of sophistication; speak confidently and reassuringly, guiding learners through each reading and blending step patiently and warmly.";

        $mform->addElement('textarea', 'tts_personality_prompt', get_string('tts_personality_prompt', 'mod_readingassessment'), 'wrap="virtual" rows="7" cols="75" style="font-family: monospace; font-size: 0.88rem; line-height: 1.4; border-radius: 8px;"');
        $mform->setType('tts_personality_prompt', PARAM_RAW);
        $mform->setDefault('tts_personality_prompt', $default_personality_prompt);
        $mform->addHelpButton('tts_personality_prompt', 'tts_personality_prompt', 'mod_readingassessment');
        $mform->hideIf('tts_personality_prompt', 'activitytype', 'eq', 'assessment');

        // Interactive Preset Switcher Script
        $personality_js = '
        <script>
        (function() {
            const presets = {
                warm_tutor: `Accent/Affect: Warm, refined, and gently instructive, reminiscent of a friendly tutor.\\nTone: Calm, encouraging, and articulate, clearly describing each step with patience.\\nPacing: Slow and deliberate, pausing often to allow the listener to follow instructions comfortably.\\nEmotion: Cheerful, supportive, and pleasantly enthusiastic; convey genuine enjoyment and appreciation of reading.\\nPronunciation: Clearly articulate phonemes, syllables, and vocabulary words with gentle emphasis.\\nPersonality Affect: Friendly and approachable with a hint of sophistication; speak confidently and reassuringly, guiding learners through each reading and blending step patiently and warmly.`,
                calm_specialist: `Accent/Affect: Calm, clear, and methodical reading specialist.\\nTone: Soothing, patient, and precise.\\nPacing: Very slow and measured, giving clear auditory space between phonemes.\\nEmotion: Encouraging, supportive, and grounded.\\nPronunciation: Exact phoneme clarity without adding unnecessary schwas (e.g. /f/ not fuh).\\nPersonality Affect: Highly attentive, reassuring coach.`,
                cheerful_coach: `Accent/Affect: Bright, energetic, and celebratory.\\nTone: Upbeat, motivating, and full of positive reinforcement.\\nPacing: Dynamic and lively with clear articulation.\\nEmotion: Highly enthusiastic, delighted by student progress.\\nPronunciation: Crisp, punchy phonics and spirited word blending.\\nPersonality Affect: Fun, engaging reading champion.`,
                storybook_narrator: `Accent/Affect: Expressive, cinematic storybook narrator.\\nTone: Rich, engaging, and imaginative.\\nPacing: Melodic and steady, matching the rhythm of children\'s literature.\\nEmotion: Warm, inviting, and captivating.\\nPronunciation: Expressive and resonant, highlighting phonics and story motifs.\\nPersonality Affect: Classic, comforting bedtime story voice.`
            };

            function setupPersonalitySwitcher() {
                const selectEl = document.getElementsByName("tts_personality_preset")[0];
                const textEl = document.getElementsByName("tts_personality_prompt")[0];
                if (!selectEl || !textEl) return;

                selectEl.addEventListener("change", function() {
                    const val = selectEl.value;
                    if (presets[val]) {
                        textEl.value = presets[val];
                    }
                });
            }

            if (document.readyState === "loading") {
                document.addEventListener("DOMContentLoaded", setupPersonalitySwitcher);
            } else {
                setupPersonalitySwitcher();
            }
        })();
        </script>
        ';
        $mform->addElement('static', 'tts_personality_switcher_js', '', $personality_js);
        $mform->hideIf('tts_personality_switcher_js', 'activitytype', 'eq', 'assessment');


        // =========================================================================
        // SECTION 3: NON-READER PHONICS & PICTURE-WORD STUDIO SETUP
        // =========================================================================
        $mform->addElement('header', 'nonreader_section', '🎨 Non-Reader Phonics & Picture-Word Studio Setup');
        $mform->hideIf('nonreader_section', 'activitytype', 'neq', 'nonreader');

        $mform->addElement('hidden', 'nonreader_data', '');
        $mform->setType('nonreader_data', PARAM_RAW);

        $nonreader_builder_html = '
        <div id="ra-nonreader-builder" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
            <div style="background: #ffffff; border: 1px solid #cbd5e1; border-radius: 10px; padding: 16px; margin-bottom: 18px; box-shadow: 0 2px 5px rgba(0,0,0,0.02);">
                <div style="font-size: 1.05rem; font-weight: 700; color: #1e3a8a; margin-bottom: 4px;">
                    🔤 Stages 1 & 2: Letter Sounds Set
                </div>
                <div style="color: #64748b; font-size: 0.88rem; margin-bottom: 10px;">
                    Enter the letter sounds to teach (comma-separated). Students will hear pure speech sounds, repeat each sound, and take the random challenge.
                </div>
                <input type="text" id="ra-nr-letters-input" class="form-control" placeholder="e.g. a, e, i, o, u or s, a, t, p, i, n" style="width: 100%; font-weight: 600; font-size: 1.05rem; border-radius: 8px;">
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <div style="font-size: 1.05rem; font-weight: 700; color: #1e3a8a;">
                        🖼️ Stage 3: Picture Word Cards & Progressive Blending
                    </div>
                    <div style="color: #64748b; font-size: 0.88rem; margin-top: 2px;">
                        Word cards are automatically segmented into progressive successive blends (/f/ → /f/+/i/=/fi/ → /fi/+/sh/=/fish/ → fish).
                    </div>
                </div>
                <button type="button" id="ra-btn-add-picture-word" class="btn btn-primary btn-sm" style="font-weight: 700; border-radius: 8px; padding: 8px 18px;">
                    ➕ Add Picture Word Card
                </button>
            </div>

            <div id="ra-nr-words-list" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Word Cards dynamically rendered -->
            </div>
        </div>

        <script>
        (function() {
            function escapeHtml(str) {
                if (!str) return "";
                return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
            }

            let nrData = {
                letters: "a, e, i, o, u",
                words: [
                    { word: "fish", image: "https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400", letters: "f, i, s, h" },
                    { word: "cat", image: "https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400", letters: "c, a, t" },
                    { word: "sun", image: "https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400", letters: "s, u, n" }
                ]
            };

            const hiddenInput = document.getElementsByName("nonreader_data")[0];
            const lettersInput = document.getElementById("ra-nr-letters-input");
            const wordsContainer = document.getElementById("ra-nr-words-list");
            const addWordBtn = document.getElementById("ra-btn-add-picture-word");

            try {
                if (hiddenInput && hiddenInput.value) {
                    const parsed = JSON.parse(hiddenInput.value);
                    if (parsed && typeof parsed === "object") nrData = parsed;
                }
            } catch(e) {}

            function syncData() {
                if (lettersInput) nrData.letters = lettersInput.value;
                if (hiddenInput) hiddenInput.value = JSON.stringify(nrData);
            }

            function renderWords() {
                if (!wordsContainer) return;
                wordsContainer.innerHTML = "";

                if (lettersInput) lettersInput.value = nrData.letters || "a, e, i, o, u";

                (nrData.words || []).forEach((w, idx) => {
                    const card = document.createElement("div");
                    card.style.background = "#ffffff";
                    card.style.border = "1px solid #cbd5e1";
                    card.style.borderRadius = "10px";
                    card.style.padding = "16px";
                    card.style.boxShadow = "0 2px 6px rgba(0,0,0,0.03)";

                    card.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                            <span style="font-weight: 700; color: #1e3a8a; font-size: 1rem;">Picture Word #${idx + 1}: <em>"${escapeHtml(w.word || "")}"</em></span>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteNRWord(${idx})">🗑️ Delete</button>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 2fr 1.5fr; gap: 12px; align-items: center;">
                            <div>
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Target Word:</label>
                                <input type="text" class="form-control" value="${escapeHtml(w.word || "")}" oninput="updateNRWordProp(${idx}, \'word\', this.value)" placeholder="e.g. fish">
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Image URL (Picture link):</label>
                                <input type="text" class="form-control" value="${escapeHtml(w.image || "")}" oninput="updateNRWordProp(${idx}, \'image\', this.value)" placeholder="https://example.com/fish.png">
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Phonemes / Digraphs:</label>
                                <input type="text" class="form-control" value="${escapeHtml(w.letters || "")}" oninput="updateNRWordProp(${idx}, \'letters\', this.value)" placeholder="f, i, sh">
                            </div>
                        </div>
                        ${w.image ? `<div style="margin-top: 10px;"><img src="${escapeHtml(w.image)}" style="height: 60px; border-radius: 6px; object-fit: cover; border: 1px solid #cbd5e1;"></div>` : ""}
                    `;

                    wordsContainer.appendChild(card);
                });

                syncData();
            }

            window.updateNRWordProp = function(idx, prop, val) {
                if (nrData.words && nrData.words[idx]) {
                    nrData.words[idx][prop] = val;
                    if (prop === "word" && !nrData.words[idx].letters) {
                        nrData.words[idx].letters = val.split("").join(", ");
                    }
                    syncData();
                }
            };

            window.deleteNRWord = function(idx) {
                if (nrData.words && confirm("Remove this picture word card?")) {
                    nrData.words.splice(idx, 1);
                    renderWords();
                }
            };

            if (lettersInput) {
                lettersInput.addEventListener("input", syncData);
            }

            if (addWordBtn) {
                addWordBtn.addEventListener("click", function() {
                    if (!nrData.words) nrData.words = [];
                    nrData.words.push({ word: "star", image: "https://images.unsplash.com/photo-1518709268805-4e9042af9f23?w=400", letters: "s, t, a, r" });
                    renderWords();
                });
            }

            renderWords();
        })();
        </script>
        ';

        $mform->addElement('static', 'nonreader_builder_container', '', $nonreader_builder_html);
        $mform->hideIf('nonreader_data', 'activitytype', 'neq', 'nonreader');
        $mform->hideIf('nonreader_builder_container', 'activitytype', 'neq', 'nonreader');


        // =========================================================================
        // SECTION 4: READING PASSAGE & TEXT
        // =========================================================================
        $mform->addElement('header', 'passage_section', '📖 ' . get_string('passage', 'mod_readingassessment'));
        $mform->hideIf('passage_section', 'activitytype', 'eq', 'nonreader');

        $mform->addElement('textarea', 'passage', get_string('passage', 'mod_readingassessment'), 'wrap="virtual" rows="10" cols="80" style="font-family: inherit; font-size: 1rem; line-height: 1.6; border-radius: 8px;"');
        $mform->setType('passage', PARAM_TEXT);
        $mform->addHelpButton('passage', 'passage', 'mod_readingassessment');
        $mform->hideIf('passage', 'activitytype', 'eq', 'nonreader');


        // =========================================================================
        // SECTION 5: COMPREHENSION & QUESTIONNAIRE BUILDER (17 Supported Types)
        // =========================================================================
        $mform->addElement('header', 'questions_builder_section', '📋 Comprehension & Questionnaire Builder');
        $mform->hideIf('questions_builder_section', 'activitytype', 'eq', 'nonreader');

        $mform->addElement('hidden', 'questions_json', '[]');
        $mform->setType('questions_json', PARAM_RAW);

        $builder_html = '
        <div id="ra-question-builder" style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; margin-bottom: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px;">
                <div>
                    <h4 style="margin: 0; color: #1e293b; font-weight: 700; font-size: 1.1rem;">Comprehension Items</h4>
                    <div style="color: #64748b; font-size: 0.88rem; margin-top: 2px;">Add questions that students will answer directly on the reading page after completing the passage.</div>
                </div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <select id="ra-new-qtype-select" style="padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; font-weight: 600; color: #334155; background: #ffffff;">
                        <option value="multichoice">🔘 Multiple Choice</option>
                        <option value="truefalse">⚖️ True / False</option>
                        <option value="matching">🔄 Matching</option>
                        <option value="shortanswer">✏️ Short Answer</option>
                        <option value="numerical">🔢 Numerical</option>
                        <option value="essay">📝 Essay (Long Response)</option>
                        <option value="calculated">🧮 Calculated</option>
                        <option value="calculatedmulti">🧮 Calculated Multichoice</option>
                        <option value="calculatedsimple">🧮 Calculated Simple</option>
                        <option value="ddtext">🧩 Drag and Drop into Text</option>
                        <option value="ddmarker">📍 Drag and Drop Markers</option>
                        <option value="ddimage">🖼️ Drag and Drop onto Image</option>
                        <option value="cloze">🔤 Embedded Answers (Cloze)</option>
                        <option value="ordering">🔃 Ordering / Sequence</option>
                        <option value="randommatch">🎲 Random Matching</option>
                        <option value="selectmissing">🔲 Select Missing Words</option>
                        <option value="description">ℹ️ Description / Instructions / Rubric</option>
                    </select>
                    <button type="button" id="ra-btn-add-item" class="btn btn-primary" style="font-weight: 700; border-radius: 8px; padding: 8px 18px;">
                        ➕ Add Item
                    </button>
                </div>
            </div>

            <div id="ra-questions-list" style="display: flex; flex-direction: column; gap: 14px;">
                <!-- Dynamically generated question cards -->
            </div>

            <div id="ra-empty-questions-msg" style="text-align: center; padding: 32px 20px; background: #ffffff; border: 2px dashed #cbd5e1; border-radius: 10px; color: #64748b;">
                <div style="font-size: 2.2rem; margin-bottom: 8px;">📝</div>
                <div style="font-weight: 700; color: #334155; font-size: 1.05rem;">No questions added yet.</div>
                <div style="font-size: 0.88rem; color: #64748b; margin-top: 4px;">Select a question type above and click <strong>[➕ Add Item]</strong>. If left empty, the activity will evaluate Reading Fluency only.</div>
            </div>
        </div>

        <script>
        (function() {
            function escapeHtml(str) {
                if (!str) return "";
                return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\x27/g, "&#039;");
            }

            let questions = [];
            const hiddenInput = document.getElementsByName("questions_json")[0];
            const listContainer = document.getElementById("ra-questions-list");
            const emptyMsg = document.getElementById("ra-empty-questions-msg");
            const addBtn = document.getElementById("ra-btn-add-item");
            const typeSelect = document.getElementById("ra-new-qtype-select");

            try {
                if (hiddenInput && hiddenInput.value) {
                    questions = JSON.parse(hiddenInput.value);
                    if (!Array.isArray(questions)) questions = [];
                }
            } catch(e) {
                questions = [];
            }

            function syncJSON() {
                if (hiddenInput) {
                    hiddenInput.value = JSON.stringify(questions);
                }
                if (emptyMsg) {
                    emptyMsg.style.display = questions.length === 0 ? "block" : "none";
                }
            }

            function renderQuestions() {
                if (!listContainer) return;
                listContainer.innerHTML = "";

                questions.forEach((q, idx) => {
                    const card = document.createElement("div");
                    card.style.background = "#ffffff";
                    card.style.border = "1px solid #cbd5e1";
                    card.style.borderRadius = "10px";
                    card.style.padding = "16px";
                    card.style.boxShadow = "0 2px 6px rgba(0,0,0,0.03)";

                    let typeBadgeName = q.type;
                    let typeBadgeColor = "#0284c7";

                    switch(q.type) {
                        case "multichoice": typeBadgeName = "Multiple Choice"; break;
                        case "truefalse": typeBadgeName = "True/False"; typeBadgeColor = "#16a34a"; break;
                        case "matching": typeBadgeName = "Matching"; typeBadgeColor = "#7c3aed"; break;
                        case "shortanswer": typeBadgeName = "Short Answer"; typeBadgeColor = "#d97706"; break;
                        case "numerical": typeBadgeName = "Numerical"; typeBadgeColor = "#0891b2"; break;
                        case "essay": typeBadgeName = "Essay"; typeBadgeColor = "#4f46e5"; break;
                        case "calculated": typeBadgeName = "Calculated"; typeBadgeColor = "#9333ea"; break;
                        case "calculatedmulti": typeBadgeName = "Calculated Multichoice"; typeBadgeColor = "#9333ea"; break;
                        case "calculatedsimple": typeBadgeName = "Calculated Simple"; typeBadgeColor = "#9333ea"; break;
                        case "ddtext": typeBadgeName = "Drag and Drop into Text"; typeBadgeColor = "#059669"; break;
                        case "ddmarker": typeBadgeName = "Drag and Drop Markers"; typeBadgeColor = "#059669"; break;
                        case "ddimage": typeBadgeName = "Drag and Drop onto Image"; typeBadgeColor = "#059669"; break;
                        case "cloze": typeBadgeName = "Embedded Answers (Cloze)"; typeBadgeColor = "#b91c1c"; break;
                        case "ordering": typeBadgeName = "Ordering"; typeBadgeColor = "#2563eb"; break;
                        case "randommatch": typeBadgeName = "Random Matching"; typeBadgeColor = "#7c3aed"; break;
                        case "selectmissing": typeBadgeName = "Select Missing Words"; typeBadgeColor = "#059669"; break;
                        case "description": typeBadgeName = "Description / Label"; typeBadgeColor = "#475569"; break;
                    }

                    let contentHtml = "";

                    // 1. Multiple Choice
                    if (q.type === "multichoice") {
                        const opts = q.options || ["", "", "", ""];
                        const correct = q.correct !== undefined ? q.correct : 0;
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Question Prompt:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Enter question..." style="width: 100%;">
                            </div>
                            <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem; color: #475569;">Choices (Select radio for correct answer):</div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                ${opts.map((opt, oidx) => `
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <input type="radio" name="ra_correct_${idx}" value="${oidx}" ${correct === oidx ? "checked" : ""} onchange="updateQProp(${idx}, \'correct\', ${oidx})" title="Mark as correct">
                                        <span style="font-weight: 700; width: 24px; color: #64748b;">${String.fromCharCode(65 + oidx)}.</span>
                                        <input type="text" class="form-control" value="${escapeHtml(opt)}" oninput="updateQOption(${idx}, ${oidx}, this.value)" placeholder="Option ${String.fromCharCode(65 + oidx)}" style="flex: 1;">
                                    </div>
                                `).join("")}
                            </div>
                        `;
                    }
                    // 2. True / False
                    else if (q.type === "truefalse") {
                        const isTrue = q.correct === true || q.correct === "true" || q.correct === 1;
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Statement Text:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Enter statement..." style="width: 100%;">
                            </div>
                            <div style="display: flex; gap: 16px; align-items: center;">
                                <span style="font-weight: 600; font-size: 0.9rem; color: #334155;">Correct Answer:</span>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="radio" name="ra_tf_${idx}" value="true" ${isTrue ? "checked" : ""} onchange="updateQProp(${idx}, \'correct\', true)"> True
                                </label>
                                <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                    <input type="radio" name="ra_tf_${idx}" value="false" ${!isTrue ? "checked" : ""} onchange="updateQProp(${idx}, \'correct\', false)"> False
                                </label>
                            </div>
                        `;
                    }
                    // 3. Matching & Random Matching
                    else if (q.type === "matching" || q.type === "randommatch") {
                        const pairs = q.pairs || [{question: "", answer: ""}, {question: "", answer: ""}];
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Instruction / Prompt:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Match the following items..." style="width: 100%;">
                            </div>
                            <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem; color: #475569;">Matching Pairs:</div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                ${pairs.map((p, pidx) => `
                                    <div style="display: flex; gap: 10px; align-items: center;">
                                        <input type="text" class="form-control" value="${escapeHtml(p.question)}" oninput="updateQPair(${idx}, ${pidx}, \'question\', this.value)" placeholder="Item / Question ${pidx + 1}" style="flex: 1;">
                                        <span style="color: #94a3b8;">➔</span>
                                        <input type="text" class="form-control" value="${escapeHtml(p.answer)}" oninput="updateQPair(${idx}, ${pidx}, \'answer\', this.value)" placeholder="Matching Answer ${pidx + 1}" style="flex: 1;">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQPair(${idx}, ${pidx})">✖</button>
                                    </div>
                                `).join("")}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addQPair(${idx})" style="margin-top: 8px;">➕ Add Pair</button>
                        `;
                    }
                    // 4. Short Answer
                    else if (q.type === "shortanswer") {
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Question Prompt:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Enter question..." style="width: 100%;">
                            </div>
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Accepted Answer(s) (separate multiple variations by comma):</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.accepted_answers || "")}" oninput="updateQProp(${idx}, \'accepted_answers\', this.value)" placeholder="e.g. caterpillar, larva, a caterpillar" style="width: 100%;">
                            </div>
                            <label style="display: flex; align-items: center; gap: 6px; font-size: 0.85rem; color: #475569; cursor: pointer;">
                                <input type="checkbox" ${q.casesensitive ? "checked" : ""} onchange="updateQProp(${idx}, \'casesensitive\', this.checked)"> Case sensitive matching
                            </label>
                        `;
                    }
                    // 5. Numerical
                    else if (q.type === "numerical") {
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Question Prompt:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Enter math/numerical question..." style="width: 100%;">
                            </div>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <div>
                                    <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Target Number:</label>
                                    <input type="number" step="any" class="form-control" value="${escapeHtml(q.target_number || "")}" oninput="updateQProp(${idx}, \'target_number\', this.value)" placeholder="e.g. 42">
                                </div>
                                <div>
                                    <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Accepted Error Tolerance (±):</label>
                                    <input type="number" step="any" class="form-control" value="${escapeHtml(q.tolerance || "0")}" oninput="updateQProp(${idx}, \'tolerance\', this.value)" placeholder="e.g. 0.5">
                                </div>
                            </div>
                        `;
                    }
                    // 6. Essay
                    else if (q.type === "essay") {
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Essay / Reflection Prompt:</label>
                                <textarea class="form-control" rows="3" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Explain how the protagonist changed...">${escapeHtml(q.question || "")}</textarea>
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Teacher Rubric / Grading Notes (Optional):</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.rubric || "")}" oninput="updateQProp(${idx}, \'rubric\', this.value)" placeholder="Grade based on clear evidence from text...">
                            </div>
                        `;
                    }
                    // 7. Drag & Drop into Text
                    else if (q.type === "ddtext" || q.type === "selectmissing") {
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Text with Gap Placeholders (use [[1]], [[2]] for blanks):</label>
                                <textarea class="form-control" rows="3" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="The hungry [[1]] transformed into a beautiful [[2]].">${escapeHtml(q.question || "")}</textarea>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Missing Words in Order ([[1]], [[2]], etc., separated by comma):</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.answers_csv || "")}" oninput="updateQProp(${idx}, \'answers_csv\', this.value)" placeholder="e.g. caterpillar, butterfly">
                            </div>
                        `;
                    }
                    // 8. Ordering / Sequence
                    else if (q.type === "ordering") {
                        const items = q.items || ["", "", ""];
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Instruction:</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.question || "")}" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Put these story events in chronological order..." style="width: 100%;">
                            </div>
                            <div style="margin-bottom: 8px; font-weight: 600; font-size: 0.85rem; color: #475569;">Items in Correct Order (will be shuffled for student):</div>
                            <div style="display: flex; flex-direction: column; gap: 8px;">
                                ${items.map((item, itidx) => `
                                    <div style="display: flex; gap: 8px; align-items: center;">
                                        <span style="font-weight: 700; width: 24px; color: #64748b;">${itidx + 1}.</span>
                                        <input type="text" class="form-control" value="${escapeHtml(item)}" oninput="updateQOrderingItem(${idx}, ${itidx}, this.value)" placeholder="Step / Event ${itidx + 1}" style="flex: 1;">
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeQOrderingItem(${idx}, ${itidx})">✖</button>
                                    </div>
                                `).join("")}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addQOrderingItem(${idx})" style="margin-top: 8px;">➕ Add Step</button>
                        `;
                    }
                    // 9. Generic / Cloze / Calculated / DD Marker / Description
                    else {
                        contentHtml = `
                            <div style="margin-bottom: 12px;">
                                <label style="font-weight: 600; font-size: 0.9rem; color: #334155;">Content / Prompt:</label>
                                <textarea class="form-control" rows="3" oninput="updateQProp(${idx}, \'question\', this.value)" placeholder="Enter item content / formula / description...">${escapeHtml(q.question || "")}</textarea>
                            </div>
                            <div>
                                <label style="font-weight: 600; font-size: 0.85rem; color: #475569;">Expected Answer / Details (Optional):</label>
                                <input type="text" class="form-control" value="${escapeHtml(q.answer_details || "")}" oninput="updateQProp(${idx}, \'answer_details\', this.value)" placeholder="Answer keys, formula parameters, or marker coordinates...">
                            </div>
                        `;
                    }

                    card.innerHTML = `
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px;">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span style="font-weight: 800; color: #1e293b;">#${idx + 1}</span>
                                <span style="background: ${typeBadgeColor}; color: #ffffff; padding: 3px 10px; border-radius: 9999px; font-size: 0.75rem; font-weight: 700;">${typeBadgeName}</span>
                            </div>
                            <div style="display: flex; gap: 6px;">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveQ(${idx}, -1)" ${idx === 0 ? "disabled" : ""}>⬆</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveQ(${idx}, 1)" ${idx === questions.length - 1 ? "disabled" : ""}>⬇</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteQ(${idx})">🗑️ Delete</button>
                            </div>
                        </div>
                        ${contentHtml}
                    `;

                    listContainer.appendChild(card);
                });

                syncJSON();
            }

            window.updateQProp = function(idx, prop, val) {
                if (questions[idx]) {
                    questions[idx][prop] = val;
                    syncJSON();
                }
            };

            window.updateQOption = function(idx, oidx, val) {
                if (questions[idx]) {
                    if (!questions[idx].options) questions[idx].options = ["", "", "", ""];
                    questions[idx].options[oidx] = val;
                    syncJSON();
                }
            };

            window.updateQPair = function(idx, pidx, prop, val) {
                if (questions[idx] && questions[idx].pairs && questions[idx].pairs[pidx]) {
                    questions[idx].pairs[pidx][prop] = val;
                    syncJSON();
                }
            };

            window.addQPair = function(idx) {
                if (questions[idx]) {
                    if (!questions[idx].pairs) questions[idx].pairs = [];
                    questions[idx].pairs.push({question: "", answer: ""});
                    renderQuestions();
                }
            };

            window.removeQPair = function(idx, pidx) {
                if (questions[idx] && questions[idx].pairs && questions[idx].pairs.length > 1) {
                    questions[idx].pairs.splice(pidx, 1);
                    renderQuestions();
                }
            };

            window.updateQOrderingItem = function(idx, itidx, val) {
                if (questions[idx] && questions[idx].items) {
                    questions[idx].items[itidx] = val;
                    syncJSON();
                }
            };

            window.addQOrderingItem = function(idx) {
                if (questions[idx]) {
                    if (!questions[idx].items) questions[idx].items = [];
                    questions[idx].items.push("");
                    renderQuestions();
                }
            };

            window.removeQOrderingItem = function(idx, itidx) {
                if (questions[idx] && questions[idx].items && questions[idx].items.length > 1) {
                    questions[idx].items.splice(itidx, 1);
                    renderQuestions();
                }
            };

            window.moveQ = function(idx, dir) {
                const target = idx + dir;
                if (target >= 0 && target < questions.length) {
                    const temp = questions[idx];
                    questions[idx] = questions[target];
                    questions[target] = temp;
                    renderQuestions();
                }
            };

            window.deleteQ = function(idx) {
                if (confirm("Delete this question item?")) {
                    questions.splice(idx, 1);
                    renderQuestions();
                }
            };

            if (addBtn && typeSelect) {
                addBtn.addEventListener("click", function() {
                    const selectedType = typeSelect.value;
                    const newQ = { type: selectedType, question: "" };

                    if (selectedType === "multichoice") {
                        newQ.options = ["", "", "", ""];
                        newQ.correct = 0;
                    } else if (selectedType === "truefalse") {
                        newQ.correct = true;
                    } else if (selectedType === "matching" || selectedType === "randommatch") {
                        newQ.pairs = [{question: "", answer: ""}, {question: "", answer: ""}];
                    } else if (selectedType === "ordering") {
                        newQ.items = ["", "", ""];
                    }

                    questions.push(newQ);
                    renderQuestions();
                });
            }

            renderQuestions();
        })();
        </script>
        ';

        $mform->addElement('static', 'questions_builder_container', '', $builder_html);
        $mform->hideIf('questions_json', 'activitytype', 'eq', 'nonreader');
        $mform->hideIf('questions_builder_container', 'activitytype', 'eq', 'nonreader');


        // =========================================================================
        // SECTION 6: STUDENT ATTEMPT & GRADING CONTROLS
        // =========================================================================
        $mform->addElement('header', 'attempt_settings', '⏱️ ' . get_string('attempt_history', 'mod_readingassessment'));
        
        $attempt_options = [
            0  => get_string('unlimited', 'mod_readingassessment'),
            1  => '1 attempt',
            2  => '2 attempts',
            3  => '3 attempts',
            5  => '5 attempts',
            10 => '10 attempts',
        ];
        $mform->addElement('select', 'maxattempts', get_string('maxattempts', 'mod_readingassessment'), $attempt_options);
        $mform->setType('maxattempts', PARAM_INT);
        $mform->setDefault('maxattempts', 0);
        $mform->addHelpButton('maxattempts', 'maxattempts', 'mod_readingassessment');

        // Standard Moodle Course Module Elements (Common module settings, Activity completion, Restrict access)
        $this->standard_coursemodule_elements();

        // Standard Moodle action buttons (Save & Return, Save & Display, Cancel)
        $this->add_action_buttons();
    }

    public function data_preprocessing(&$default_values) {
        parent::data_preprocessing($default_values);

        if (!isset($default_values['mastery_repetitions'])) {
            $default_values['mastery_repetitions'] = 2;
        } else {
            $default_values['mastery_repetitions'] = intval($default_values['mastery_repetitions']);
        }

        if (empty($default_values['questions_json'])) {
            $default_values['questions_json'] = '[]';
        }

        if (empty($default_values['nonreader_data'])) {
            $default_values['nonreader_data'] = json_encode([
                'letters' => 'a, e, i, o, u',
                'words' => [
                    ['word' => 'fish', 'image' => 'https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400', 'letters' => 'f, i, s, h'],
                    ['word' => 'cat', 'image' => 'https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400', 'letters' => 'c, a, t'],
                    ['word' => 'sun', 'image' => 'https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400', 'letters' => 's, u, n']
                ]
            ]);
        }

        if (empty($default_values['tts_personality_prompt'])) {
            $default_values['tts_personality_prompt'] = "Accent/Affect: Warm, refined, and gently instructive, reminiscent of a friendly tutor.\n" .
                                                        "Tone: Calm, encouraging, and articulate, clearly describing each step with patience.\n" .
                                                        "Pacing: Slow and deliberate, pausing often to allow the listener to follow instructions comfortably.\n" .
                                                        "Emotion: Cheerful, supportive, and pleasantly enthusiastic; convey genuine enjoyment and appreciation of reading.\n" .
                                                        "Pronunciation: Clearly articulate phonemes, syllables, and vocabulary words with gentle emphasis.\n" .
                                                        "Personality Affect: Friendly and approachable with a hint of sophistication; speak confidently and reassuringly, guiding learners through each reading and blending step patiently and warmly.";
        }
    }
}
