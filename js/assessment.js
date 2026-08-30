window.ReadingAssessment = (function() {
    let pc = null;
    let dataChannel = null;
    let microphoneStream = null;
    let speechRecognition = null;
    let isRecording = false;
    let liveTranscript = "";
    let finalTranscript = "";
    let heldEvaluationData = null;
    let retryCount = 0;
    let asrEngineUsed = "openai"; // 'openai' or 'browser'

    const ASR_SERVICE_URL = "http://localhost:8000";

    // Jaro-Winkler string distance
    function jaroWinkler(s1, s2) {
        if (s1 === s2) return 1.0;
        let l1 = s1.length, l2 = s2.length;
        if (l1 === 0 || l2 === 0) return 0.0;
        let matchDistance = Math.floor(Math.max(l1, l2) / 2) - 1;
        let s1Matches = new Array(l1).fill(false);
        let s2Matches = new Array(l2).fill(false);
        let matches = 0, transpositions = 0;

        for (let i = 0; i < l1; i++) {
            let start = Math.max(0, i - matchDistance);
            let end = Math.min(i + matchDistance + 1, l2);
            for (let j = start; j < end; j++) {
                if (s2Matches[j] || s1[i] !== s2[j]) continue;
                s1Matches[i] = true;
                s2Matches[j] = true;
                matches++;
                break;
            }
        }
        if (matches === 0) return 0.0;
        let k = 0;
        for (let i = 0; i < l1; i++) {
            if (!s1Matches[i]) continue;
            while (!s2Matches[k]) k++;
            if (s1[i] !== s2[k]) transpositions++;
            k++;
        }
        let sim = (matches / l1 + matches / l2 + (matches - transpositions / 2) / matches) / 3.0;
        let p = 0.1, prefix = 0;
        for (let i = 0; i < Math.min(4, Math.min(l1, l2)); i++) {
            if (s1[i] === s2[i]) prefix++;
            else break;
        }
        return sim + prefix * p * (1 - sim);
    }

    // Metaphone algorithm: reduces words to consonant-based phonetic roots
    function metaphone(word) {
        if (!word) return "";
        let str = word.toUpperCase().replace(/[^A-Z]/g, "");
        if (!str) return "";

        // Drop initial silent letters
        if (/^(KN|GN|PN|AE|WR)/.test(str)) {
            str = str.substring(1);
        } else if (/^X/.test(str)) {
            str = "S" + str.substring(1);
        } else if (/^WH/.test(str)) {
            str = "W" + str.substring(2);
        }

        let meta = "";
        let len = str.length;

        for (let i = 0; i < len; i++) {
            let c = str[i];
            let next = (i < len - 1) ? str[i + 1] : "";
            let prev = (i > 0) ? str[i - 1] : "";

            if (c === "B") {
                if (prev === "M" && i === len - 1) continue;
                meta += "B";
            } else if (c === "C") {
                if (next === "H") {
                    meta += "X";
                    i++;
                } else if (next === "I" || next === "E" || next === "Y") {
                    meta += "S";
                } else {
                    meta += "K";
                }
            } else if (c === "D") {
                if (next === "G" && (i + 2 < len) && (str[i + 2] === "E" || str[i + 2] === "I" || str[i + 2] === "Y")) {
                    meta += "J";
                    i += 2;
                } else {
                    meta += "T";
                }
            } else if (c === "G") {
                if (next === "H" && i === len - 2) continue;
                if (next === "N" && i === len - 2) continue;
                if (next === "I" || next === "E" || next === "Y") {
                    meta += "J";
                } else {
                    meta += "K";
                }
            } else if (c === "H") {
                if (/[AEIOU]/.test(next) && (!/[CSPTG]/.test(prev))) {
                    meta += "H";
                }
            } else if (c === "F" || c === "J" || c === "L" || c === "M" || c === "N" || c === "R") {
                meta += c;
            } else if (c === "K") {
                if (prev !== "C") meta += "K";
            } else if (c === "P") {
                if (next === "H") {
                    meta += "F";
                    i++;
                } else {
                    meta += "P";
                }
            } else if (c === "Q") {
                meta += "K";
            } else if (c === "S") {
                if (next === "H") {
                    meta += "X";
                    i++;
                } else {
                    meta += "S";
                }
            } else if (c === "T") {
                if (next === "H") {
                    meta += "0";
                    i++;
                } else if (next === "I" && (i + 2 < len) && (str[i + 2] === "O" || str[i + 2] === "A")) {
                    meta += "X";
                } else {
                    meta += "T";
                }
            } else if (c === "V") {
                meta += "F";
            } else if (c === "W" || c === "Y") {
                if (/[AEIOU]/.test(next)) meta += c;
            } else if (c === "X") {
                meta += "KS";
            } else if (c === "Z") {
                meta += "S";
            } else if (i === 0 && /[AEIOU]/.test(c)) {
                meta += c;
            }
        }
        return meta;
    }

    // Hybrid Jaro-Winkler (60%) + Metaphone (40%) Similarity
    function computePhoneticSimilarity(w1, w2) {
        if (!w1 || !w2) return 0.0;
        if (w1 === w2) return 1.0;

        let strSim = jaroWinkler(w1, w2);
        let m1 = metaphone(w1);
        let m2 = metaphone(w2);

        if (m1 && m2) {
            let metaSim = (m1 === m2) ? 1.0 : jaroWinkler(m1, m2);
            return (strSim * 0.6) + (metaSim * 0.4);
        }
        return strSim;
    }

    function updateLivePassageHighlighting(passageText, currentTranscript) {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox || !passageText) return;

        const origPassageTokens = passageText.split(/(\s+)/);
        const cleanSpokenWords = currentTranscript.replace(/[^\w\s]/g, '').toLowerCase().split(/\s+/).filter(Boolean);

        let spokenIdx = 0;
        let html = "";

        for (let i = 0; i < origPassageTokens.length; i++) {
            const token = origPassageTokens[i];
            if (/^\s+$/.test(token)) {
                html += token;
                continue;
            }

            const cleanWord = token.replace(/[^\w\s]/g, '').toLowerCase();
            let bestSim = 0.0;
            let bestIdx = -1;

            let windowEnd = Math.min(spokenIdx + 4, cleanSpokenWords.length);
            for (let s = spokenIdx; s < windowEnd; s++) {
                let sim = computePhoneticSimilarity(cleanWord, cleanSpokenWords[s]);
                if (sim > bestSim) {
                    bestSim = sim;
                    bestIdx = s;
                }
            }

            if (bestIdx !== -1 && bestSim >= 0.75) {
                spokenIdx = bestIdx + 1;
                if (bestSim >= 0.90) {
                    html += `<span class="word-good">${token}</span>`;
                } else {
                    html += `<span class="word-improvement">${token}</span>`;
                }
            } else {
                if (spokenIdx < cleanSpokenWords.length) {
                    html += `<span class="word-miscue">${token}</span>`;
                } else {
                    html += token; // Not read yet
                }
            }
        }

        passageBox.innerHTML = html;
    }

    function renderEvaluatedPassageHighlighting(passageText, wordFeedback) {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox || !passageText) return;

        if (!wordFeedback || !Array.isArray(wordFeedback)) {
            passageBox.textContent = passageText;
            return;
        }

        const origTokens = passageText.split(/(\s+)/);
        let fbIndex = 0;
        let html = "";

        for (let i = 0; i < origTokens.length; i++) {
            const token = origTokens[i];
            if (/^\s+$/.test(token)) {
                html += token;
                continue;
            }

            if (fbIndex < wordFeedback.length) {
                const fb = wordFeedback[fbIndex];
                const st = fb.status || 'miscue';
                const spokenStr = fb.spoken ? ` (Spoken: ${fb.spoken})` : '';

                if (st === 'good') {
                    html += `<span class="word-good" title="Spoken cleanly">${token}</span>`;
                } else if (st === 'improvement') {
                    html += `<span class="word-improvement" title="${spokenStr}">${token}</span>`;
                } else {
                    html += `<span class="word-miscue" title="${spokenStr}">${token}</span>`;
                }
                fbIndex++;
            } else {
                html += token;
            }
        }

        passageBox.innerHTML = html;
    }

    // Immediately stop microphone & close WebRTC connection to conserve API credits
    function stopRecordingMedia() {
        if (microphoneStream) {
            microphoneStream.getTracks().forEach(t => {
                try { t.stop(); } catch(e) {}
            });
            microphoneStream = null;
        }
        if (dataChannel) {
            try { dataChannel.close(); } catch(e) {}
            dataChannel = null;
        }
        if (pc) {
            try { pc.close(); } catch(e) {}
            pc = null;
        }
        if (speechRecognition) {
            try { speechRecognition.stop(); } catch(e) {}
            speechRecognition = null;
        }
    }

    function init(config) {
        const startBtn = document.getElementById("ra-btn-start");
        const retryBtn = document.getElementById("ra-btn-retry");
        const submitBtn = document.getElementById("ra-btn-submit");
        const statusText = document.getElementById("ra-status-text");
        const vadIndicator = document.getElementById("ra-vad-indicator");
        const transcriptDisplay = document.getElementById("ra-live-transcript");

        if (!startBtn) return;

        startBtn.disabled = false;
        retryBtn.disabled = true;

        const questions = config.questions || [];
        const asrServiceUrl = config.asr_service_url || ASR_SERVICE_URL;
        const attemptsExhausted = (config.attempts_exhausted === true);

        // Clear unanswered highlights when student picks an answer
        questions.forEach((q, idx) => {
            const radioOptions = document.querySelectorAll(`input[name="q_${idx}"]`);
            radioOptions.forEach(opt => {
                opt.addEventListener("change", () => {
                    const itemBox = document.getElementById(`ra-qitem-${idx}`);
                    if (itemBox) itemBox.classList.remove("ra-question-unanswered");
                });
            });
        });

        const validateAllQuestionsAnswered = () => {
            let allAnswered = true;
            let firstUnansweredEl = null;

            questions.forEach((q, idx) => {
                const selected = document.querySelector(`input[name="q_${idx}"]:checked`);
                const itemBox = document.getElementById(`ra-qitem-${idx}`);
                if (!selected) {
                    allAnswered = false;
                    if (itemBox) {
                        itemBox.classList.add("ra-question-unanswered");
                        if (!firstUnansweredEl) firstUnansweredEl = itemBox;
                    }
                } else {
                    if (itemBox) itemBox.classList.remove("ra-question-unanswered");
                }
            });

            if (!allAnswered && firstUnansweredEl) {
                firstUnansweredEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            return allAnswered;
        };

        // --- Start / Done Button Toggle ---
        startBtn.addEventListener("click", async () => {
            if (!isRecording) {
                // START RECORDING
                try {
                    startBtn.disabled = true;

                    // Practice Mode only if max attempts reached
                    if (attemptsExhausted) {
                        asrEngineUsed = "browser";
                        const SpeechRecognitionClass = window.SpeechRecognition || window.webkitSpeechRecognition;
                        if (!SpeechRecognitionClass) {
                            throw new Error("Web Speech API not supported in this browser. Please use Chrome/Edge.");
                        }

                        speechRecognition = new SpeechRecognitionClass();
                        speechRecognition.continuous = true;
                        speechRecognition.interimResults = true;
                        speechRecognition.lang = 'en-US';

                        speechRecognition.onresult = (event) => {
                            let interimStr = '';
                            for (let i = event.resultIndex; i < event.results.length; ++i) {
                                if (event.results[i].isFinal) {
                                    finalTranscript += event.results[i][0].transcript + ' ';
                                } else {
                                    interimStr += event.results[i][0].transcript;
                                }
                            }
                            const full = finalTranscript + interimStr;
                            if (transcriptDisplay) transcriptDisplay.textContent = full;
                            updateLivePassageHighlighting(config.passage || '', full);
                        };

                        speechRecognition.start();
                        isRecording = true;
                        statusText.textContent = "Practice Mode Active (Browser Speech Engine) — Speak now. Zero OpenAI API credits used.";
                        vadIndicator.classList.add("ra-vad-active");

                        startBtn.innerHTML = "<span>✓</span> Done";
                        startBtn.className = "ra-btn ra-btn-done";
                        startBtn.disabled = false;
                        retryBtn.disabled = false;
                        return;
                    }

                    // Official Allowed Attempt -> Use OpenAI Realtime API
                    statusText.textContent = "Connecting to ASR service...";
                    let clientSecret = null;
                    try {
                        const sessResp = await fetch(`${asrServiceUrl}/session`, { method: "POST" });
                        const sessData = await sessResp.json().catch(() => ({}));
                        if (sessResp.ok && sessData.client_secret) {
                            clientSecret = sessData.client_secret;
                        }
                    } catch (e) {
                        console.warn("Could not reach Python ASR service /session endpoint:", e);
                    }

                    if (clientSecret) {
                        // --- WebRTC OpenAI Realtime Engine ---
                        asrEngineUsed = "openai";
                        pc = new RTCPeerConnection();
                        dataChannel = pc.createDataChannel("oai-events");

                        dataChannel.onopen = () => {
                            isRecording = true;
                            statusText.textContent = "Recording active (Official AI Assessment) — speak now. Click [Done] when finished.";
                            vadIndicator.classList.add("ra-vad-active");
                            
                            startBtn.innerHTML = "<span>✓</span> Done";
                            startBtn.className = "ra-btn ra-btn-done";
                            startBtn.disabled = false;
                            retryBtn.disabled = false;

                            dataChannel.send(JSON.stringify({
                                type: "session.update",
                                session: {
                                    type: "transcription",
                                    audio: {
                                        input: {
                                            transcription: { model: "gpt-live-transcribe", delay: "minimal" },
                                            turn_detection: null
                                        }
                                    }
                                }
                            }));
                        };

                        dataChannel.onmessage = (event) => {
                            const msg = JSON.parse(event.data);
                            if (msg.type === "conversation.item.input_audio_transcription.delta") {
                                liveTranscript += (msg.delta || "");
                                const full = finalTranscript + " " + liveTranscript;
                                if (transcriptDisplay) transcriptDisplay.textContent = full;
                                updateLivePassageHighlighting(config.passage || '', full);
                            } else if (msg.type === "conversation.item.input_audio_transcription.completed") {
                                finalTranscript += (msg.transcript || "") + " ";
                                liveTranscript = "";
                                const full = finalTranscript;
                                if (transcriptDisplay) transcriptDisplay.textContent = full;
                                updateLivePassageHighlighting(config.passage || '', full);
                            }
                        };

                        microphoneStream = await navigator.mediaDevices.getUserMedia({
                            audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true }
                        });

                        microphoneStream.getTracks().forEach(track => pc.addTrack(track, microphoneStream));

                        const offer = await pc.createOffer();
                        await pc.setLocalDescription(offer);

                        const sdpResp = await fetch("https://api.openai.com/v1/realtime/calls", {
                            method: "POST",
                            body: offer.sdp,
                            headers: {
                                "Authorization": `Bearer ${clientSecret}`,
                                "Content-Type": "application/sdp"
                            }
                        });

                        if (!sdpResp.ok) throw new Error(await sdpResp.text());
                        const answerSdp = await sdpResp.text();
                        await pc.setRemoteDescription({ type: "answer", sdp: answerSdp });

                    } else {
                        // Fallback to Web Speech API
                        asrEngineUsed = "browser";
                        const SpeechRecognitionClass = window.SpeechRecognition || window.webkitSpeechRecognition;
                        if (!SpeechRecognitionClass) {
                            throw new Error("OpenAI API key missing and Web Speech API not supported in this browser.");
                        }

                        speechRecognition = new SpeechRecognitionClass();
                        speechRecognition.continuous = true;
                        speechRecognition.interimResults = true;
                        speechRecognition.lang = 'en-US';

                        speechRecognition.onresult = (event) => {
                            let interimStr = '';
                            for (let i = event.resultIndex; i < event.results.length; ++i) {
                                if (event.results[i].isFinal) {
                                    finalTranscript += event.results[i][0].transcript + ' ';
                                } else {
                                    interimStr += event.results[i][0].transcript;
                                }
                            }
                            const full = finalTranscript + interimStr;
                            if (transcriptDisplay) transcriptDisplay.textContent = full;
                            updateLivePassageHighlighting(config.passage || '', full);
                        };

                        speechRecognition.start();
                        isRecording = true;
                        statusText.textContent = "Recording active (Browser Speech Engine) — speak now. Click [Done] when finished.";
                        vadIndicator.classList.add("ra-vad-active");

                        startBtn.innerHTML = "<span>✓</span> Done";
                        startBtn.className = "ra-btn ra-btn-done";
                        startBtn.disabled = false;
                        retryBtn.disabled = false;
                    }

                } catch (err) {
                    console.error("ASR Error:", err);
                    statusText.textContent = "Error: " + err.message;
                    startBtn.disabled = false;
                }

            } else {
                // DONE CLICKED -> IMMEDIATELY STOP WEBRTC / MIC & EVALUATE READING SCORE
                stopRecordingMedia();
                isRecording = false;
                vadIndicator.classList.remove("ra-vad-active");
                statusText.textContent = "Evaluating reading fluency...";

                startBtn.innerHTML = "<span>▶</span> Start";
                startBtn.className = "ra-btn ra-btn-start";

                // Reading Speed = (No. of words read ÷ Reading time in seconds) × 60
                const readingTimeSeconds = readingStartTime ? Math.max(1, Math.round((Date.now() - readingStartTime) / 1000)) : 30;
                const wordsList = (config.passage || '').trim().split(/\s+/);
                const totalWords = wordsList.length;
                const readingSpeedWPM = Math.round(((totalWords / readingTimeSeconds) * 60) * 100) / 100;

                // Evaluate reading fluency with backend scoring service
                try {
                    const evalResp = await fetch(`${asrServiceUrl}/evaluate`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            passage: config.passage,
                            transcript: finalTranscript + " " + liveTranscript,
                            reading_time: readingTimeSeconds,
                            reading_speed: readingSpeedWPM,
                            answers: [],
                            correct_answers: [],
                            readingassessmentid: config.readingassessmentid,
                            userid: config.userid
                        })
                    });

                    const evalData = await evalResp.json();
                    evalData.reading_time = readingTimeSeconds;
                    evalData.reading_speed = readingSpeedWPM;
                    heldEvaluationData = evalData;

                    // Render evaluated 3-tier word highlighting
                    renderEvaluatedPassageHighlighting(config.passage || '', evalData.word_feedback);

                    let engineNote = (asrEngineUsed === "browser") ? " [Practice Mode - Browser Engine]" : " [Official AI Attempt]";
                    statusText.textContent = `Reading evaluated! Accuracy: ${evalData.accuracy_score}% | ⚡ Speed: ${readingSpeedWPM} WPM (${readingTimeSeconds}s)${engineNote}. Complete questionnaire below and click [Submit Assessment].`;

                } catch (err) {
                    console.error("Fluency evaluation error:", err);
                    statusText.textContent = "Fluency evaluation error: " + err.message;
                }
            }
        });

        // --- Retry Button ---
        retryBtn.addEventListener("click", () => {
            stopRecordingMedia();
            isRecording = false;
            vadIndicator.classList.remove("ra-vad-active");

            retryCount++;
            liveTranscript = "";
            finalTranscript = "";
            heldEvaluationData = null;

            const passageBox = document.getElementById("ra-passage-text");
            if (passageBox) passageBox.textContent = config.passage || '';
            if (transcriptDisplay) transcriptDisplay.textContent = "";

            startBtn.innerHTML = "<span>▶</span> Start";
            startBtn.className = "ra-btn ra-btn-start";
            startBtn.disabled = false;

            statusText.textContent = `Reading reset (Retry #${retryCount}). Click [Start] to re-read.`;
        });

        // --- Submit Assessment Button ---
        submitBtn.addEventListener("click", async () => {
            if (!heldEvaluationData && (finalTranscript.trim().length === 0 && liveTranscript.trim().length === 0)) {
                statusText.textContent = "⚠️ Please read the passage and click [Done] before submitting.";
                startBtn.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }

            if (isRecording) {
                stopRecordingMedia();
                isRecording = false;
                vadIndicator.classList.remove("ra-vad-active");
                startBtn.innerHTML = "<span>▶</span> Start";
                startBtn.className = "ra-btn ra-btn-start";
            }

            submitBtn.disabled = true;
            statusText.textContent = "Submitting assessment...";

            const questions = config.questions || [];
            const studentAnswers = [];
            questions.forEach((q, idx) => {
                const qtype = q.type || 'multichoice';
                if (qtype === 'description') {
                    studentAnswers.push(null);
                } else if (qtype === 'multichoice') {
                    const selected = document.querySelector(`input[name="ra_q_${idx}"]:checked`);
                    studentAnswers.push(selected ? parseInt(selected.value) : -1);
                } else if (qtype === 'truefalse') {
                    const selected = document.querySelector(`input[name="ra_q_${idx}"]:checked`);
                    studentAnswers.push(selected ? (selected.value === 'true') : null);
                } else if (qtype === 'matching' || qtype === 'randommatch') {
                    const pairs = q.pairs || [];
                    const pairAns = [];
                    pairs.forEach((p, pidx) => {
                        const sel = document.querySelector(`select[name="ra_q_${idx}_p_${pidx}"]`);
                        pairAns.push(sel ? sel.value : '');
                    });
                    studentAnswers.push(pairAns);
                } else if (qtype === 'ordering') {
                    const selects = document.querySelectorAll(`select[name^="ra_q_${idx}_ord_"]`);
                    const orderAns = [];
                    selects.forEach(sel => {
                        const span = sel.nextElementSibling;
                        orderAns.push({ order: parseInt(sel.value), text: span ? span.getAttribute('data-itemtext') : '' });
                    });
                    orderAns.sort((a, b) => a.order - b.order);
                    studentAnswers.push(orderAns.map(o => o.text));
                } else if (qtype === 'essay') {
                    const ta = document.querySelector(`textarea[name="ra_q_${idx}"]`);
                    studentAnswers.push(ta ? ta.value : '');
                } else {
                    const inp = document.querySelector(`input[name="ra_q_${idx}"]`);
                    studentAnswers.push(inp ? inp.value : '');
                }
            });

            try {
                const evalResp = await fetch(`${asrServiceUrl}/evaluate`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        passage: config.passage,
                        transcript: finalTranscript + " " + liveTranscript,
                        answers: studentAnswers,
                        correct_answers: questions.map(q => q.correct !== undefined ? q.correct : 0),
                        readingassessmentid: config.readingassessmentid,
                        userid: config.userid
                    })
                });

                const evalData = await evalResp.json();

                const wwwroot = config.wwwroot || window.location.origin;
                const submitUrl = `${wwwroot}/mod/readingassessment/view.php?id=${config.cmid}&action=submit`;

                const params = new URLSearchParams({
                    transcript: finalTranscript + " " + liveTranscript,
                    accuracy_score: evalData.accuracy_score,
                    comprehension_score: evalData.comprehension_score || 100.0,
                    final_grade: evalData.final_grade || evalData.accuracy_score,
                    reading_time: evalData.reading_time || (heldEvaluationData ? heldEvaluationData.reading_time : 0),
                    reading_speed: evalData.reading_speed || (heldEvaluationData ? heldEvaluationData.reading_speed : 0.0),
                    miscues_json: JSON.stringify(evalData.word_feedback || []),
                    answers_json: JSON.stringify(studentAnswers),
                    asr_engine: asrEngineUsed,
                    sesskey: config.sesskey || ''
                });

                await fetch(submitUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: params
                });

                statusText.textContent = "Assessment submitted successfully! Reloading page to display results...";
                setTimeout(() => window.location.reload(), 1200);

            } catch (err) {
                console.error("Evaluation submission error:", err);
                statusText.textContent = "Error submitting evaluation: " + err.message;
                submitBtn.disabled = false;
            }
        });
    }

    return { init: init };
})();
