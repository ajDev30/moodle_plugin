window.ReadingAssessment = (function() {
    let ws = null;
    let audioContext = null;
    let processorNode = null;
    let microphoneStream = null;
    let isRecording = false;
    let liveTranscript = "";
    let finalTranscript = "";
    let heldEvaluationData = null;
    let retryCount = 0;
    let asrEngineUsed = "azure";
    let readingStartTime = null;
    let wordAudioMap = {};
    let currentAudio = null;

    function getWsUrl(asrServiceUrl) {
        let url = asrServiceUrl || "http://localhost:8010";
        if (url.startsWith("http://")) {
            url = "ws://" + url.substring(7);
        } else if (url.startsWith("https://")) {
            url = "wss://" + url.substring(8);
        } else if (!url.startsWith("ws://") && !url.startsWith("wss://")) {
            url = "ws://" + url;
        }
        return url.replace(/\/+$/, "") + "/ws/stream";
    }

    function playWordAudio(word, asrServiceUrl, voice) {
        const clean = word.replace(/[^\w]/g, '').toLowerCase();
        if (!clean) return;

        if (currentAudio) {
            try { currentAudio.pause(); currentAudio.currentTime = 0; } catch(e) {}
        }

        if (wordAudioMap[clean]) {
            currentAudio = new Audio(wordAudioMap[clean]);
            currentAudio.play().catch(() => {});
            return;
        }

        const baseUrl = asrServiceUrl || "http://localhost:8010";
        fetch(`${baseUrl}/tts_words`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ words: [clean], voice: voice || "en-US-JennyNeural" })
        })
        .then(r => r.json())
        .then(d => {
            if (d && d.audio_map && d.audio_map[clean]) {
                wordAudioMap[clean] = d.audio_map[clean];
                currentAudio = new Audio(wordAudioMap[clean]);
                currentAudio.play().catch(() => {});
            }
        })
        .catch(e => console.warn("TTS audio fetch error:", e));
    }

    function renderEvaluatedPassageHighlighting(passageText, wordFeedback, asrServiceUrl, voice) {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox || !passageText) return;

        if (!wordFeedback || !Array.isArray(wordFeedback) || wordFeedback.length === 0) {
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
                const cleanW = token.replace(/[^\w]/g, '');
                const spokenStr = fb.spoken ? ` (Heard: ${fb.spoken})` : '';
                const disfluency = fb.disfluency && fb.disfluency.detected ? ` [${fb.disfluency.type}]` : '';

                const isGood = (fb.error_type === "None" && !fb.is_miscue) ||
                               (fb.accuracy_score !== undefined && fb.accuracy_score >= 60.0) ||
                               st === "CORRECT_FLUENT" || st === "good";

                let cls = "word-miscue";
                if (isGood) {
                    cls = "word-good";
                } else if (st === "ACCEPTABLE_REGIONAL" || st === "CORRECT_BUT_SEGMENTED" || st === "improvement") {
                    cls = "word-improvement";
                } else if (st === "REPEATED_WORD" || st === "REPEATED_ONSET" || st === "REPEATED_SYLLABLE" || st === "RESTART" || st === "BLOCK_OR_PROLONGATION") {
                    cls = "word-improvement";
                }

                html += `<span class="${cls} ra-clickable-word" data-word="${cleanW}" title="${st}${spokenStr}${disfluency} (Click to hear standard pronunciation)">${token}</span>`;
                fbIndex++;
            } else {
                html += token;
            }
        }

        passageBox.innerHTML = html;

        // Attach click listeners for instant OpenAI TTS audio playback
        passageBox.querySelectorAll(".ra-clickable-word").forEach(el => {
            el.addEventListener("click", () => {
                const w = el.getAttribute("data-word");
                playWordAudio(w, asrServiceUrl, voice);
            });
        });
    }

    function updateLivePassageHighlighting(passageText, wordsResult) {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox || !passageText) return;

        if (!wordsResult || !Array.isArray(wordsResult)) {
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

            if (fbIndex < wordsResult.length) {
                const wRes = wordsResult[fbIndex];
                const st = wRes.status;
                const isGood = (wRes.error_type === "None" && !wRes.is_miscue) ||
                               (wRes.accuracy_score !== undefined && wRes.accuracy_score >= 60.0) ||
                               st === "CORRECT_FLUENT" || wRes.mastery;
                const isMiscue = wRes.is_miscue ||
                                 wRes.error_type === "Mispronunciation" ||
                                 (wRes.accuracy_score !== undefined && wRes.accuracy_score < 60.0);

                let cls = "";
                if (isGood) {
                    cls = "word-good";
                } else if (isMiscue) {
                    cls = "word-miscue";
                } else if (st === "ACCEPTABLE_REGIONAL" || st === "CORRECT_BUT_SEGMENTED") {
                    cls = "word-improvement";
                } else if (st && st !== "OMITTED" && st !== "INSUFFICIENT_AUDIO") {
                    cls = "word-improvement";
                }

                if (cls) {
                    html += `<span class="${cls}">${token}</span>`;
                } else {
                    html += token;
                }
                fbIndex++;
            } else {
                html += token;
            }
        }

        passageBox.innerHTML = html;
    }

    function stopRecordingMedia() {
        if (processorNode) {
            try { processorNode.disconnect(); } catch(e) {}
            processorNode = null;
        }
        if (audioContext) {
            try { audioContext.close(); } catch(e) {}
            audioContext = null;
        }
        if (microphoneStream) {
            microphoneStream.getTracks().forEach(t => {
                try { t.stop(); } catch(e) {}
            });
            microphoneStream = null;
        }
        if (ws) {
            try {
                if (ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: "stop" }));
                }
            } catch(e) {}
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
        const asrServiceUrl = config.asr_service_url || "http://localhost:8010";
        const wsUrl = getWsUrl(asrServiceUrl);
        const ttsVoice = config.tts_voice || "alloy";

        questions.forEach((q, idx) => {
            const radioOptions = document.querySelectorAll(`input[name="q_${idx}"]`);
            radioOptions.forEach(opt => {
                opt.addEventListener("change", () => {
                    const itemBox = document.getElementById(`ra-qitem-${idx}`);
                    if (itemBox) itemBox.classList.remove("ra-question-unanswered");
                });
            });
        });

        // Preload word pronunciations in background for instant playback
        if (config.passage) {
            const wordsList = config.passage.split(/\s+/).map(w => w.replace(/[^\w]/g, '').toLowerCase()).filter(Boolean);
            fetch(`${asrServiceUrl}/tts_words`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ words: wordsList.slice(0, 40), voice: ttsVoice })
            })
            .then(r => r.json())
            .then(d => {
                if (d && d.audio_map) {
                    Object.assign(wordAudioMap, d.audio_map);
                }
            })
            .catch(() => {});
        }

        startBtn.addEventListener("click", async () => {
            if (!isRecording) {
                try {
                    startBtn.disabled = true;
                    statusText.textContent = "Connecting to Streaming ASR & Acoustic Assessment...";
                    readingStartTime = Date.now();

                    ws = new WebSocket(wsUrl);
                    ws.binaryType = "arraybuffer";

                    ws.onopen = async () => {
                        ws.send(JSON.stringify({
                            type: "start",
                            target: config.passage || "",
                            attempt_id: (config.attemptcount || 0) + 1,
                            moodle_user_id: config.userid,
                            moodle_attempt_id: config.cmid
                        }));

                        microphoneStream = await navigator.mediaDevices.getUserMedia({
                            audio: {
                                channelCount: 1,
                                echoCancellation: true,
                                noiseSuppression: true,
                                autoGainControl: true,
                            }
                        });

                        audioContext = new (window.AudioContext || window.webkitAudioContext)({ sampleRate: 16000 });
                        if (audioContext.state === 'suspended') {
                            await audioContext.resume();
                        }
                        const source = audioContext.createMediaStreamSource(microphoneStream);

                        const pcmWorkletCode = `
                            class PcmProcessor extends AudioWorkletProcessor {
                                process(inputs, outputs, parameters) {
                                    const input = inputs[0];
                                    if (input && input.length > 0) {
                                        const float32Data = input[0];
                                        const pcm16 = new Int16Array(float32Data.length);
                                        for (let i = 0; i < float32Data.length; i++) {
                                            const s = Math.max(-1, Math.min(1, float32Data[i]));
                                            pcm16[i] = s < 0 ? Math.round(s * 32768) : Math.round(s * 32767);
                                        }
                                        this.port.postMessage(pcm16.buffer, [pcm16.buffer]);
                                    }
                                    return true;
                                }
                            }
                            registerProcessor('pcm-processor', PcmProcessor);
                        `;

                        try {
                            const blob = new Blob([pcmWorkletCode], { type: 'application/javascript' });
                            const workletUrl = URL.createObjectURL(blob);
                            await audioContext.audioWorklet.addModule(workletUrl);

                            processorNode = new AudioWorkletNode(audioContext, 'pcm-processor');
                            processorNode.port.onmessage = (e) => {
                                if (!isRecording || !ws || ws.readyState !== WebSocket.OPEN) return;
                                ws.send(e.data);
                            };

                            source.connect(processorNode);
                            processorNode.connect(audioContext.destination);
                        } catch (workletErr) {
                            console.warn("AudioWorklet fallback to ScriptProcessor in assessment.js:", workletErr);
                            processorNode = audioContext.createScriptProcessor(2048, 1, 1);
                            processorNode.onaudioprocess = (e) => {
                                if (!isRecording || !ws || ws.readyState !== WebSocket.OPEN) return;
                                const inputData = e.inputBuffer.getChannelData(0);
                                const pcm16 = new Int16Array(inputData.length);
                                for (let i = 0; i < inputData.length; i++) {
                                    const s = Math.max(-1, Math.min(1, inputData[i]));
                                    pcm16[i] = s < 0 ? Math.round(s * 32768) : Math.round(s * 32767);
                                }
                                ws.send(pcm16.buffer);
                            };
                            source.connect(processorNode);
                            processorNode.connect(audioContext.destination);
                        }

                        isRecording = true;
                        statusText.textContent = "🎙️ Real-time Acoustic Assessment Active — Read the passage aloud now. Click [Done] when finished.";
                        vadIndicator.classList.add("ra-vad-active");

                        startBtn.innerHTML = "<span>✓</span> Done";
                        startBtn.className = "ra-btn ra-btn-done";
                        startBtn.disabled = false;
                        retryBtn.disabled = false;
                    };

                    ws.onmessage = (event) => {
                        try {
                            const data = JSON.parse(event.data);
                            const msgType = data.type;

                            if (msgType === "vad") {
                                if (data.is_speech) {
                                    vadIndicator.classList.add("ra-vad-active");
                                } else {
                                    vadIndicator.classList.remove("ra-vad-active");
                                }
                            } else if (msgType === "partial") {
                                const res = data.result;
                                if (res) {
                                    if (res.detected && res.detected.raw) {
                                        liveTranscript = res.detected.raw;
                                        if (transcriptDisplay) transcriptDisplay.textContent = liveTranscript;
                                    }
                                    if (res.words) {
                                        updateLivePassageHighlighting(config.passage || "", res.words);
                                    }
                                }
                            } else if (msgType === "final") {
                                const res = data.result;
                                heldEvaluationData = res;
                                isRecording = false;
                                stopRecordingMedia();
                                vadIndicator.classList.remove("ra-vad-active");

                                startBtn.innerHTML = "<span>▶</span> Start";
                                startBtn.className = "ra-btn ra-btn-start";
                                startBtn.disabled = false;

                                const readingTimeSeconds = readingStartTime ? Math.max(1, Math.round((Date.now() - readingStartTime) / 1000)) : 30;
                                const wordsList = (config.passage || '').trim().split(/\s+/);
                                const totalWords = wordsList.length;
                                const readingSpeedWPM = Math.round(((totalWords / readingTimeSeconds) * 60) * 100) / 100;

                                heldEvaluationData.reading_time = readingTimeSeconds;
                                heldEvaluationData.reading_speed = readingSpeedWPM;

                                const wordResults = res.words || [];
                                const scores = res.scores || res.passage_scores || {};
                                const wordAcc = Math.round((scores.word_accuracy || 0.0) * 100);
                                const phoneAcc = Math.round((scores.phoneme_accuracy || 0.0) * 100);
                                const calculatedMiscueCount = res.miscue_count !== undefined ? res.miscue_count : wordResults.filter(w => w.is_miscue || w.error_type === "Mispronunciation" || (w.accuracy_score !== undefined && w.accuracy_score < 60.0)).length;

                                renderEvaluatedPassageHighlighting(config.passage || '', wordResults, asrServiceUrl, ttsVoice);

                                let disfluencyNotice = res.disfluent_word_percentage > 0 ? ` | Disfluencies: ${res.repeated_words || 0}` : '';
                                statusText.textContent = `Acoustic reading evaluated! Word Accuracy: ${wordAcc}% | Miscues: ${calculatedMiscueCount} | Phoneme Acc: ${phoneAcc}% | ⚡ Speed: ${readingSpeedWPM} WPM (${readingTimeSeconds}s)${disfluencyNotice}. Complete questionnaire below and click [Submit Assessment].`;
                            }
                        } catch(err) {
                            console.debug("WS message decode error:", err);
                        }
                    };

                    ws.onerror = (err) => {
                        console.error("Streaming WebSocket error:", err);
                        statusText.textContent = "Streaming ASR connection error. Check if the Python service is running on port 8010.";
                        startBtn.disabled = false;
                    };

                    ws.onclose = () => {
                        if (isRecording) {
                            stopRecordingMedia();
                            isRecording = false;
                            vadIndicator.classList.remove("ra-vad-active");
                            startBtn.innerHTML = "<span>▶</span> Start";
                            startBtn.className = "ra-btn ra-btn-start";
                            startBtn.disabled = false;
                        }
                    };

                } catch (err) {
                    console.error("ASR Start Error:", err);
                    statusText.textContent = "Error: " + err.message;
                    startBtn.disabled = false;
                }

            } else {
                // Done clicked
                statusText.textContent = "Finalizing acoustic assessment...";
                if (ws && ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: "stop" }));
                } else {
                    stopRecordingMedia();
                    isRecording = false;
                    vadIndicator.classList.remove("ra-vad-active");
                    startBtn.innerHTML = "<span>▶</span> Start";
                    startBtn.className = "ra-btn ra-btn-start";
                }
            }
        });

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

        submitBtn.addEventListener("click", async () => {
            if (!heldEvaluationData && liveTranscript.trim().length === 0) {
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
            statusText.textContent = "Submitting assessment to Moodle Gradebook...";

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
                        transcript: liveTranscript,
                        answers: studentAnswers,
                        correct_answers: questions.map(q => q.correct !== undefined ? q.correct : 0),
                        reading_time: heldEvaluationData ? heldEvaluationData.reading_time : 30,
                        reading_speed: heldEvaluationData ? heldEvaluationData.reading_speed : 0.0,
                        readingassessmentid: config.readingassessmentid,
                        userid: config.userid
                    })
                });

                const evalData = await evalResp.json();
                const wwwroot = config.wwwroot || window.location.origin;
                const submitUrl = `${wwwroot}/mod/readingassessment/view.php?id=${config.cmid}&action=submit`;

                const miscuesList = heldEvaluationData && heldEvaluationData.words ? heldEvaluationData.words.map(w => {
                    const isGood = (!w.is_miscue && w.error_type === "None") ||
                                   (w.accuracy_score !== undefined && w.accuracy_score >= 60.0) ||
                                   w.status === "CORRECT_FLUENT" || w.mastery;
                    const isImprovement = w.status === "ACCEPTABLE_REGIONAL" || w.status === "CORRECT_BUT_SEGMENTED";
                    return {
                        word: w.target_word || w.word,
                        status: isGood ? "good" : (isImprovement ? "improvement" : "miscue"),
                        spoken: (w.phoneme_results ? w.phoneme_results.map(p => p.phoneme).join(" ") : (w.detected_phonemes || []).join(" ")),
                        phoneme_score: w.phoneme_score || w.accuracy_score,
                        disfluency: w.disfluency
                    };
                }) : (evalData.word_feedback || []);

                const params = new URLSearchParams({
                    transcript: liveTranscript,
                    accuracy_score: evalData.accuracy_score,
                    comprehension_score: evalData.comprehension_score || 100.0,
                    final_grade: evalData.final_grade || evalData.accuracy_score,
                    reading_time: evalData.reading_time || 0,
                    reading_speed: evalData.reading_speed || 0.0,
                    miscues_json: JSON.stringify(miscuesList),
                    answers_json: JSON.stringify(studentAnswers),
                    azure_speech_json: JSON.stringify(heldEvaluationData || evalData || {}),
                    asr_engine: asrEngineUsed,
                    sesskey: config.sesskey || ''
                });

                await fetch(submitUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: params
                });

                statusText.textContent = "Assessment submitted successfully! Reloading results...";
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
