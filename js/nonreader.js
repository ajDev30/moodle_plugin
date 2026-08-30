/**
 * Non-Reader & Early Phonics Studio - Continuous Progressive Blending Tutor
 * Science of Reading Successive Phoneme Blending:
 * /f/ -> /f/ + /i/ = /fi/ -> /fi/ + /sh/ = /fish/ -> "fish"
 * Two-Phase Handshake: Teacher Modeling (HARD MIC MUTE) -> 700ms Cooldown -> Student Turn (MIC ACTIVE)
 */

window.NonReaderStudio = (function() {
    let config = {};
    let isStarted = false;
    let currentStage = 1; // 1: Letter Sounds, 2: Random Challenge, 3: Progressive Blending, 4: Completion
    let currentLetterIndex = 0;
    let lettersList = [];
    let randomLettersList = [];
    let randomLetterIndex = 0;

    let currentWordIndex = 0;
    let wordsList = [];
    let activeStepIndex = 0;

    // Audio & Loopback Protection State (Aligned with Instructional Reader)
    let phonicsAudioMap = {};
    let currentPlayingAudio = null;
    let isAudioPlaying = false;
    let audioCooldownTimer = null;
    let microphoneStream = null;

    // Continuous Streaming Audio & WebRTC State
    let pc = null;
    let dc = null;
    let isStreamingActive = false;
    let isSpeechRecognitionActive = false;
    let speechRecognizer = null;
    let startTime = null;

    let totalTasks = 0;
    let successfulTasks = 0;
    let isAdvancing = false;

    const ASR_SERVICE_URL = "http://localhost:8000";

    const DIGRAPHS = ["sh", "ch", "th", "wh", "ph", "ck", "qu", "ng", "ea", "ee", "oo", "ai", "oa", "ar", "or", "er", "ir", "ur"];

    const PHONEME_MAP = {
        "a": ["a", "ah", "ay", "ae", "eh", "uh", "apple", "ant", "at", "ey"],
        "e": ["e", "eh", "ee", "egg", "elephant", "ed", "echo"],
        "i": ["i", "ih", "eye", "ee", "it", "in", "igloo", "ice"],
        "o": ["o", "ah", "oh", "aw", "octopus", "on", "off", "orange"],
        "u": ["u", "uh", "yu", "oo", "umbrella", "up", "under", "us"],
        "b": ["b", "ba", "beh", "bee", "buh", "bat", "ball", "boy"],
        "c": ["c", "k", "ka", "keh", "kuh", "see", "cat", "car", "cup"],
        "d": ["d", "da", "deh", "duh", "dee", "dog", "duck", "door"],
        "f": ["f", "ef", "fa", "feh", "fuh", "fish", "fox", "fan"],
        "g": ["g", "ga", "geh", "guh", "gee", "goat", "go", "girl"],
        "h": ["h", "ha", "heh", "huh", "aitch", "hat", "hen", "hot"],
        "j": ["j", "ja", "jeh", "juh", "jay", "jam", "jar", "jug"],
        "k": ["k", "ka", "keh", "kuh", "kay", "kite", "king", "key"],
        "l": ["l", "el", "la", "leh", "luh", "lion", "leg", "log"],
        "m": ["m", "em", "ma", "meh", "muh", "moon", "monkey", "man"],
        "n": ["n", "en", "na", "neh", "nuh", "nut", "nest", "net"],
        "p": ["p", "pa", "peh", "puh", "pee", "pig", "pen", "pot"],
        "q": ["q", "qu", "kwa", "cue", "queen", "quiet", "quilt"],
        "r": ["r", "ar", "er", "ra", "reh", "ruh", "ring", "rat", "red"],
        "s": ["s", "es", "sa", "seh", "suh", "sun", "snake", "star"],
        "t": ["t", "ta", "teh", "tuh", "tee", "top", "tent", "tree"],
        "v": ["v", "va", "veh", "vuh", "vee", "van", "vase", "vest"],
        "w": ["w", "wa", "weh", "wuh", "double u", "win", "web", "water"],
        "x": ["x", "ks", "ex", "box", "fox", "xray", "six"],
        "y": ["y", "ya", "yeh", "yuh", "why", "yellow", "yo", "yes"],
        "z": ["z", "za", "zeh", "zuh", "zee", "zed", "zebra", "zoo", "zip"],
        "sh": ["sh", "esh", "sha", "sheh", "shuh", "ship", "shoe", "fish"],
        "ch": ["ch", "cha", "cheh", "chuh", "chair", "chin", "chip"],
        "th": ["th", "the", "tha", "theh", "thuh", "thumb", "think", "three"],
        "fi": ["fi", "fee", "fih", "fye", "fa", "fish"],
        "ca": ["ca", "ka", "kah", "cat", "cap"],
        "su": ["su", "suh", "sah", "soo", "sun"],
        "ma": ["ma", "mah", "mae", "map", "mat"]
    };

    function cleanWord(str) {
        return (str || "").toLowerCase().replace(/[^a-z0-9]/g, '');
    }

    function segmentWord(word) {
        word = (word || "").toLowerCase().trim();
        const phonemes = [];
        let i = 0;
        while (i < word.length) {
            let matched = false;
            for (let dg of DIGRAPHS) {
                if (word.substring(i).startsWith(dg)) {
                    phonemes.push(dg);
                    i += dg.length;
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                phonemes.push(word[i]);
                i += 1;
            }
        }
        return phonemes;
    }

    function stretchSound(p) {
        if (["f", "s", "m", "n", "l", "r", "z", "v", "sh", "th"].includes(p)) {
            return p + p + p;
        }
        return p;
    }

    function buildProgressiveSteps(word) {
        word = (word || "").toLowerCase().trim();
        const phonemes = segmentWord(word);
        const steps = [];
        if (!phonemes.length) return steps;

        // Step 1: First sound
        const first = phonemes[0];
        steps.push({
            step_num: 1,
            type: "first_sound",
            accumulated: first,
            sound_label: `/${first}/`,
            formula_display: `/${first}/`,
            prompt_speech: `Listen: /${stretchSound(first)}/`,
            student_prompt: `Say the first sound: <strong>/${first}/</strong>`
        });

        // Step 2..N: Progressive Accumulations
        let accum = first;
        for (let idx = 1; idx < phonemes.length; idx++) {
            const nextP = phonemes[idx];
            const prev = accum;
            accum += nextP;
            steps.push({
                step_num: idx + 1,
                type: "blend_step",
                prev_chunk: prev,
                next_sound: nextP,
                accumulated: accum,
                sound_label: `/${accum}/`,
                formula_display: `/${prev}/ + /${nextP}/ → /${accum}/`,
                prompt_speech: `/${stretchSound(prev)}/ + /${nextP}/ ... /${accum}/`,
                student_prompt: `Connect the sounds: <strong>/${prev}/ + /${nextP}/ → /${accum}/</strong>`
            });
        }

        // Final whole word
        steps.push({
            step_num: steps.length + 1,
            type: "whole_word",
            accumulated: word,
            sound_label: word,
            formula_display: `Now blend it all together: "${word.toUpperCase()}"!`,
            prompt_speech: `Now blend it all together... ${word}!`,
            student_prompt: `Say the whole word: <strong>"${word.toUpperCase()}"</strong>!`
        });

        return steps;
    }

    // --- HARDWARE MICROPHONE CONTROL & RECOGNITION SUSPENSION ---
    function setMicrophoneEnabled(enabled) {
        // 1. Hardware WebRTC audio track muting
        if (microphoneStream) {
            microphoneStream.getAudioTracks().forEach(track => {
                track.enabled = enabled;
            });
        }

        // 2. Web Speech Recognition abort and pause
        if (speechRecognizer) {
            if (!enabled) {
                try { speechRecognizer.abort(); } catch(e) {}
            } else {
                if (isStarted && !isAudioPlaying && currentStage <= 3) {
                    try { speechRecognizer.start(); } catch(e) {}
                }
            }
        }

        // 3. Update Visual Status Badge
        updateLiveIndicator(enabled, enabled ? "Live Mic Listening" : "🔇 Mic Muted (Teacher Speaking)");
    }

    function stopAllPlayingAudio() {
        if (currentPlayingAudio) {
            try {
                currentPlayingAudio.pause();
                currentPlayingAudio.src = "";
            } catch(e) {}
            currentPlayingAudio = null;
        }
        if ('speechSynthesis' in window) {
            try { window.speechSynthesis.cancel(); } catch(e) {}
        }
        if (audioCooldownTimer) {
            clearTimeout(audioCooldownTimer);
            audioCooldownTimer = null;
        }
    }

    function safePlayPhonicsAudio(textToSpeak, callback) {
        stopAllPlayingAudio();

        isAudioPlaying = true;
        setMicrophoneEnabled(false); // HARD MUTE MIC & ABORT SPEECH RECOGNITION

        const statusEl = document.getElementById("ra-nr-status");
        if (statusEl) {
            statusEl.innerHTML = `<span style="color: #d97706; font-weight: 700;">🔊 Listen to teacher: "/${textToSpeak}/"... (Mic Off)</span>`;
        }

        const cleaned = cleanWord(textToSpeak);
        const b64Audio = phonicsAudioMap[cleaned];

        function onPlaybackFinished() {
            // 700ms acoustic silence cooldown to flush room echoes completely
            audioCooldownTimer = setTimeout(() => {
                isAudioPlaying = false;
                setMicrophoneEnabled(true); // RE-ENABLE MIC & RESTART SPEECH RECOGNITION
                const previewEl = document.getElementById("ra-nr-live-preview");
                if (previewEl) previewEl.innerHTML = "";
                if (callback) callback();
            }, 700);
        }

        if (b64Audio) {
            currentPlayingAudio = new Audio(b64Audio);
            currentPlayingAudio.onended = () => {
                currentPlayingAudio = null;
                onPlaybackFinished();
            };
            currentPlayingAudio.onerror = () => {
                currentPlayingAudio = null;
                fallbackBrowserTTS(textToSpeak, onPlaybackFinished);
            };
            currentPlayingAudio.play().catch(() => {
                currentPlayingAudio = null;
                fallbackBrowserTTS(textToSpeak, onPlaybackFinished);
            });
        } else {
            const asrUrl = config.asr_service_url || ASR_SERVICE_URL;
            const voice = config.tts_voice || "alloy";
            fetch(`${asrUrl}/tts_phoneme_guide`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({
                    text: textToSpeak,
                    voice: voice,
                    instructions: config.tts_personality_prompt || "",
                    speed: 0.88
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.audio) {
                    phonicsAudioMap[cleaned] = data.audio;
                    currentPlayingAudio = new Audio(data.audio);
                    currentPlayingAudio.onended = () => {
                        currentPlayingAudio = null;
                        onPlaybackFinished();
                    };
                    currentPlayingAudio.onerror = () => {
                        currentPlayingAudio = null;
                        fallbackBrowserTTS(textToSpeak, onPlaybackFinished);
                    };
                    currentPlayingAudio.play().catch(() => {
                        currentPlayingAudio = null;
                        fallbackBrowserTTS(textToSpeak, onPlaybackFinished);
                    });
                } else {
                    fallbackBrowserTTS(textToSpeak, onPlaybackFinished);
                }
            })
            .catch(() => fallbackBrowserTTS(textToSpeak, onPlaybackFinished));
        }
    }

    function fallbackBrowserTTS(textToSpeak, callback) {
        if ('speechSynthesis' in window) {
            try { window.speechSynthesis.cancel(); } catch(e) {}
            const utter = new SpeechSynthesisUtterance(textToSpeak);
            utter.lang = 'en-US';
            utter.rate = 0.85;
            utter.pitch = 1.05;
            utter.onend = () => { if (callback) callback(); };
            utter.onerror = () => { if (callback) callback(); };
            window.speechSynthesis.speak(utter);
        } else {
            if (callback) callback();
        }
    }

    function init(cfg) {
        config = cfg || {};
        const asrServiceUrl = config.asr_service_url || ASR_SERVICE_URL;
        const voice = config.tts_voice || "alloy";

        // Parse letters
        const rawLetters = (config.nonreader_data && config.nonreader_data.letters) ? config.nonreader_data.letters : "a, e, i, o, u";
        lettersList = rawLetters.split(",").map(l => l.trim().toLowerCase()).filter(l => l.length > 0);
        if (lettersList.length === 0) lettersList = ["a", "e", "i", "o", "u"];

        randomLettersList = [...lettersList].sort(() => 0.5 - Math.random());

        // Parse picture words
        const rawWords = (config.nonreader_data && config.nonreader_data.words) ? config.nonreader_data.words : [
            { word: "fish", image: "https://images.unsplash.com/photo-1524704654690-b56c05c78a00?w=400" },
            { word: "cat", image: "https://images.unsplash.com/photo-1514888286974-6c03e2ca1dba?w=400" },
            { word: "sun", image: "https://images.unsplash.com/photo-1538370965046-79c0d6907d47?w=400" }
        ];

        wordsList = rawWords.map(w => {
            const clean = (w.word || "").toLowerCase().trim();
            return {
                word: clean,
                image: w.image || "",
                steps: buildProgressiveSteps(clean)
            };
        }).filter(w => w.word.length > 0);

        totalTasks = lettersList.length + randomLettersList.length + wordsList.length;

        // Pre-fetch all letters, progressive chunks, and target words in background (Aligned with Instructional Reader)
        const allAudioTokens = [...lettersList];
        wordsList.forEach(w => {
            allAudioTokens.push(w.word);
            (w.steps || []).forEach(st => {
                if (st.accumulated) allAudioTokens.push(st.accumulated);
                if (st.prev_chunk) allAudioTokens.push(st.prev_chunk);
                if (st.next_sound) allAudioTokens.push(st.next_sound);
            });
        });

        fetch(`${asrServiceUrl}/tts_words`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                words: allAudioTokens,
                voice: voice,
                instructions: config.tts_personality_prompt || "",
                speed: 0.88
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.audio_map) phonicsAudioMap = data.audio_map;
        })
        .catch(e => console.warn("Phonics TTS background pre-fetch fallback to browser:", e));

        renderWelcomeScreen();
    }

    function renderWelcomeScreen() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        container.innerHTML = `
            <div class="ra-nr-card" style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 4rem; margin-bottom: 14px;">🎨🔤🖼️</div>
                <h2 style="font-size: 2rem; font-weight: 800; color: #1e3a8a; margin-bottom: 10px;">
                    Continuous Phoneme Blending Tutor
                </h2>
                <p style="font-size: 1.15rem; color: #475569; max-width: 620px; margin: 0 auto 26px; line-height: 1.5;">
                    We learn pure speech sounds and blend them progressively into words (e.g. <em>/f/ → /f/ + /i/ = /fi/ → /fi/ + /sh/ = /fish/</em>) using live voice streaming!
                </p>

                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 14px; padding: 18px; max-width: 540px; margin: 0 auto 30px; text-align: left;">
                    <div style="font-weight: 700; color: #166534; font-size: 1.05rem; margin-bottom: 6px;">✨ What we will do:</div>
                    <ul style="margin: 0; padding-left: 22px; color: #15803d; font-size: 0.95rem; line-height: 1.6;">
                        <li><strong>Stage 1:</strong> Learn individual speech sounds (/a/, /e/, /i/, /o/, /u/).</li>
                        <li><strong>Stage 2:</strong> Random letter sound challenge.</li>
                        <li><strong>Stage 3:</strong> Progressive successive blending (${wordsList.map(w => w.word).join(', ')})!</li>
                    </ul>
                </div>

                <button class="ra-btn ra-btn-start" onclick="NonReaderStudio.startPhonicsStudio()" style="font-size: 1.25rem; padding: 16px 48px; border-radius: 9999px; box-shadow: 0 6px 16px rgba(2, 132, 199, 0.35);">
                    <span>▶</span> Start Blending Studio (Live Streaming Mic)
                </button>
            </div>
        `;
    }

    function renderStudioUI() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        if (currentStage === 1) {
            renderStage1LetterSounds();
        } else if (currentStage === 2) {
            renderStage2RandomChallenge();
        } else if (currentStage === 3) {
            renderStage3ProgressiveBlending();
        } else {
            renderCompletionCelebration();
        }
    }

    async function startPhonicsStudio() {
        isStarted = true;
        startTime = Date.now();
        currentStage = 1;
        currentLetterIndex = 0;
        successfulTasks = 0;
        isAdvancing = false;

        renderStudioUI();
        await startStreamingAudioEngine();
    }

    // --- CONTINUOUS STREAMING AUDIO ENGINE ---
    async function startStreamingAudioEngine() {
        const asrUrl = config.asr_service_url || ASR_SERVICE_URL;

        // 1. Try OpenAI Realtime WebRTC Streaming
        try {
            const sessResp = await fetch(`${asrUrl}/session`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ mode: "transcription", language: "en", personality: config.tts_personality_prompt || "" })
            });

            if (sessResp.ok) {
                const sessData = await sessResp.json();
                const ephemeralKey = sessData.client_secret ? sessData.client_secret.value : sessData.value;

                if (ephemeralKey) {
                    pc = new RTCPeerConnection();
                    const audioEl = document.createElement("audio");
                    audioEl.autoplay = true;
                    pc.ontrack = (e) => (audioEl.srcObject = e.streams[0]);

                    microphoneStream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            echoCancellation: true,
                            noiseSuppression: true,
                            autoGainControl: true,
                            channelCount: 1,
                            sampleRate: 24000
                        }
                    });
                    microphoneStream.getTracks().forEach((track) => pc.addTrack(track, microphoneStream));

                    dc = pc.createDataChannel("oai-events");
                    dc.addEventListener("open", () => {
                        console.log("OpenAI Streaming DataChannel connected for Blending!");
                        isStreamingActive = true;
                        updateLiveIndicator(true, "OpenAI Live Streaming Active");
                    });

                    dc.addEventListener("message", (e) => {
                        try {
                            const event = JSON.parse(e.data);
                            handleStreamingEvent(event);
                        } catch (err) {
                            console.error("Streaming event parse error:", err);
                        }
                    });

                    const offer = await pc.createOffer();
                    await pc.setLocalDescription(offer);

                    const baseUrl = "https://api.openai.com/v1/realtime";
                    const model = "gpt-4o-realtime-preview";
                    const sdpResponse = await fetch(`${baseUrl}?model=${model}`, {
                        method: "POST",
                        body: offer.sdp,
                        headers: {
                            Authorization: `Bearer ${ephemeralKey}`,
                            "Content-Type": "application/sdp"
                        }
                    });

                    if (sdpResponse.ok) {
                        const answerSdp = await sdpResponse.text();
                        await pc.setRemoteDescription({ type: "answer", sdp: answerSdp });
                        return;
                    }
                }
            }
        } catch (err) {
            console.warn("OpenAI WebRTC stream fallback to Continuous Web Speech API:", err);
        }

        // 2. Continuous Web Speech API Fallback Streaming
        startContinuousWebSpeechStream();
    }

    function startContinuousWebSpeechStream() {
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) {
            updateLiveIndicator(false, "Microphone stream unsupported on this browser");
            return;
        }

        try {
            speechRecognizer = new SpeechRec();
            speechRecognizer.continuous = true;
            speechRecognizer.interimResults = true;
            speechRecognizer.lang = "en-US";

            speechRecognizer.onstart = () => {
                isSpeechRecognitionActive = true;
                updateLiveIndicator(true, "Streaming Speech Engine Active");
            };

            speechRecognizer.onresult = (e) => {
                if (isAudioPlaying || isAdvancing) return;

                let interim = "";
                let final = "";

                for (let i = e.resultIndex; i < e.results.length; ++i) {
                    const trans = e.results[i][0].transcript;
                    if (e.results[i].isFinal) {
                        final += trans;
                    } else {
                        interim += trans;
                    }
                }

                const spoken = (final || interim).trim().toLowerCase();
                if (spoken && !isAudioPlaying && !isAdvancing) {
                    handleLiveTranscriptStream(spoken);
                }
            };

            speechRecognizer.onerror = (e) => {
                console.warn("Speech stream notice:", e.error);
            };

            speechRecognizer.onend = () => {
                if (isStarted && !isAudioPlaying && currentStage <= 3) {
                    try { speechRecognizer.start(); } catch(e) {}
                }
            };

            if (!isAudioPlaying) {
                speechRecognizer.start();
            }
        } catch (e) {
            console.error("Continuous Speech Recognition init error:", e);
        }
    }

    function handleStreamingEvent(event) {
        if (isAudioPlaying || isAdvancing) return;

        if (event.type === "conversation.item.input_audio_transcription.completed") {
            const transcript = (event.transcript || "").trim().toLowerCase();
            if (transcript && !isAudioPlaying && !isAdvancing) {
                handleLiveTranscriptStream(transcript);
            }
        } else if (event.type === "response.audio_transcript.delta") {
            const delta = (event.delta || "").trim().toLowerCase();
            if (delta && !isAudioPlaying && !isAdvancing) {
                handleLiveTranscriptStream(delta);
            }
        }
    }

    function updateLiveIndicator(active, text) {
        const dot = document.getElementById("ra-nr-live-dot");
        const label = document.getElementById("ra-nr-live-label");
        if (dot) {
            dot.className = active ? "ra-vad-dot ra-vad-dot-active" : "ra-vad-dot ra-vad-dot-muted";
        }
        if (label) {
            label.textContent = text || (active ? "Live Mic Listening" : "🔇 Mic Muted (Teacher Speaking)");
            label.style.color = active ? "#166534" : "#b45309";
        }
    }

    // --- LIVE STREAMING EVALUATION ---
    async function handleLiveTranscriptStream(rawTranscript) {
        if (!rawTranscript || isAdvancing || isAudioPlaying) return;

        const cleanTranscript = rawTranscript.replace(/[^\w\s]/g, "").trim().toLowerCase();
        const tokens = cleanTranscript.split(/\s+/).filter(t => t.length > 0);
        if (tokens.length === 0) return;

        const previewEl = document.getElementById("ra-nr-live-preview");
        if (previewEl) previewEl.innerHTML = `Heard: <span style="font-weight: 700; color: #1e293b;">"${cleanTranscript}"</span>`;

        if (currentStage === 1) {
            evaluateStage1Stream(tokens, cleanTranscript);
        } else if (currentStage === 2) {
            evaluateStage2Stream(tokens, cleanTranscript);
        } else if (currentStage === 3) {
            evaluateStage3ProgressiveStream(tokens, cleanTranscript);
        }
    }

    function matchLetterSound(targetLetter, tokens, fullTranscript) {
        targetLetter = targetLetter.toLowerCase().trim();
        const allowed = PHONEME_MAP[targetLetter] || [targetLetter];

        for (let t of tokens) {
            if (t === targetLetter || allowed.includes(t)) {
                return true;
            }
        }

        for (let ph of allowed) {
            if (fullTranscript.includes(ph)) {
                return true;
            }
        }

        return false;
    }

    function evaluateStage1Stream(tokens, fullTranscript) {
        if (isAudioPlaying) return;
        const currentLetter = lettersList[currentLetterIndex];
        if (matchLetterSound(currentLetter, tokens, fullTranscript)) {
            isAdvancing = true;
            successfulTasks++;

            const tile = document.getElementById("ra-nr-letter-main");
            const statusEl = document.getElementById("ra-nr-status");
            if (tile) tile.classList.add("tile-success");
            if (statusEl) statusEl.innerHTML = `<span style="color: #15803d; font-weight: 700; font-size: 1.15rem;">🌟 Fantastic! You said /${currentLetter}/!</span>`;

            setTimeout(() => {
                currentLetterIndex++;
                if (currentLetterIndex < lettersList.length) {
                    isAdvancing = false;
                    renderStage1LetterSounds();
                } else {
                    currentStage = 2;
                    isAdvancing = false;
                    renderStage2RandomChallenge();
                }
            }, 1000);
        }
    }

    function evaluateStage2Stream(tokens, fullTranscript) {
        if (isAudioPlaying) return;
        const currentLetter = randomLettersList[randomLetterIndex];
        if (matchLetterSound(currentLetter, tokens, fullTranscript)) {
            isAdvancing = true;
            successfulTasks++;

            const tile = document.getElementById("ra-nr-letter-main");
            const statusEl = document.getElementById("ra-nr-status");
            if (tile) tile.classList.add("tile-success");
            if (statusEl) statusEl.innerHTML = `<span style="color: #15803d; font-weight: 700; font-size: 1.15rem;">🏆 Super job! Correct sound!</span>`;

            setTimeout(() => {
                randomLetterIndex++;
                if (randomLetterIndex < randomLettersList.length) {
                    isAdvancing = false;
                    renderStage2RandomChallenge();
                } else {
                    currentStage = 3;
                    currentWordIndex = 0;
                    activeStepIndex = 0;
                    isAdvancing = false;
                    renderStage3ProgressiveBlending();
                }
            }, 1000);
        }
    }

    function evaluateStage3ProgressiveStream(tokens, fullTranscript) {
        if (isAudioPlaying) return;
        const currentWordObj = wordsList[currentWordIndex];
        const steps = currentWordObj.steps || [];
        const currentStep = steps[activeStepIndex];
        const statusEl = document.getElementById("ra-nr-status");

        if (!currentStep) return;

        let matched = false;
        const targetAccum = currentStep.accumulated.toLowerCase();

        if (currentStep.type === "whole_word") {
            if (tokens.includes(currentWordObj.word) || fullTranscript.includes(currentWordObj.word)) {
                matched = true;
            }
        } else {
            if (matchLetterSound(targetAccum, tokens, fullTranscript) || tokens.includes(targetAccum) || fullTranscript.includes(targetAccum) || fullTranscript.includes(currentWordObj.word)) {
                matched = true;
            }
        }

        if (matched) {
            isAdvancing = true;
            const chainEl = document.getElementById("ra-blend-active-step-tile");
            if (chainEl) chainEl.classList.add("tile-success");

            if (currentStep.type === "whole_word") {
                successfulTasks++;
                if (statusEl) statusEl.innerHTML = `<span style="color: #15803d; font-size: 1.3rem; font-weight: 800;">🎉 HOORAY! You blended "${currentWordObj.word.toUpperCase()}"! 🎉</span>`;

                setTimeout(() => {
                    currentWordIndex++;
                    if (currentWordIndex < wordsList.length) {
                        activeStepIndex = 0;
                        isAdvancing = false;
                        renderStage3ProgressiveBlending();
                    } else {
                        currentStage = 4;
                        isAdvancing = false;
                        renderCompletionCelebration();
                    }
                }, 1400);
            } else {
                if (statusEl) statusEl.innerHTML = `<span style="color: #15803d; font-weight: 700;">✓ Great sound! Moving to next blend:</span>`;

                setTimeout(() => {
                    activeStepIndex++;
                    isAdvancing = false;
                    renderStage3ProgressiveBlending();
                }, 900);
            }
        }
    }

    // --- STAGE 1: LETTER SOUNDS ---
    function renderStage1LetterSounds() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        const currentLetter = lettersList[currentLetterIndex];

        container.innerHTML = `
            <div class="ra-nr-stage-header">
                <div class="ra-nr-badge">Stage 1 of 3: Pure Letter Sounds</div>
                <div class="ra-streaming-status">
                    <span class="ra-vad-dot ra-vad-dot-active" id="ra-nr-live-dot"></span>
                    <span id="ra-nr-live-label" style="font-weight: 600; color: #166534; font-size: 0.9rem;">Live Mic Listening</span>
                </div>
                <div class="ra-nr-progress">Letter ${currentLetterIndex + 1} of ${lettersList.length}</div>
            </div>

            <div class="ra-nr-card">
                <div class="ra-nr-prompt">Listen to the speech sound, then say it out loud:</div>

                <!-- Big Letter Tile -->
                <div class="ra-nr-letter-tile" id="ra-nr-letter-main" onclick="NonReaderStudio.playLetterAudio('${currentLetter}')">
                    <span class="ra-nr-big-letter">${currentLetter.toUpperCase()}</span>
                    <span class="ra-nr-small-letter">/${currentLetter}/</span>
                    <div class="ra-nr-speaker-icon">🔊 Listen</div>
                </div>

                <div class="ra-nr-feedback-msg" id="ra-nr-status">
                    🎙️ <em>Say the sound <strong>"/${currentLetter}/"</strong> into the mic...</em>
                </div>

                <div id="ra-nr-live-preview" style="min-height: 24px; color: #64748b; font-size: 0.95rem; margin-bottom: 12px;"></div>

                <div class="ra-controls" style="justify-content: center; margin-top: 10px;">
                    <button class="ra-btn ra-btn-retry" onclick="NonReaderStudio.playLetterAudio('${currentLetter}')">
                        <span>🔊</span> Hear Sound Again
                    </button>
                </div>
            </div>
        `;

        setTimeout(() => playLetterAudio(currentLetter), 350);
    }

    // --- STAGE 2: RANDOM LETTER CHALLENGE ---
    function renderStage2RandomChallenge() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        const currentLetter = randomLettersList[randomLetterIndex];

        container.innerHTML = `
            <div class="ra-nr-stage-header">
                <div class="ra-nr-badge" style="background: #7c3aed;">Stage 2 of 3: Random Sound Challenge</div>
                <div class="ra-streaming-status">
                    <span class="ra-vad-dot ra-vad-dot-active" id="ra-nr-live-dot"></span>
                    <span id="ra-nr-live-label" style="font-weight: 600; color: #166534; font-size: 0.9rem;">Live Mic Listening</span>
                </div>
                <div class="ra-nr-progress">Letter ${randomLetterIndex + 1} of ${randomLettersList.length}</div>
            </div>

            <div class="ra-nr-card">
                <div class="ra-nr-prompt">Can you say this random speech sound on your own?</div>

                <div class="ra-nr-letter-tile" style="border-color: #8b5cf6;" id="ra-nr-letter-main">
                    <span class="ra-nr-big-letter" style="color: #7c3aed;">${currentLetter.toUpperCase()}</span>
                    <span class="ra-nr-small-letter">/${currentLetter}/</span>
                </div>

                <div class="ra-nr-feedback-msg" id="ra-nr-status">
                    🎙️ <em>Say the sound <strong>"/${currentLetter}/"</strong> into the mic...</em>
                </div>

                <div id="ra-nr-live-preview" style="min-height: 24px; color: #64748b; font-size: 0.95rem; margin-bottom: 12px;"></div>
            </div>
        `;
    }

    // --- STAGE 3: PROGRESSIVE CONTINUOUS BLENDING ---
    function renderStage3ProgressiveBlending() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        const currentWordObj = wordsList[currentWordIndex];
        const steps = currentWordObj.steps || [];
        const currentStep = steps[activeStepIndex] || steps[0];

        let chainHtml = "";
        if (currentStep.type === "first_sound") {
            chainHtml = `
                <div class="ra-blend-chain">
                    <div class="ra-blend-chunk ra-blend-chunk-active animate__animated animate__pulse" id="ra-blend-active-step-tile">
                        <span class="ra-blend-text">${currentStep.accumulated.toUpperCase()}</span>
                        <span class="ra-blend-phoneme">/${currentStep.accumulated}/</span>
                    </div>
                </div>
            `;
        } else if (currentStep.type === "blend_step") {
            chainHtml = `
                <div class="ra-blend-chain">
                    <div class="ra-blend-chunk ra-blend-chunk-done">
                        <span class="ra-blend-text">${currentStep.prev_chunk.toUpperCase()}</span>
                        <span class="ra-blend-phoneme">/${currentStep.prev_chunk}/</span>
                    </div>
                    <div class="ra-blend-symbol">+</div>
                    <div class="ra-blend-chunk ra-blend-chunk-focus">
                        <span class="ra-blend-text">${currentStep.next_sound.toUpperCase()}</span>
                        <span class="ra-blend-phoneme">/${currentStep.next_sound}/</span>
                    </div>
                    <div class="ra-blend-symbol">=</div>
                    <div class="ra-blend-chunk ra-blend-chunk-active animate__animated animate__pulse" id="ra-blend-active-step-tile">
                        <span class="ra-blend-text">${currentStep.accumulated.toUpperCase()}</span>
                        <span class="ra-blend-phoneme">/${currentStep.accumulated}/</span>
                    </div>
                </div>
            `;
        } else if (currentStep.type === "whole_word") {
            chainHtml = `
                <div class="ra-blend-full-banner animate__animated animate__pulse animate__infinite">
                    ⭐ Whole Word: <strong>"${currentWordObj.word.toUpperCase()}"</strong> ⭐
                </div>
            `;
        }

        container.innerHTML = `
            <div class="ra-nr-stage-header">
                <div class="ra-nr-badge" style="background: #059669;">Stage 3 of 3: Continuous Progressive Blending</div>
                <div class="ra-streaming-status">
                    <span class="ra-vad-dot ra-vad-dot-active" id="ra-nr-live-dot"></span>
                    <span id="ra-nr-live-label" style="font-weight: 600; color: #166534; font-size: 0.9rem;">Live Mic Listening</span>
                </div>
                <div class="ra-nr-progress">Word ${currentWordIndex + 1} of ${wordsList.length} (Step ${activeStepIndex + 1} of ${steps.length})</div>
            </div>

            <div class="ra-nr-card">
                <!-- Picture Box with fallback -->
                <div class="ra-nr-picture-box">
                    ${currentWordObj.image ? `<img src="${currentWordObj.image}" alt="${currentWordObj.word}" class="ra-nr-picture-img" onerror="this.style.display='none'; document.getElementById('ra-nr-fallback-icon').style.display='block';"><div id="ra-nr-fallback-icon" style="display:none; font-size: 4.5rem;">🖼️</div>` : '<div style="font-size: 4.5rem;">🖼️</div>'}
                </div>

                <!-- Progressive Formula Badge -->
                <div class="ra-blend-formula-banner">
                    ${currentStep.formula_display}
                </div>

                <!-- Progressive Chain Visual Tiles -->
                ${chainHtml}

                <div class="ra-nr-feedback-msg" id="ra-nr-status">
                    🎙️ <em>${currentStep.student_prompt}</em>
                </div>

                <div id="ra-nr-live-preview" style="min-height: 24px; color: #64748b; font-size: 0.95rem; margin-bottom: 12px;"></div>

                <div class="ra-controls" style="justify-content: center; margin-top: 10px;">
                    <button class="ra-btn ra-btn-retry" onclick="NonReaderStudio.playCurrentStepAudio()">
                        <span>🔊</span> Hear Teacher Guide
                    </button>
                </div>
            </div>
        `;

        setTimeout(() => playCurrentStepAudio(), 350);
    }

    // --- AUDIO PLAYBACK METHODS (SAFE & PROVEN) ---
    function playLetterAudio(letter) {
        const currentLetter = letter || (lettersList[currentLetterIndex]);
        safePlayPhonicsAudio(currentLetter, () => {
            const statusEl = document.getElementById("ra-nr-status");
            if (statusEl) {
                statusEl.innerHTML = `🎙️ <em>Say the sound <strong>"/${currentLetter}/"</strong> into the mic...</em>`;
            }
        });
    }

    function playCurrentStepAudio() {
        const currentWordObj = wordsList[currentWordIndex];
        const steps = currentWordObj.steps || [];
        const currentStep = steps[activeStepIndex];
        if (!currentStep) return;

        const textToModel = currentStep.accumulated;
        safePlayPhonicsAudio(textToModel, () => {
            const statusEl = document.getElementById("ra-nr-status");
            if (statusEl) {
                statusEl.innerHTML = `🎙️ <em>${currentStep.student_prompt}</em>`;
            }
        });
    }

    // --- STAGE 4: COMPLETION CELEBRATION ---
    async function renderCompletionCelebration() {
        const container = document.getElementById("ra-nonreader-studio");
        if (!container) return;

        if (pc) {
            try { pc.close(); } catch(e) {}
        }
        if (microphoneStream) {
            try { microphoneStream.getTracks().forEach(t => t.stop()); } catch(e) {}
        }
        if (speechRecognizer) {
            try { speechRecognizer.stop(); } catch(e) {}
        }

        const totalTime = Math.round((Date.now() - startTime) / 1000);
        const accuracy = Math.round((successfulTasks / Math.max(1, totalTasks)) * 100);

        container.innerHTML = `
            <div class="ra-nr-card" style="text-align: center; padding: 40px 20px;">
                <div style="font-size: 4.5rem; margin-bottom: 12px;" class="animate__animated animate__bounce">🏆🌟🎉</div>
                <h2 style="color: #15803d; font-size: 2rem; font-weight: 800; margin-bottom: 8px;">Super Reader Certificate!</h2>
                <p style="color: #475569; font-size: 1.15rem; max-width: 600px; margin: 0 auto 24px;">
                    Congratulations! You mastered phoneme sounds and blended picture words with live streaming voice!
                </p>

                <div class="ra-kpi-grid" style="max-width: 700px; margin: 0 auto 28px;">
                    <div class="ra-kpi-card">
                        <div class="ra-kpi-icon" style="background: #dcfce7; color: #15803d;">🎯</div>
                        <div class="ra-kpi-val">${accuracy}%</div>
                        <div class="ra-kpi-label">Phonics Accuracy</div>
                    </div>
                    <div class="ra-kpi-card">
                        <div class="ra-kpi-icon" style="background: #e0f2fe; color: #0284c7;">⏱️</div>
                        <div class="ra-kpi-val">${totalTime}s</div>
                        <div class="ra-kpi-label">Activity Time</div>
                    </div>
                    <div class="ra-kpi-card">
                        <div class="ra-kpi-icon" style="background: #fef3c7; color: #b45309;">🔤</div>
                        <div class="ra-kpi-val">${lettersList.length}</div>
                        <div class="ra-kpi-label">Sounds Mastered</div>
                    </div>
                    <div class="ra-kpi-card">
                        <div class="ra-kpi-icon" style="background: #ede9fe; color: #7c3aed;">🖼️</div>
                        <div class="ra-kpi-val">${wordsList.length}</div>
                        <div class="ra-kpi-label">Words Blended</div>
                    </div>
                </div>

                <button id="ra-btn-nr-finish" class="ra-btn ra-btn-start" onclick="NonReaderStudio.submitResults(${accuracy}, ${totalTime})" style="font-size: 1.2rem; padding: 14px 40px; border-radius: 9999px;">
                    <span>📤</span> Save & Complete Activity
                </button>
            </div>
        `;
    }

    async function submitResults(accuracy, totalTime) {
        const btn = document.getElementById("ra-btn-nr-finish");
        if (btn) {
            btn.disabled = true;
            btn.textContent = "Saving results...";
        }

        const wwwroot = config.wwwroot || window.location.origin;
        const submitUrl = `${wwwroot}/mod/readingassessment/view.php?id=${config.cmid}&action=submit`;

        const params = new URLSearchParams({
            transcript: `Non-Reader Blending Completed: ${lettersList.join(', ')} | Words: ${wordsList.map(w => w.word).join(', ')}`,
            accuracy_score: accuracy,
            comprehension_score: 100.0,
            final_grade: accuracy,
            reading_time: totalTime,
            reading_speed: 0.0,
            miscues_json: JSON.stringify([]),
            answers_json: JSON.stringify({ letters: lettersList, words: wordsList, accuracy: accuracy }),
            asr_engine: 'openai_progressive_blending',
            sesskey: config.sesskey || ''
        });

        try {
            await fetch(submitUrl, {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: params
            });
            window.location.reload();
        } catch (e) {
            console.error("Submission error:", e);
            window.location.reload();
        }
    }

    return {
        init: init,
        startPhonicsStudio: startPhonicsStudio,
        playLetterAudio: playLetterAudio,
        playCurrentStepAudio: playCurrentStepAudio,
        submitResults: submitResults
    };
})();
