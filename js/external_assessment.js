window.ExternalReadingAssessment = (function() {
    let config = {
        token: '',
        passage: '',
        questions: [],
        wwwroot: '',
        asr_service_url: 'http://localhost:8010'
    };

    let ws = null;
    let audioContext = null;
    let processorNode = null;
    let microphoneStream = null;
    let isRecording = false;
    let liveTranscript = "";
    let heldEvaluationData = null;
    let readingStartTime = null;
    let prevPartialWords = [];
    let currentPassageIndex = 0;
    let noMatchCount = 0;
    let totalMiscuesCount = 0;
    let readingEndTime = null;
    
    // Coach Mode State
    let coachTimer = null;
    let isCoachSpeaking = false;
    let isIsolatedMode = false;
    let coachWs = null;
    let coachModeTargetIndex = -1;

    function resetCoachTimer() {
        if (coachTimer) clearTimeout(coachTimer);
        if (currentPassageIndex < passageTokens.length) {
            // Trigger coach if stuck for 15 seconds on the same word
            coachTimer = setTimeout(() => window.triggerCoachMode(), 15000);
        }
    }

    let currentCoachWord = "";
    let cachedCoachAudio = null;

    window.playCurrentCoachWord = async function() {
        if (!currentCoachWord) return;
        
        const fallbackTTS = () => {
            if ('speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(currentCoachWord);
                utterance.lang = 'en-US';
                utterance.rate = 0.85;
                window.speechSynthesis.speak(utterance);
            }
        };

        try {
            const resp = await fetch(`${config.asr_service_url || "http://localhost:8010"}/tts_phoneme_guide`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ text: currentCoachWord, voice: "en-US-JennyNeural" })
            });
            const data = await resp.json();
            if (data && data.audio) {
                cachedCoachAudio = new Audio(data.audio);
                cachedCoachAudio.play().catch(e => fallbackTTS());
                return;
            } else {
                fallbackTTS();
            }
        } catch(e) {
            fallbackTTS();
        }
    };

    window.forceDismissCoachMode = function() {
        if (!isIsolatedMode) return;
        
        if (window._coachStopTimeout) {
            clearTimeout(window._coachStopTimeout);
            window._coachStopTimeout = null;
        }

        const card = document.getElementById("ra-isolation-card");
        if (card) card.style.display = "none";
        
        isIsolatedMode = false;
        
        if (coachWs) {
            try { coachWs.close(); } catch(e){}
            coachWs = null;
        }

        if (currentPassageIndex === coachModeTargetIndex) {
            currentPassageIndex++;
            if (ws && ws.readyState === WebSocket.OPEN) {
                ws.send(JSON.stringify({ type: "sync_index", index: currentPassageIndex }));
            }
        }
        
        noMatchCount = 0;
        resetCoachTimer();
    };

    window.triggerCoachMode = async function(forceIndex = -1) {
        let indexToCoach = forceIndex >= 0 ? forceIndex : currentPassageIndex;
        coachModeTargetIndex = indexToCoach;
        if (indexToCoach >= passageTokens.length || isIsolatedMode || !isRecording) return;
        
        totalMiscuesCount++; // Count as Phil-IRI error

        let targetWord = passageTokens[indexToCoach].replace(/[.,\/#!$%\^&\*;:{}=\-_`~()"'?!]/g,"");
        if (!targetWord) {
            if (forceIndex === -1) currentPassageIndex++;
            resetCoachTimer();
            return;
        }

        isIsolatedMode = true;
        isCoachSpeaking = true;
        currentCoachWord = targetWord;
        cachedCoachAudio = null;

        // Initialize Coach Azure WebSocket
        const wsUrl = getWsUrl(config.asr_service_url);
        coachWs = new WebSocket(wsUrl);
        coachWs.onopen = () => {
            coachWs.send(JSON.stringify({ type: "start", target: targetWord, attempt_id: 1 }));
        };
        coachWs.onmessage = (event) => {
            try {
                const msg = JSON.parse(event.data);
                
                if (msg.type === "partial" && msg.result) {
                    const trans = document.getElementById("ra-iso-transcript");
                    const heardText = msg.result.text || "";
                    if (trans) trans.textContent = "(Heard: " + heardText + ")";

                    // If the user keeps reading without pausing, Azure won't send a final chunk.
                    // We detect if they said the target word in the partial stream and force Azure to finalize.
                    if (currentCoachWord) {
                        let cleanHeard = heardText.toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()"'?!]/g,"");
                        let cleanTarget = currentCoachWord.toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()"'?!]/g,"");
                        if (cleanHeard.includes(cleanTarget) || cleanHeard.split(/\s+/).includes(cleanTarget)) {
                            if (coachWs && coachWs.readyState === WebSocket.OPEN && !window._coachStopTimeout) {
                                // Add a 700ms delay to ensure they finish pronouncing the final syllables 
                                // before we forcefully cut off the Azure audio buffer!
                                window._coachStopTimeout = setTimeout(() => {
                                    if (coachWs && coachWs.readyState === WebSocket.OPEN) {
                                        coachWs.send(JSON.stringify({ type: "stop" }));
                                    }
                                    window._coachStopTimeout = null;
                                }, 700);
                            }
                        }
                    }
                }

                if (msg.type === "final" && msg.result) {
                    if (window._coachStopTimeout) {
                        clearTimeout(window._coachStopTimeout);
                        window._coachStopTimeout = null;
                    }
                    const trans = document.getElementById("ra-iso-transcript");
                    if (trans) trans.textContent = "(Listening...)";

                    const wordResults = msg.result.words || msg.word_results || [];
                    
                    // Render in Coach Table
                    const coachWordRow = document.getElementById("ra-coach-wordrow");
                    const coachPhonemeRow = document.getElementById("ra-coach-phonemerow");
                    const coachScoreRow = document.getElementById("ra-coach-scorerow");
                    const pronunContainer = document.getElementById("ra-pronunciation-results");
                    if (pronunContainer) pronunContainer.style.display = "block";

                    if (coachWordRow && coachPhonemeRow && coachScoreRow && wordResults.length > 0) {
                        let now = new Date();
                        let timeStr = now.getHours() + ":" + String(now.getMinutes()).padStart(2, '0') + ":" + String(now.getSeconds()).padStart(2, '0');
                        
                        let tdwT = document.createElement('td');
                        tdwT.innerText = "[" + timeStr + "]";
                        tdwT.style.border = "1px solid lightgrey";
                        tdwT.style.padding = "5px";
                        tdwT.style.backgroundColor = "#f1f5f9";
                        tdwT.style.fontWeight = "bold";
                        coachWordRow.appendChild(tdwT);

                        let tdpT = document.createElement('td'); tdpT.style.border = "1px solid lightgrey"; coachPhonemeRow.appendChild(tdpT);
                        let tdsT = document.createElement('td'); tdsT.style.border = "1px solid lightgrey"; coachScoreRow.appendChild(tdsT);
                    }

                    let passed = false;
                    let targetOmitted = false;
                    wordResults.forEach(wr => {
                        if (wr.error_type === "Omission") targetOmitted = true;
                    });
                    
                    if (!targetOmitted) {
                        wordResults.forEach(wr => {
                            const err = wr.error_type || "None";
                            const acc = wr.accuracy_score || 0;
                            if (err === "None" && acc >= 60) {
                                passed = true;
                            }
                        });
                    }

                    wordResults.forEach(wr => {
                        const err = wr.error_type || "None";
                        const acc = wr.accuracy_score || 0;
                        const wordText = wr.word || "";
                        
                        if (coachWordRow && coachPhonemeRow && coachScoreRow && err !== "Insertion") {
                            const phonemes = wr.phoneme_results || [];
                            const countp = phonemes.length > 0 ? phonemes.length : 1;

                            let tdw = document.createElement('td');
                            tdw.innerText = wordText;
                            let x = document.createElement("SUP");
                            x.appendChild(document.createTextNode(Math.round(acc)));
                            tdw.appendChild(x);
                            tdw.colSpan = countp;
                            tdw.style.border = "1px solid lightgrey";
                            tdw.style.padding = "5px";
                            if (err === "None" && acc >= 80) tdw.style.backgroundColor = "lightgreen";
                            else tdw.style.backgroundColor = "#ff6b6b";  
                            coachWordRow.appendChild(tdw);

                            if (phonemes.length === 0) {
                                let tdp = document.createElement('td'); tdp.style.border = "1px solid lightgrey"; coachPhonemeRow.appendChild(tdp);
                                let tds = document.createElement('td'); tds.style.border = "1px solid lightgrey"; coachScoreRow.appendChild(tds);
                            }

                            phonemes.forEach(p => {
                                let tdp = document.createElement('td');
                                tdp.innerText = p.phoneme;
                                tdp.style.border = "1px solid lightgrey";
                                tdp.style.padding = "5px";
                                let pAcc = p.accuracy || 0;
                                if(pAcc >= 80) tdp.style.backgroundColor = "green";  
                                else if(pAcc >= 60) tdp.style.backgroundColor = "lightgreen";  
                                else if(pAcc >= 40) tdp.style.backgroundColor = "yellow";  
                                else tdp.style.backgroundColor = "red"; 
                                coachPhonemeRow.appendChild(tdp);

                                let tds = document.createElement('td');
                                tds.innerText = Math.round(pAcc);
                                tds.style.border = "1px solid lightgrey";
                                tds.style.padding = "5px";
                                if(pAcc >= 80) tds.style.backgroundColor = "green";  
                                else if(pAcc >= 60) tds.style.backgroundColor = "lightgreen";  
                                else if(pAcc >= 40) tds.style.backgroundColor = "yellow";  
                                else tds.style.backgroundColor = "red";
                                coachScoreRow.appendChild(tds);
                            });
                        }
                    });
                    
                    // Coach Mode Auto-Finalizer
                    if (passed) {
                        // Close Coach Mode and return to passage seamlessly
                        window.forceDismissCoachMode();
                    } else {
                        // The attempt failed. If we manually sent "stop", the backend session is dead.
                        // We must send "start" again to re-arm the Azure recognizer for the next attempt.
                        if (coachWs && coachWs.readyState === WebSocket.OPEN) {
                            coachWs.send(JSON.stringify({ type: "start", target: currentCoachWord, attempt_id: 2 }));
                        }
                    }
                }
            } catch(e){}
        };
        
        // Visual indicator
        const span = document.getElementById(`ra-ext-word-${currentPassageIndex}`);
        if (span) {
            span.style.backgroundColor = "#fef08a"; // yellow
            span.style.color = "#854d0e";
            span.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        // Fetch Phonetics
        let guide = { respelling: targetWord, ipa: `/${targetWord}/`, syllables: [targetWord] };
        try {
            const resp = await fetch(`${config.asr_service_url}/phoneme_analysis`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ word: targetWord })
            });
            if (resp.ok) {
                const data = await resp.json();
                if (data && data.word) guide = data;
            }
        } catch(e) {}

        // Populate Card
        const card = document.getElementById("ra-isolation-card");
        if (card) {
            document.getElementById("ra-iso-target-word").textContent = targetWord;
            document.getElementById("ra-iso-phonetic").innerHTML = `Phonetic: <strong>[ ${guide.respelling || targetWord} ]</strong>`;
            document.getElementById("ra-iso-ipa").innerHTML = `IPA: <strong>${guide.ipa || `/${targetWord}/`}</strong>`;
            
            let sylHtml = "";
            let syllables = guide.syllables || [targetWord];
            syllables.forEach((syl, idx) => {
                sylHtml += `<span style="font-size: 2rem; font-weight: 800; background: white; padding: 10px 20px; border-radius: 12px; border: 2px solid #e2e8f0; color: #334155; cursor: pointer;" onclick="playCurrentCoachWord()">${syl}</span>`;
                if (idx < syllables.length - 1) sylHtml += `<span style="font-size: 2rem; color: #94a3b8; align-self: center;">·</span>`;
            });
            document.getElementById("ra-iso-syllables").innerHTML = sylHtml;
            card.style.display = "block";
        }

        // TTS auto-play once
        playCurrentCoachWord();
        isCoachSpeaking = false; 
        if (coachTimer) clearTimeout(coachTimer); // pause timer until dismissed
    };

    function getWsUrl(asrServiceUrl) {
        let url = asrServiceUrl || "http://localhost:8010";
        if (url.startsWith("http://")) {
            url = "ws://" + url.substring(7);
        } else if (url.startsWith("https://")) {
            url = "wss://" + url.substring(8);
        } else {
            url = "ws://" + url;
        }
        return url.replace(/\/+$/, "") + "/ws/stream";
    }

    async function stopRecording() {
        if (coachTimer) clearTimeout(coachTimer);
        isRecording = false;
        if (processorNode) {
            processorNode.disconnect();
            processorNode = null;
        }
        if (microphoneStream) {
            microphoneStream.getTracks().forEach(t => t.stop());
            microphoneStream = null;
        }
        if (audioContext) {
            try { audioContext.close(); } catch(e) {}
            audioContext = null;
        }
        if (ws) {
            try {
                if (ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: "stop" }));
                }
            } catch(e) {}
        }
    }

    let passageTokens = [];
    let currentLevel = 0;

    function updateLevelUI() {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox) return;
        
        let rawText = (config.passages && config.passages[currentLevel]) ? config.passages[currentLevel] : "";
        if (!rawText.trim()) {
            passageBox.innerHTML = "<div class='alert alert-warning'>No passage configured for this level.</div>";
            return;
        }

        const rawWords = rawText.split(/\s+/);
        passageTokens = [];
        let html = "";
        rawWords.forEach((word, idx) => {
            if (word.trim().length > 0) {
                passageTokens.push(word);
                html += `<span id="ra-ext-word-${idx}" style="transition: color 0.2s; display: inline-block; padding: 0 2px;">${word}</span> `;
            }
        });
        passageBox.innerHTML = html;
        
        // Hide/Show questions for this level
        config.questions.forEach((q, idx) => {
            let qDiv = document.getElementById(`ra-q-container-${idx}`);
            if (qDiv) {
                let qLevel = parseInt(q.level_idx || 0);
                if (qLevel === currentLevel) {
                    qDiv.style.display = 'block';
                } else {
                    qDiv.style.display = 'none';
                }
            }
        });
    }

    function resetAssessmentState() {
        isRecording = false;
        liveTranscript = "";
        heldEvaluationData = null;
        readingStartTime = null;
        readingEndTime = null;
        currentPassageIndex = 0;
        noMatchCount = 0;
        totalMiscuesCount = 0;
        
        const vadIndicator = document.getElementById("ra-vad-indicator");
        if (vadIndicator) vadIndicator.classList.remove("ra-vad-active");
        
        const pronunContainer = document.getElementById("ra-pronunciation-results");
        if (pronunContainer) pronunContainer.style.display = "none";
        
        const t = document.getElementById("ra-live-transcript");
        if (t) t.innerText = "";
        
        const startBtn = document.getElementById("ra-btn-start");
        if (startBtn) {
            startBtn.disabled = false;
            startBtn.classList.remove("btn-danger", "btn-warning");
            startBtn.classList.add("btn-success");
            
            if (currentLevel === 3) {
                startBtn.innerHTML = "🔊 Listen to Passage";
                
                // Hide mic components
                const micSection = document.getElementById("ra-live-transcript")?.parentElement;
                if (micSection) micSection.style.display = "none";
            } else {
                startBtn.innerHTML = "🎙️ Start Reading";
                const micSection = document.getElementById("ra-live-transcript")?.parentElement;
                if (micSection) micSection.style.display = "block";
            }
        }
        
        const submitBtn = document.getElementById("ra-btn-submit");
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = "Submit Results";
        }
        
        updateLevelUI();
    }

    function init(cfg) {
        Object.assign(config, cfg);
        
        if (config.starting_level !== undefined) {
            currentLevel = config.starting_level;
        }

        const startBtn = document.getElementById("ra-btn-start");
        const submitBtn = document.getElementById("ra-btn-submit");
        const statusText = document.getElementById("ra-status-text");
        const vadIndicator = document.getElementById("ra-vad-indicator");
        const transcriptDisplay = document.getElementById("ra-live-transcript");
        
        updateLevelUI();

        if (!startBtn) return;

        startBtn.addEventListener("click", async () => {
            if (currentLevel === 3) {
                // Listening Comprehension Mode
                startBtn.disabled = true;
                startBtn.innerHTML = "🔊 Reading...";
                statusText.textContent = "Please listen to the passage carefully...";
                
                const rawText = (config.passages && config.passages[currentLevel]) ? config.passages[currentLevel] : "";
                const utterance = new SpeechSynthesisUtterance(rawText);
                utterance.rate = 0.9; // Slightly slower for comprehension
                
                utterance.onend = () => {
                    startBtn.innerHTML = "✅ Finished Reading";
                    statusText.textContent = "You can now answer the questions below.";
                    if (submitBtn) submitBtn.disabled = false;
                };
                
                window.speechSynthesis.cancel(); // Stop any previous speech
                window.speechSynthesis.speak(utterance);
                return;
            }

            if (!isRecording) {
                try {
                    statusText.textContent = "Requesting microphone access...";
                    microphoneStream = await navigator.mediaDevices.getUserMedia({
                        audio: {
                            channelCount: 1,
                            sampleRate: 16000,
                            echoCancellation: true,
                            noiseSuppression: true
                        }
                    });

                    audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
                    if (audioContext.state === 'suspended') {
                        await audioContext.resume();
                    }
                    
                    // 3-2-1 Countdown
                    startBtn.disabled = true;
                    startBtn.classList.remove("btn-success");
                    startBtn.classList.add("btn-warning");
                    for (let i = 3; i > 0; i--) {
                        startBtn.innerHTML = `Starting in ${i}...`;
                        statusText.innerHTML = `<span style="font-size: 1.2rem; font-weight: bold; color: #d97706;">Get ready... ${i}</span>`;
                        await new Promise(r => setTimeout(r, 1000));
                    }
                    startBtn.disabled = false;
                    startBtn.classList.remove("btn-warning");
                    
                    const source = audioContext.createMediaStreamSource(microphoneStream);
                    processorNode = audioContext.createScriptProcessor(2048, 1, 1);

                    const wsUrl = getWsUrl(config.asr_service_url);
                    ws = new WebSocket(wsUrl);

                    ws.onopen = () => {
                        isRecording = true;
                        startBtn.innerHTML = "⏹ Stop & Finish";
                        startBtn.classList.remove("btn-success");
                        startBtn.classList.add("btn-danger");
                        statusText.textContent = "Recording active. Please read the passage aloud.";
                        if (vadIndicator) vadIndicator.classList.add("ra-vad-active");
                        liveTranscript = "";
                        heldEvaluationData = null;
                        readingStartTime = Date.now();
                        prevPartialWords = [];
                        currentPassageIndex = 0;
                        noMatchCount = 0;
                        resetCoachTimer();

                        ws.send(JSON.stringify({
                            type: "start",
                            target: config.passage
                        }));

                        processorNode.onaudioprocess = (e) => {
                            if (!isRecording) return;
                            
                            const inputData = e.inputBuffer.getChannelData(0);
                            const pcm16 = new Int16Array(inputData.length);
                            for (let i = 0; i < inputData.length; i++) {
                                let s = Math.max(-1, Math.min(1, inputData[i]));
                                pcm16[i] = s < 0 ? s * 0x8000 : s * 0x7FFF;
                            }
                            
                            if (isIsolatedMode) {
                                if (coachWs && coachWs.readyState === WebSocket.OPEN) {
                                    coachWs.send(pcm16.buffer);
                                }
                            } else {
                                if (ws && ws.readyState === WebSocket.OPEN) {
                                    ws.send(pcm16.buffer);
                                }
                            }
                        };

                        source.connect(processorNode);
                        processorNode.connect(audioContext.destination);

                        ws.onmessage = (event) => {
                            try {
                                const msg = JSON.parse(event.data);
                                if (msg.type === "partial" || msg.type === "final") {
                                    const res = msg.result || {};
                                    const raw = res.detected ? res.detected.raw : (msg.text || "");
                                    const wordResults = res.words || msg.word_results || [];

                                    if (msg.type === "final") {
                                        liveTranscript += " " + raw;
                                        prevPartialWords = []; // reset for next chunk
                                        if (res.scores) {
                                            heldEvaluationData = { miscues: [], accuracy_score: res.scores.accuracy_score, comprehension_score: 0 };
                                        }
                                    } else {
                                        // Handle partial streaming highlighting
                                        const curWords = raw.split(/\s+/).filter(w => w.length > 0);
                                        for (let i = prevPartialWords.length; i < curWords.length; i++) {
                                            let spokenWord = curWords[i].toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()"'?!]/g,"");
                                            
                                            // Try to match with current passage token
                                            if (currentPassageIndex < passageTokens.length) {
                                                let expectedWord = passageTokens[currentPassageIndex].toLowerCase().replace(/[.,\/#!$%\^&\*;:{}=\-_`~()"'?!]/g,"");
                                                if (spokenWord === expectedWord) {
                                                    // Match!
                                                    const span = document.getElementById(`ra-ext-word-${currentPassageIndex}`);
                                                    if (span) {
                                                        span.style.backgroundColor = "#e0f2fe"; // Light blue highlight
                                                        span.style.color = "#0369a1";
                                                    }
                                                    currentPassageIndex++;
                                                    resetCoachTimer(); // Coach Mode: reset timer on match
                                                }
                                            }
                                        }
                                        prevPartialWords = curWords;
                                    }
                                    
                                    if (transcriptDisplay) transcriptDisplay.textContent = liveTranscript + (msg.type === "partial" ? " " + raw : "");

                                    // Update word highlights based on Azure Pronunciation Assessment results
                                    // Update word highlights and Results Table
                                    if (wordResults.length > 0) {
                                        const pronunContainer = document.getElementById("ra-pronunciation-results");
                                        const wordRow = document.getElementById("ra-wordrow");
                                        const phonemeRow = document.getElementById("ra-phonemerow");
                                        const scoreRow = document.getElementById("ra-scorerow");

                                        if (pronunContainer) {
                                            pronunContainer.style.display = "block";
                                            if (msg.type === "final") {
                                                // Interim Phil-IRI Calculation
                                                const wordsInPassage = passageTokens.length || 1;
                                                const wordReadingScore = Math.max(0, ((wordsInPassage - totalMiscuesCount) / wordsInPassage) * 100);
                                                document.getElementById("ra-summ-word-reading").innerText = Math.round(wordReadingScore) + "%";
                                                
                                                if (readingStartTime) {
                                                    const rTimeSec = (Date.now() - readingStartTime) / 1000;
                                                    const wpm = (wordsInPassage / rTimeSec) * 60;
                                                    document.getElementById("ra-summ-wpm").innerText = Math.round(wpm);
                                                }
                                            }
                                        }

                                        if (wordRow && phonemeRow && scoreRow) {
                                            // DO NOT clear innerHTML = "" so table stays and grows
                                            // Add a timestamp column for this chunk
                                            let now = new Date();
                                            let timeStr = now.getHours() + ":" + String(now.getMinutes()).padStart(2, '0') + ":" + String(now.getSeconds()).padStart(2, '0');
                                            
                                            let tdwT = document.createElement('td');
                                            tdwT.innerText = "[" + timeStr + "]";
                                            tdwT.style.border = "1px solid lightgrey";
                                            tdwT.style.padding = "5px";
                                            tdwT.style.backgroundColor = "#f1f5f9";
                                            tdwT.style.fontWeight = "bold";
                                            wordRow.appendChild(tdwT);

                                            let tdpT = document.createElement('td'); tdpT.style.border = "1px solid lightgrey"; phonemeRow.appendChild(tdpT);
                                            let tdsT = document.createElement('td'); tdsT.style.border = "1px solid lightgrey"; scoreRow.appendChild(tdsT);
                                        }

                                        let foundMispronunciationIdx = -1;

                                        wordResults.forEach((wr, idx) => {
                                            let err = wr.error_type || "None";
                                            const acc = wr.accuracy_score || 0;
                                            
                                            // Force low-scoring words to be treated as Mispronunciation even if Azure didn't flag them
                                            if (err === "None" && acc < 60) {
                                                err = "Mispronunciation";
                                            }

                                            const wordText = wr.word || "";
                                            const passageIdx = wr.passage_idx !== undefined && wr.passage_idx !== null ? wr.passage_idx : idx;
                                            
                                            if (err === "None") {
                                                if (passageIdx === currentPassageIndex) {
                                                    const s = document.getElementById(`ra-ext-word-${passageIdx}`);
                                                    if (s) {
                                                        s.style.backgroundColor = "#e0f2fe"; 
                                                        s.style.color = "#0369a1";
                                                    }
                                                    currentPassageIndex = passageIdx + 1;
                                                    resetCoachTimer();
                                                    if (ws && ws.readyState === WebSocket.OPEN) {
                                                        ws.send(JSON.stringify({ type: "sync_index", index: currentPassageIndex }));
                                                    }
                                                }
                                                // If they successfully read the word in the same chunk sequentially, clear any stutter flag for it!
                                                if (foundMispronunciationIdx === passageIdx && passageIdx === currentPassageIndex - 1) {
                                                    foundMispronunciationIdx = -1;
                                                }
                                            }

                                            // Trigger Coach Mode on the first miscue found in this chunk
                                            if ((err === "Mispronunciation" || err === "Omission" || err === "Insertion") && foundMispronunciationIdx === -1) {
                                                foundMispronunciationIdx = passageIdx;
                                            }

                                            // Highlight Passage Words
                                            if (passageIdx < passageTokens.length) {
                                                const span = document.getElementById(`ra-ext-word-${passageIdx}`);
                                                if (span) {
                                                    if (err === "None" || err === "Insertion") {
                                                        span.style.color = "#059669"; // Green
                                                        span.style.borderBottom = "2px solid #059669";
                                                    } else if (err === "Mispronunciation") {
                                                        span.style.color = "#d97706"; // Orange
                                                        span.style.borderBottom = "2px solid #d97706";
                                                    } else if (err === "Omission") {
                                                        span.style.color = "#dc2626"; // Red
                                                        span.style.borderBottom = "2px dashed #dc2626";
                                                    }
                                                }
                                            }

                                            // Populate Detailed Table (only if we have the rows)
                                            if (wordRow && phonemeRow && scoreRow) {
                                                if (err === "Omission") {
                                                    let tdw = document.createElement('td');
                                                    tdw.innerText = wordText;
                                                    tdw.style.backgroundColor = "orange"; 
                                                    tdw.style.border = "1px solid lightgrey";
                                                    tdw.style.padding = "5px";
                                                    wordRow.appendChild(tdw);

                                                    let tdp = document.createElement('td');
                                                    tdp.innerText = "-";
                                                    tdp.style.backgroundColor = "orange";
                                                    tdp.style.border = "1px solid lightgrey";
                                                    tdp.style.padding = "5px";
                                                    phonemeRow.appendChild(tdp);

                                                    let tds = document.createElement('td');
                                                    tds.innerText = "-";
                                                    tds.style.backgroundColor = "orange";
                                                    tds.style.border = "1px solid lightgrey";
                                                    tds.style.padding = "5px";
                                                    scoreRow.appendChild(tds);
                                                } else if (err === "None" || err === "Mispronunciation") {
                                                    const phonemes = wr.phoneme_results || [];
                                                    const countp = phonemes.length > 0 ? phonemes.length : 1;

                                                    let tdw = document.createElement('td');
                                                    tdw.innerText = wordText;
                                                    let x = document.createElement("SUP");
                                                    x.appendChild(document.createTextNode(Math.round(acc)));
                                                    tdw.appendChild(x);
                                                    tdw.colSpan = countp;
                                                    tdw.style.border = "1px solid lightgrey";
                                                    tdw.style.padding = "5px";
                                                    if (err === "None" && acc >= 80) {
                                                        tdw.style.backgroundColor = "lightgreen";
                                                    } else {
                                                        tdw.style.backgroundColor = "#ff6b6b";  
                                                    }
                                                    wordRow.appendChild(tdw);

                                                    if (phonemes.length === 0) {
                                                        let tdp = document.createElement('td'); tdp.style.border = "1px solid lightgrey"; phonemeRow.appendChild(tdp);
                                                        let tds = document.createElement('td'); tds.style.border = "1px solid lightgrey"; scoreRow.appendChild(tds);
                                                    }

                                                    phonemes.forEach(p => {
                                                        let tdp = document.createElement('td');
                                                        tdp.innerText = p.phoneme;
                                                        tdp.style.border = "1px solid lightgrey";
                                                        tdp.style.padding = "5px";
                                                        let pAcc = p.accuracy || 0;
                                                        if(pAcc >= 80) tdp.style.backgroundColor = "green";  
                                                        else if(pAcc >= 60) tdp.style.backgroundColor = "lightgreen";  
                                                        else if(pAcc >= 40) tdp.style.backgroundColor = "yellow";  
                                                        else tdp.style.backgroundColor = "red"; 
                                                        phonemeRow.appendChild(tdp);

                                                        let tds = document.createElement('td');
                                                        tds.innerText = Math.round(pAcc);
                                                        tds.style.border = "1px solid lightgrey";
                                                        tds.style.padding = "5px";
                                                        if(pAcc >= 80) tds.style.backgroundColor = "green";  
                                                        else if(pAcc >= 60) tds.style.backgroundColor = "lightgreen";  
                                                        else if(pAcc >= 40) tds.style.backgroundColor = "yellow";  
                                                        else tds.style.backgroundColor = "red";
                                                        scoreRow.appendChild(tds);
                                                    });
                                                }
                                            }
                                        });

                                        if (foundMispronunciationIdx !== -1) {
                                            window.triggerCoachMode(foundMispronunciationIdx);
                                        }
                                    }
                                } else if (msg.type === "attempt_ready" || msg.type === "ready") {
                                    console.log("Session ready:", msg.message);
                                }
                            } catch(e) {}
                        };

                        ws.onerror = (e) => {
                            console.error("WebSocket error:", e);
                            statusText.textContent = "Error: Connection to speech service failed.";
                        };
                    };

                } catch (err) {
                    statusText.textContent = "Microphone access denied or failed.";
                    console.error(err);
                }
            } else {
                await stopRecording();
                startBtn.disabled = true;
                startBtn.innerHTML = "Processing...";
                statusText.textContent = "Processing speech... Please answer the questions below.";
                if (vadIndicator) vadIndicator.classList.remove("ra-vad-active");
                if (submitBtn) submitBtn.disabled = false;
            }
        });

        if (submitBtn) {
            submitBtn.addEventListener("click", async () => {
                submitBtn.disabled = true;
                submitBtn.innerHTML = "Submitting...";

                let studentAnswers = {};
                config.questions.forEach((q, idx) => {
                    const qtype = q.type || 'multichoice';
                    if (qtype === 'multichoice' || qtype === 'truefalse') {
                        const checked = document.querySelector(`input[name="ra_q_${idx}"]:checked`);
                        studentAnswers[idx] = checked ? checked.value : '';
                    } else if (qtype === 'matching') {
                        const pairs = q.pairs || [];
                        let pairAns = {};
                        pairs.forEach((p, pidx) => {
                            const sel = document.querySelector(`select[name="ra_q_${idx}_p_${pidx}"]`);
                            pairAns[pidx] = sel ? sel.value : '';
                        });
                        studentAnswers[idx] = pairAns;
                    } else {
                        const inp = document.querySelector(`input[name="ra_q_${idx}"]`) || document.querySelector(`textarea[name="ra_q_${idx}"]`);
                        studentAnswers[idx] = inp ? inp.value : '';
                    }
                });

                try {
                    const evalResp = await fetch(`${config.asr_service_url}/evaluate`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            expected_text: config.passage,
                            spoken_text: liveTranscript,
                            azure_miscues: heldEvaluationData ? heldEvaluationData.miscues : []
                        })
                    });
                    const evalData = await evalResp.json();
                    
                    const miscuesList = (heldEvaluationData && heldEvaluationData.miscues) ? heldEvaluationData.miscues : (evalData.miscues || []);
                    const rTime = readingStartTime ? Math.round((Date.now() - readingStartTime)/1000) : 0;
                    const rSpeed = evalData.reading_speed || 0.0;
                    const accuracy = evalData.accuracy_score || 0.0;

                    const submitUrl = `${config.wwwroot}/mod/readingassessment/external_submit.php`;
                    const params = new URLSearchParams({
                        token: config.token,
                        transcript: liveTranscript,
                        accuracy_score: accuracy,
                        reading_time: rTime,
                        reading_speed: rSpeed,
                        miscues_json: JSON.stringify(miscuesList),
                        total_miscues: totalMiscuesCount,
                        answers_json: JSON.stringify(studentAnswers),
                        level_idx: currentLevel
                    });

                    const response = await fetch(submitUrl, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: params
                    });

                    const resData = await response.json();
                    
                    if (response.ok) {
                        if (resData.classification === "Frustration" || resData.classification === "Non-Reader") {
                            if (currentLevel < 3 && config.passages && config.passages[currentLevel + 1] && config.passages[currentLevel + 1].trim().length > 0) {
                                // Step down to next passage
                                alert("Let's try another passage! Please read the next text.");
                                currentLevel++;
                                resetAssessmentState();
                                return;
                            }
                        }

                        // No more levels or they passed, show modal
                        const modal = document.getElementById("external-thank-you");
                        if (modal) {
                            modal.style.display = "flex";
                            document.getElementById("final-classification").innerText = resData.classification || "PENDING";
                            document.getElementById("final-word-score").innerText = (resData.word_reading_score !== undefined ? resData.word_reading_score : "-") + "%";
                            document.getElementById("final-comp-score").innerText = (resData.comprehension_score !== undefined ? resData.comprehension_score : "-") + "%";
                            document.getElementById("final-wpm-score").innerText = resData.reading_rate !== undefined ? resData.reading_rate : "-";
                            
                            const aralStatusDiv = document.getElementById("aral-status-message");
                            if (aralStatusDiv) {
                                if (resData.classification === "Independent") {
                                    aralStatusDiv.innerHTML = "<span style='color: #16a34a; font-weight: bold;'>Passed Phil-IRI</span><br><span style='font-size: 0.9rem; color: #64748b;'>Not subject for ARAL Program</span>";
                                } else {
                                    aralStatusDiv.innerHTML = "<span style='color: #dc2626; font-weight: bold;'>Subject for ARAL Program</span><br><span style='font-size: 0.9rem; color: #64748b;'>Intervention Recommended</span>";
                                }
                            }
                        }
                    } else {
                        alert("Error submitting: " + (resData.error || "Unknown error"));
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = "Submit Results";
                    }

                } catch (err) {
                    console.error("Evaluation submission error:", err);
                    alert("Error communicating with server.");
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = "Submit Results";
                }
            });
        }
    }

    return { init: init };
})();
