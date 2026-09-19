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
    let uiTimerInterval = null;
    let azureFinalWpmCount = 0;
    let azurePartialWpmCount = 0;
    let finalWordResultsList = [];
    
    let mainAudioRecorder = null;
    let mainAudioChunks = [];
    let finalAudioBlob = null;
    let readingEndTime = null;
    
    // Coach Mode State
    let coachTimer = null;
    let isCoachSpeaking = false;
    let isIsolatedMode = false;
    let coachWs = null;
    let coachModeTargetIndex = -1;

    function escapeHtml(unsafe) {
        return (unsafe || "").toString()
             .replace(/&/g, "&amp;")
             .replace(/</g, "&lt;")
             .replace(/>/g, "&gt;")
             .replace(/"/g, "&quot;")
             .replace(/'/g, "&#039;");
    }

    function isReversal(target, spoken) {
        if (!target || !spoken) return false;
        let t = target.toLowerCase().replace(/[^\w]/g, "");
        let s = spoken.toLowerCase().replace(/[^\w]/g, "");
        if (t === s || t.length < 2) return false;
        
        if (t === s.split('').reverse().join('')) return true;
        if (t.split('').sort().join('') === s.split('').sort().join('')) return true;
        
        return false;
    }

    let globalReadOrder = 0;

    function renderPhilIriLive() {
        const container = document.getElementById("ra-philiri-transcript");
        if (!container) return;

        let html = "";
        
        passageTokens.forEach((token, index) => {
            let state = philIriState[index] || { status: "None", spoken: "", insertionsBefore: [] };
            let err = state.status;
            let spoken = state.spoken;
            
            let remainingInsertions = state.insertionsBefore;
            // Deduce Substitution or Reversal: If Omission but there is an Insertion right before it
            if (err === "Omission" && state.insertionsBefore.length > 0) {
                let subSpoken = state.insertionsBefore.join(" ");
                if (isReversal(token, subSpoken)) {
                    err = "Reversal";
                } else {
                    err = "Substitution";
                }
                spoken = subSpoken;
                remainingInsertions = []; // Locally clear it so it doesn't double-render as an insertion
            } else if (err === "Mispronunciation") {
                if (isReversal(token, spoken)) {
                    err = "Reversal";
                }
            }

            let topAnnotation = "";
            let bottomAnnotation = "";
            let wordStyle = "color: inherit;";

            // Render remaining insertions first!
            if (remainingInsertions.length > 0) {
                let insText = remainingInsertions.join(" ");
                html += `<span style="display: inline-flex; flex-direction: column; align-items: center; vertical-align: bottom; margin: 0 2px; line-height: 1.2;">
                    <span style="font-size: 0.65rem; color: #059669; font-weight: bold; min-height: 1em;">${insText}</span>
                    <span style="color: #059669;">^</span>
                    <span style="font-size: 0.65rem; min-height: 1em;"></span>
                </span>`;
            }

            if (err === "None") {
                // Not read yet, or read correctly? If read correctly, we'll give it green if we want, but usually it's just normal text unless we explicitly mark it.
                // Let's leave correct words green to show they were processed.
                if (spoken !== "") wordStyle = "color: #059669;"; 
            } else if (err === "Mispronunciation") {
                wordStyle = "color: #d97706; text-decoration: underline; text-decoration-color: #d97706;";
                let t = token.toLowerCase().replace(/[^\w]/g, "");
                let s = spoken.toLowerCase().replace(/[^\w]/g, "");
                if (t !== s) {
                    topAnnotation = spoken;
                }
            } else if (err === "Reversal") {
                wordStyle = "color: #e11d48;"; // Rose Red
                topAnnotation = spoken;
            } else if (err === "Omission") {
                wordStyle = "color: #dc2626; border: 1px solid #dc2626; border-radius: 50%; padding: 0 4px;";
            } else if (err === "Substitution") {
                wordStyle = "color: #2563eb; text-decoration: line-through; text-decoration-color: #2563eb;";
                topAnnotation = spoken;
            } else if (err === "Repetition") {
                wordStyle = "text-decoration: underline; text-decoration-style: wavy; text-decoration-color: #eab308;";
            } else if (err === "Transposition") {
                wordStyle = "color: #9333ea; border-bottom: 2px dashed #9333ea;"; // Purple dashed line
                topAnnotation = "⇌";
            }

            html += `<span style="display: inline-flex; flex-direction: column; align-items: center; vertical-align: bottom; margin: 0 2px; line-height: 1.2;">
                <span style="font-size: 0.65rem; color: #2563eb; font-weight: bold; min-height: 1em;">${topAnnotation}</span>
                <span style="${wordStyle}">${token}</span>
                <span style="font-size: 0.65rem; color: #d97706; font-weight: bold; min-height: 1em;">${bottomAnnotation}</span>
            </span>`;
        });

        container.innerHTML = html;
    }

    function resetCoachTimer() {
        // Coach mode is completely disabled in external assessment.
        if (coachTimer) clearTimeout(coachTimer);
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
        if (mainAudioRecorder && mainAudioRecorder.state === 'paused') {
            mainAudioRecorder.resume();
        }
        
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
        if (mainAudioRecorder && mainAudioRecorder.state === 'recording') {
            mainAudioRecorder.pause();
        }
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

        if (mainAudioRecorder && mainAudioRecorder.state !== 'inactive') {
            mainAudioRecorder.stop();
        }

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
    let philIriState = [];
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
        philIriState = [];
        globalReadOrder = 0;
        let html = "";
        rawWords.forEach((word, idx) => {
            if (word.trim().length > 0) {
                passageTokens.push(word);
                philIriState.push({ status: "None", spoken: "", insertionsBefore: [] });
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
        globalReadOrder = 0;
        
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
                        if (uiTimerInterval) clearInterval(uiTimerInterval);
                        uiTimerInterval = setInterval(() => {
                            if (!isRecording) return;
                            const rTimeSec = Math.floor((Date.now() - readingStartTime) / 1000);
                            const elTime = document.getElementById("sb-time");
                            if (elTime) elTime.innerText = rTimeSec + "s";
                            
                            const wordsInPassage = passageTokens.length || 1;
                            
                            // Static Passage Word Count
                            const elWc = document.getElementById("sb-word-count");
                            if (elWc) elWc.innerText = wordsInPassage;
                            
                            if (rTimeSec > 0) {
                                const wpm = ((azureFinalWpmCount + azurePartialWpmCount) / rTimeSec) * 60;
                                const elSpeed = document.getElementById("sb-speed-calc");
                                if (elSpeed) elSpeed.innerText = Math.round(wpm) + " WPM";
                            }
                            
                            // Accuracy calculation (against total passage length)
                            const acc = Math.max(0, ((wordsInPassage - totalMiscuesCount) / wordsInPassage) * 100);
                            const elAcc = document.getElementById("sb-acc-calc");
                            if (elAcc) elAcc.innerText = Math.round(acc) + "%";
                            
                        }, 1000);
                        prevPartialWords = [];
                        currentPassageIndex = 0;
                        noMatchCount = 0;
                        azureFinalWpmCount = 0;
                        azurePartialWpmCount = 0;
                        resetCoachTimer();

                        ws.send(JSON.stringify({
                            type: "start",
                            target: (config.passages && config.passages[currentLevel]) ? config.passages[currentLevel] : ""
                        }));

                        mainAudioChunks = [];
                        finalAudioBlob = null;
                        try {
                            mainAudioRecorder = new MediaRecorder(microphoneStream);
                            mainAudioRecorder.ondataavailable = function(evt) {
                                if (evt.data && evt.data.size > 0) mainAudioChunks.push(evt.data);
                            };
                            mainAudioRecorder.onstop = function() {
                                finalAudioBlob = new Blob(mainAudioChunks, { type: 'audio/webm' });
                            };
                            mainAudioRecorder.start();
                        } catch(err) {
                            console.warn("MediaRecorder not supported or failed to start", err);
                        }

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
                                    const wordResults = msg.words || res.words || msg.word_results || [];
                                    
                                    const validPassageWords = new Set(passageTokens.map(w => w.toLowerCase().replace(/[^\w\s]/g,"")).filter(w => w));

                                    if (msg.type === "partial") {
                                        const rawPartial = msg.result ? (msg.result.text || "") : (msg.text || "");
                                        const words = rawPartial.trim().toLowerCase().replace(/[^\w\s]/g,"").split(/\s+/).filter(w => w);
                                        
                                        azurePartialWpmCount = 0;
                                        for (let word of words) {
                                            if (validPassageWords.has(word)) azurePartialWpmCount++;
                                        }
                                    }

                                    if (msg.type === "final") {
                                        // 1. Process WPM Speedometer (Isolated from wordResults chunk)
                                        const rawFinal = res.detected ? res.detected.raw : (msg.text || "");
                                        const finalWords = rawFinal.trim().toLowerCase().replace(/[^\w\s]/g,"").split(/\s+/).filter(w => w);
                                        for (let word of finalWords) {
                                            if (validPassageWords.has(word)) azureFinalWpmCount++;
                                        }
                                        azurePartialWpmCount = 0;
                                        
                                        liveTranscript += " " + raw;
                                        if (res.scores) {
                                            heldEvaluationData = { miscues: [], accuracy_score: res.scores.accuracy_score, comprehension_score: 0 };
                                        }
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

                                        finalWordResultsList = finalWordResultsList.concat(wordResults);
                                        
                                        let foundMispronunciationIdx = -1;

                                        wordResults.forEach((wr, idx) => {
                                            let err = wr.error_type || "None";
                                            const acc = wr.accuracy_score || 0;
                                            const wordText = wr.word || "";
                                            
                                            // Force low-scoring words to be treated as Mispronunciation even if Azure didn't flag them
                                            if (err === "None" && acc < 60) {
                                                err = "Mispronunciation";
                                            }
                                            


                                            let cleanWord = wordText.toLowerCase().replace(/[^\w]/g, "");
                                            let passageIdx = -1;
                                            
                                            // --- Smart Lookahead Algorithm ---
                                            let verifyCount = Math.min(2, wordResults.length - 1 - idx); // Check up to 2 words ahead
                                            
                                            // Only run sliding window if it's NOT an explicit insertion
                                            if (err !== "Insertion") {
                                            
                                            // 1. Search forwards (up to 100 words)
                                            for(let offset=0; offset<100; offset++){
                                                let checkIdx = currentPassageIndex + offset;
                                                if(checkIdx < passageTokens.length){
                                                    let cleanPassage = passageTokens[checkIdx].toLowerCase().replace(/[^\w]/g, "");
                                                    if(cleanPassage === cleanWord){
                                                        // If it's a massive jump, we MUST verify surrounding context
                                                        if (offset > 5) {
                                                            let isSolidMatch = true;
                                                            for (let v = 1; v <= verifyCount; v++) {
                                                                let nextSpoken = wordResults[idx + v].word.toLowerCase().replace(/[^\w]/g, "");
                                                                let nextPassage = passageTokens[checkIdx + v] ? passageTokens[checkIdx + v].toLowerCase().replace(/[^\w]/g, "") : "";
                                                                if (nextSpoken !== nextPassage) {
                                                                    isSolidMatch = false; 
                                                                    break;
                                                                }
                                                            }
                                                            // Reject massive jumps on single common words if there's no context to verify it
                                                            if (verifyCount === 0 || !isSolidMatch) continue; 
                                                        }
                                                        passageIdx = checkIdx;
                                                        break;
                                                    }
                                                }
                                            }
                                            
                                            // 2. Search backwards (up to 30 words) for repetitions
                                            if (passageIdx === -1) {
                                                for(let offset=1; offset<=30; offset++){
                                                    let checkIdx = currentPassageIndex - offset;
                                                    if(checkIdx >= 0){
                                                        let cleanPassage = passageTokens[checkIdx].toLowerCase().replace(/[^\w]/g, "");
                                                        if(cleanPassage === cleanWord){
                                                            // For backward jumps (repetitions), also require context if it's far
                                                            if (offset > 5) {
                                                                let isSolidMatch = true;
                                                                for (let v = 1; v <= verifyCount; v++) {
                                                                    let nextSpoken = wordResults[idx + v].word.toLowerCase().replace(/[^\w]/g, "");
                                                                    let nextPassage = passageTokens[checkIdx + v] ? passageTokens[checkIdx + v].toLowerCase().replace(/[^\w]/g, "") : "";
                                                                    if (nextSpoken !== nextPassage) {
                                                                        isSolidMatch = false; 
                                                                        break;
                                                                    }
                                                                }
                                                                if (verifyCount === 0 || !isSolidMatch) continue; 
                                                            }
                                                            passageIdx = checkIdx;
                                                            break;
                                                        }
                                                    }
                                                }
                                            }
                                            
                                            } // End if (err !== "Insertion")
                                            
                                            let oldPassageIndex = currentPassageIndex;
                                            if(passageIdx === -1){
                                                passageIdx = currentPassageIndex; 
                                            } else {
                                                // Advance currentPassageIndex to the matched word's index + 1
                                                currentPassageIndex = passageIdx + 1;
                                            }
                                            
                                            // --- Phil-IRI Sequence Tracker ---
                                            if (err === "Insertion") {
                                                let targetIdx = passageIdx < passageTokens.length ? passageIdx : passageTokens.length - 1;
                                                if (targetIdx >= 0) {
                                                    philIriState[targetIdx].insertionsBefore.push(wordText);
                                                }
                                            } else if (passageIdx < passageTokens.length) {
                                                    if (!philIriState[passageIdx].readOrder) {
                                                        globalReadOrder++;
                                                        philIriState[passageIdx].readOrder = globalReadOrder;
                                                    }

                                                    // Record skipped words as Omissions
                                                    if (oldPassageIndex < passageIdx) {
                                                        for (let i = oldPassageIndex; i < passageIdx; i++) {
                                                            if (i < passageTokens.length) {
                                                                philIriState[i].status = "Omission";
                                                            }
                                                        }
                                                    }
                                                    // Handle Repetitions (backward jumps)
                                                    if (passageIdx < oldPassageIndex) {
                                                        philIriState[passageIdx].status = "Repetition";
                                                    } else {
                                                        philIriState[passageIdx].status = err;
                                                        philIriState[passageIdx].spoken = wordText;
                                                    }
                                            }
                                            
                                            if (err === "None") {
                                                const s = document.getElementById(`ra-ext-word-${passageIdx}`);
                                                if (s) {
                                                    s.style.backgroundColor = "#e0f2fe"; 
                                                    s.style.color = "#0369a1";
                                                }
                                                resetCoachTimer();
                                                if (ws && ws.readyState === WebSocket.OPEN) {
                                                    ws.send(JSON.stringify({ type: "sync_index", index: currentPassageIndex }));
                                                }
                                                
                                                // Clear stutter flag if they eventually got it right
                                                if (foundMispronunciationIdx === passageIdx) {
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

                                        // Pass 1: Detect Transpositions
                                        for (let i = 0; i < passageTokens.length - 1; i++) {
                                            let s1 = philIriState[i];
                                            let s2 = philIriState[i+1];
                                            if (s1 && s2 && s1.readOrder && s2.readOrder && s1.readOrder === s2.readOrder + 1) {
                                                // s1 (first word) must be the backward jump, and s2 must not have been repeated later
                                                if (s1.status === "Repetition" && s2.status !== "Repetition") {
                                                    s1.status = "Transposition";
                                                    s2.status = "Transposition";
                                                }
                                            }
                                        }

                                        // Recalculate Phil-IRI Miscues Native Count from the Sequence Tracker
                                        let computedMiscues = 0;
                                        let skipNextTransposition = false;
                                        philIriState.forEach((st, idx) => {
                                            // Handle Transposition pairs (only count 1 error for the swapped pair)
                                            if (st.status === "Transposition") {
                                                if (skipNextTransposition) {
                                                    skipNextTransposition = false;
                                                } else {
                                                    computedMiscues++;
                                                    skipNextTransposition = true;
                                                }
                                                computedMiscues += st.insertionsBefore.length;
                                                return;
                                            }
                                            
                                            // Normal deduction
                                            if (st.status === "Omission" && st.insertionsBefore.length > 0) {
                                                computedMiscues++; // Substitution or Reversal counts as 1 miscue
                                                computedMiscues += (st.insertionsBefore.length - 1); // Extra insertions
                                            } else {
                                                if (st.status !== "None") computedMiscues++;
                                                computedMiscues += st.insertionsBefore.length;
                                            }
                                        });
                                        totalMiscuesCount = computedMiscues;
                                        const elMiscue = document.getElementById("sb-miscue-count");
                                        if (elMiscue) elMiscue.innerText = totalMiscuesCount;

                                        // Now that state is fully updated and computed, render the UI!
                                        renderPhilIriLive();

                                        // Coach Mode TTS is intentionally disabled for this assessment
                                    }
                                } else if (msg.type === "assessment_report") {
                                    // Save the final perfectly calculated Azure sample scores and words
                                    if (heldEvaluationData) {
                                        heldEvaluationData.accuracy_score = msg.scores.accuracy_score;
                                        heldEvaluationData.scores = msg.scores; // fluency, prosody, completeness
                                        
                                        // Collect the exact final Omissions, Insertions, etc for the backend!
                                        const finalWords = msg.word_results || [];
                                        heldEvaluationData.word_results = finalWords;
                                        
                                        let miscuesArr = [];
                                        finalWords.forEach((wr, idx) => {
                                            const et = wr.error_type || "None";
                                            if (et !== "None") {
                                                miscuesArr.push({
                                                    word: wr.word || "",
                                                    spoken_word: wr.spoken_word || "",
                                                    error_type: et,
                                                    accuracy_score: wr.accuracy_score || 0
                                                });
                                            }
                                        });
                                        heldEvaluationData.miscues = miscuesArr;
                                        
                                        // Update the summary UI with the final correct Phil-IRI word reading score
                                        const wordsInPassage = passageTokens.length || 1;
                                        const totalMiscuesFinal = miscuesArr.length;
                                        const finalWordReadingScore = Math.max(0, ((wordsInPassage - totalMiscuesFinal) / wordsInPassage) * 100);
                                        const summWordReading = document.getElementById("ra-summ-word-reading");
                                        if (summWordReading) summWordReading.innerText = Math.round(finalWordReadingScore) + "%";
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
                
                const pronunContainer = document.getElementById("ra-pronunciation-results");
                if (pronunContainer) pronunContainer.style.display = "block";
                
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
                    
                    // Wait a tiny bit for MediaRecorder to fire its onstop event
                    await new Promise(r => setTimeout(r, 600));

                    const formData = new FormData();
                    formData.append('token', config.token);
                    formData.append('transcript', liveTranscript);
                    formData.append('accuracy_score', accuracy);
                    formData.append('reading_time', rTime);
                    formData.append('reading_speed', rSpeed);
                    formData.append('miscues_json', JSON.stringify(miscuesList));
                    formData.append('total_miscues', totalMiscuesCount);
                    formData.append('answers_json', JSON.stringify(studentAnswers));
                    formData.append('level_idx', currentLevel);
                    
                    if (finalAudioBlob) {
                        formData.append('audio_file', finalAudioBlob, 'attempt.webm');
                    }

                    const response = await fetch(submitUrl, {
                        method: "POST",
                        body: formData
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
