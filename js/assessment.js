window.ReadingAssessment = (function() {
    let pc = null;
    let dataChannel = null;
    let microphoneStream = null;
    let speechRecognition = null;
    let isPaused = false;
    let liveTranscript = "";
    let finalTranscript = "";
    let autoSubmitted = false;

    const ASR_SERVICE_URL = "http://localhost:8000";

    // Jaro-Winkler helper for live browser highlighting
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

    function updateLivePassageHighlighting(passageText, currentTranscript, submitCallback) {
        const passageBox = document.getElementById("ra-passage-text");
        if (!passageBox || !passageText) return;

        const cleanPassageWords = passageText.replace(/[^\w\s]/g, '').toLowerCase().split(/\s+/).filter(Boolean);
        const origPassageTokens = passageText.split(/(\s+)/);

        const cleanSpokenWords = currentTranscript.replace(/[^\w\s]/g, '').toLowerCase().split(/\s+/).filter(Boolean);

        let spokenIdx = 0;
        let matchedCount = 0;
        let wordIndex = 0;

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
                let sim = jaroWinkler(cleanWord, cleanSpokenWords[s]);
                if (sim > bestSim) {
                    bestSim = sim;
                    bestIdx = s;
                }
            }

            if (bestIdx !== -1 && bestSim >= 0.75) {
                matchedCount++;
                spokenIdx = bestIdx + 1;
                if (bestSim >= 0.92) {
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
            wordIndex++;
        }

        passageBox.innerHTML = html;

        // Auto-submit if all passage words are read
        if (cleanPassageWords.length > 0 && matchedCount >= cleanPassageWords.length && !autoSubmitted) {
            autoSubmitted = true;
            if (submitCallback) submitCallback();
        }
    }

    function init(config) {
        const startBtn = document.getElementById("ra-btn-start");
        const pauseBtn = document.getElementById("ra-btn-pause");
        const submitBtn = document.getElementById("ra-btn-submit");
        const statusText = document.getElementById("ra-status-text");
        const vadIndicator = document.getElementById("ra-vad-indicator");
        const transcriptDisplay = document.getElementById("ra-live-transcript");

        if (!startBtn) return;

        startBtn.disabled = false;
        pauseBtn.disabled = true;
        submitBtn.disabled = true;

        const triggerAutoSubmit = () => {
            if (submitBtn && !submitBtn.disabled) {
                statusText.textContent = "Passage completed cleanly! Auto-submitting assessment...";
                submitBtn.click();
            }
        };

        const processTranscriptUpdate = (fullText) => {
            if (transcriptDisplay) {
                transcriptDisplay.textContent = fullText;
            }
            updateLivePassageHighlighting(config.passage || '', fullText, triggerAutoSubmit);
        };

        startBtn.addEventListener("click", async () => {
            try {
                startBtn.disabled = true;
                statusText.textContent = "Connecting to ASR service...";
                autoSubmitted = false;

                let clientSecret = null;
                try {
                    const sessResp = await fetch(`${ASR_SERVICE_URL}/session`, { method: "POST" });
                    const sessData = await sessResp.json().catch(() => ({}));
                    if (sessResp.ok && sessData.client_secret) {
                        clientSecret = sessData.client_secret;
                    }
                } catch (e) {
                    console.warn("Could not reach Python ASR service /session endpoint:", e);
                }

                if (clientSecret) {
                    // --- WebRTC OpenAI Realtime ---
                    pc = new RTCPeerConnection();
                    dataChannel = pc.createDataChannel("oai-events");

                    dataChannel.onopen = () => {
                        statusText.textContent = "Recording active (OpenAI Realtime) — speak now.";
                        vadIndicator.classList.add("ra-vad-active");
                        pauseBtn.disabled = false;
                        submitBtn.disabled = false;

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
                            processTranscriptUpdate(finalTranscript + " " + liveTranscript);
                        } else if (msg.type === "conversation.item.input_audio_transcription.completed") {
                            finalTranscript += (msg.transcript || "") + " ";
                            liveTranscript = "";
                            processTranscriptUpdate(finalTranscript);
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
                    // --- Web Speech API Fallback ---
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
                        processTranscriptUpdate(finalTranscript + interimStr);
                    };

                    speechRecognition.start();
                    statusText.textContent = "Recording active (Browser Speech Engine) — speak now.";
                    vadIndicator.classList.add("ra-vad-active");
                    pauseBtn.disabled = false;
                    submitBtn.disabled = false;
                }

            } catch (err) {
                console.error("ASR Error:", err);
                statusText.textContent = "Error: " + err.message;
                startBtn.disabled = false;
            }
        });

        pauseBtn.addEventListener("click", () => {
            if (!isPaused) {
                if (microphoneStream) {
                    microphoneStream.getAudioTracks().forEach(t => t.enabled = false);
                }
                if (speechRecognition) {
                    speechRecognition.stop();
                }
                isPaused = true;
                pauseBtn.textContent = "Resume";
                statusText.textContent = "Recording paused.";
                vadIndicator.classList.remove("ra-vad-active");
            } else {
                if (microphoneStream) {
                    microphoneStream.getAudioTracks().forEach(t => t.enabled = true);
                }
                if (speechRecognition) {
                    speechRecognition.start();
                }
                isPaused = false;
                pauseBtn.textContent = "Pause";
                statusText.textContent = "Recording active — speak now.";
                vadIndicator.classList.add("ra-vad-active");
            }
        });

        submitBtn.addEventListener("click", async () => {
            pauseBtn.disabled = true;
            submitBtn.disabled = true;
            statusText.textContent = "Evaluating assessment...";

            if (microphoneStream) {
                microphoneStream.getTracks().forEach(t => t.stop());
            }
            if (speechRecognition) {
                speechRecognition.stop();
            }
            if (pc) {
                pc.close();
            }
            vadIndicator.classList.remove("ra-vad-active");

            // Gather student answers
            const studentAnswers = [];
            const questions = config.questions || [];
            questions.forEach((q, idx) => {
                const selected = document.querySelector(`input[name="q_${idx}"]:checked`);
                studentAnswers.push(selected ? parseInt(selected.value) : -1);
            });

            const correctAnswers = questions.map(q => q.correct !== undefined ? q.correct : 0);

            // Call Python service for Jaro-Winkler evaluation
            try {
                const evalResp = await fetch(`${ASR_SERVICE_URL}/evaluate`, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({
                        passage: config.passage,
                        transcript: finalTranscript + " " + liveTranscript,
                        answers: studentAnswers,
                        correct_answers: correctAnswers,
                        readingassessmentid: config.readingassessmentid,
                        userid: config.userid
                    })
                });

                const evalData = await evalResp.json();

                // Build submit URL using config.wwwroot or window.location
                const wwwroot = config.wwwroot || window.location.origin;
                const submitUrl = `${wwwroot}/mod/readingassessment/view.php?id=${config.cmid}&action=submit`;

                const params = new URLSearchParams({
                    transcript: finalTranscript + " " + liveTranscript,
                    accuracy_score: evalData.accuracy_score,
                    comprehension_score: evalData.comprehension_score,
                    final_grade: evalData.final_grade,
                    miscues_json: JSON.stringify(evalData.word_feedback || []),
                    answers_json: JSON.stringify(studentAnswers),
                    sesskey: config.sesskey || ''
                });

                const moodleResp = await fetch(submitUrl, {
                    method: "POST",
                    headers: { "Content-Type": "application/x-www-form-urlencoded" },
                    body: params
                });

                statusText.textContent = "Assessment submitted successfully! Reloading page to display results...";
                setTimeout(() => window.location.reload(), 1200);

            } catch (err) {
                console.error("Evaluation submission error:", err);
                statusText.textContent = "Error submitting evaluation: " + err.message;
            }
        });
    }

    return { init: init };
})();
