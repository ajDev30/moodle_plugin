window.InstructionalReader = (function() {
    let ws = null;
    let audioContext = null;
    let processorNode = null;
    let pc = null;
    let dataChannel = null;
    let microphoneStream = null;
    let speechRecognition = null;
    let isRecording = false;
    let isIntervening = false;
    let isAudioPlaying = false; // Anti-exploit flag: drop all audio deltas during playback
    let interventionTimer = null;
    let activeEngineName = "Connecting...";
    let moduleVoice = "en-US-JennyNeural";
    let moduleAsrUrl = "http://localhost:8010";

    // Word Isolation & Progressive Blending & Mastery State
    let isIsolatedMode = false;
    let isolatedTargetWord = "";
    let isolatedRespelling = "";
    let isolatedIpa = "";
    let isolatedSyllables = [];
    let isolatedBlendingSteps = [];
    let activeSyllableIndex = 0;
    let syllableStatus = []; // 'pending', 'focus', 'good', 'miscue'
    let wordMasteryCount = 0;
    let wordMasteryTarget = 2; // Dynamic teacher-configured target (1x, 2x, 3x, 4x, 5x)
    let coachingAttemptNumber = 1; // 3-stage blending correction tracker

    // Reading Speed & Timer State
    let readingStartTime = null;
    let totalReadingTimeSeconds = 0;
    let calculatedReadingSpeedWPM = 0;

    // Single Audio Manager State to prevent concurrent audio overlap
    let currentPlayingAudio = null;
    let audioCooldownTimer = null;

    let lines = [];
    let passageWordRecords = []; // Tracks every word's exact status: { word, line, index, status, spoken }
    let currentLineIndex = 0;
    let currentWordIndex = 0;
    let miscueCount = 0;
    let wordAudioMap = {}; // word -> data:audio/mp3;base64,...

    let liveTranscript = "";
    let finalTranscript = "";

    const ASR_SERVICE_URL = "http://localhost:8010";

    // Dynamic Runtime Pronunciation Cache (100% Word-Independent, NO Hardcoded Aliases)
    const pronunciationCache = new Map();

    async function getPronunciationData(word, asrServiceUrl) {
        const clean = cleanWord(word);
        if (!clean) return getPhoneticGuideLocal(word);

        if (pronunciationCache.has(clean)) {
            return pronunciationCache.get(clean);
        }

        try {
            const url = asrServiceUrl || ASR_SERVICE_URL;
            const resp = await fetch(`${url}/phoneme_analysis`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ word: clean })
            });
            if (resp.ok) {
                const data = await resp.json();
                if (data && data.word) {
                    pronunciationCache.set(clean, data);
                    return data;
                }
            }
        } catch (e) {
            console.warn("Dynamic phonetic lookup fallback:", e);
        }

        const localFallback = getPhoneticGuideLocal(word);
        pronunciationCache.set(clean, localFallback);
        return localFallback;
    }

    function cleanWord(str) {
        return (str || '').replace(/[^\w\u00C0-\u024F\u0250-\u02AF]/g, '').toLowerCase();
    }

    // Pure Azure Speech SDK Lexical Matcher
    function matchWholeWord(targetWord, spokenPhrase) {
        const targetClean = cleanWord(targetWord);
        const spokenClean = cleanWord(spokenPhrase);

        if (!targetClean || !spokenClean) return false;
        return targetClean === spokenClean;
    }

    // Pure Azure Speech SDK Syllable Matcher
    function matchSyllable(targetSyllable, spokenToken) {
        const sylClean = cleanWord(targetSyllable);
        if (!sylClean || !spokenToken) return false;

        const tokens = (typeof spokenToken === 'string')
            ? spokenToken.toLowerCase().split(/[\s\-.]+/).map(cleanWord).filter(Boolean)
            : [cleanWord(spokenToken)];

        return tokens.includes(sylClean);
    }

    // Phoneme-Level Pronunciation & Continuous Blending Evaluator
    // Enforces the core rule: ASR Lexical Match != Pronunciation Mastery
    function evaluatePronunciation(params) {
        const { targetWord, targetSyllables, spokenTranscript, attemptNumber = 1 } = params;
        const cleanTarget = cleanWord(targetWord);
        const spokenClean = (spokenTranscript || "").trim().toLowerCase();
        const chunks = spokenClean.split(/[\s\-.]+/).filter(Boolean);
        const joinedSpoken = chunks.join("");
        const isMonosyllable = (!targetSyllables || targetSyllables.length <= 1);
        
        // 1. Exact continuous fluent match (single token, no segmentation)
        if (spokenClean === cleanTarget || (chunks.length === 1 && chunks[0] === cleanTarget)) {
            return {
                lexicalMatch: true,
                phonemeMatch: true,
                syllableMatch: true,
                blendingScore: 1.0,
                errorType: "CORRECT_FLUENT",
                mastery: true,
                coachingRequired: false,
                feedbackPrompt: `Super! You read "${cleanTarget}" smoothly!`
            };
        }
        
        // 2. Segmented correct word (ANTI-FALSE-POSITIVE: ASR lexical match != mastery)
        // e.g., "gla-de", "gla de", "g l ay d", "gla...de"
        if (joinedSpoken === cleanTarget || chunks.join(" ") === cleanTarget || matchWholeWord(cleanTarget, spokenClean)) {
            let feedback = "";
            if (attemptNumber === 1) {
                feedback = `Almost! Let's blend it smoothly. Listen: ${cleanTarget}.`;
            } else if (attemptNumber === 2) {
                feedback = `Good try. Keep the sounds connected: ${cleanTarget}. Try it smoothly.`;
            } else {
                feedback = `Let's put the sounds together into one smooth word: ${cleanTarget}.`;
            }
            
            return {
                lexicalMatch: true,
                phonemeMatch: true,
                syllableMatch: isMonosyllable ? false : (chunks.length === targetSyllables.length),
                blendingScore: 0.65,
                errorType: "CORRECT_NEEDS_SMOOTHING",
                mastery: false, // REJECT FALSE POSITIVE
                coachingRequired: true,
                feedbackPrompt: feedback
            };
        }
        
        // 3. Unrecognized / Mispronounced attempt feedback
        let errorType = "UNRECOGNIZED";
        let feedback = `Listen closely: ${cleanTarget}. Now your turn!`;
        
        if (joinedSpoken.length > cleanTarget.length + 2) {
            errorType = "PHONEME_ADDITION";
            feedback = `Keep the sound pure without extra syllables. Listen: ${cleanTarget}.`;
        } else if (joinedSpoken.length < cleanTarget.length - 2) {
            errorType = "PHONEME_DELETION";
            feedback = `Don't miss the inner sounds. Listen: ${cleanTarget}.`;
        } else if (isMonosyllable && chunks.length > 1) {
            errorType = "SYLLABLE_SEGMENTATION_ERROR";
            feedback = `"${cleanTarget}" is one single sound. Say it together: ${cleanTarget}.`;
        }
        
        return {
            lexicalMatch: false,
            phonemeMatch: false,
            syllableMatch: false,
            blendingScore: 0.0,
            errorType: errorType,
            mastery: false,
            coachingRequired: true,
            feedbackPrompt: feedback
        };
    }

    function splitPassageIntoLines(passage) {
        if (!passage) return [];
        const rawLines = passage.split(/\r?\n/).map(l => l.trim()).filter(Boolean);
        const result = [];
        rawLines.forEach(line => {
            const words = line.split(/\s+/);
            if (words.length > 12) {
                let chunk = [];
                words.forEach(w => {
                    chunk.push(w);
                    if (chunk.length >= 8 && /[.!?,;:]$/.test(w)) {
                        result.push(chunk.join(' '));
                        chunk = [];
                    }
                });
                if (chunk.length > 0) result.push(chunk.join(' '));
            } else {
                result.push(line);
            }
        });
        return result.length > 0 ? result : [passage];
    }

    // Rule-based client phonetic fallback
    function getPhoneticGuideLocal(word) {
        const clean = cleanWord(word);
        return {
            word: clean,
            respelling: clean,
            ipa: `/${clean}/`,
            syllables: [clean],
            blending_steps: [
                { label: "Whole Word", formula: `[ ${clean} ]`, spoken_target: clean }
            ]
        };
    }

    // Hardware microphone mute / unmute to prevent loopback exploits
    function setMicrophoneEnabled(enabled) {
        if (microphoneStream) {
            microphoneStream.getAudioTracks().forEach(track => {
                track.enabled = enabled;
            });
        }
    }

    // Stop and cancel all previously playing audio streams immediately
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

    // Single-instance safe audio playback with strict hardware mic muting and acoustic flush
    function safePlayWordAudio(word, voiceName, callback) {
        stopAllPlayingAudio();

        isAudioPlaying = true;
        setMicrophoneEnabled(false); // HARD MUTE MIC to prevent loopback

        const cleaned = cleanWord(word);
        const b64Audio = wordAudioMap[cleaned];
        const voiceToUse = voiceName || moduleVoice;

        function onPlaybackFinished() {
            liveTranscript = "";
            audioCooldownTimer = setTimeout(() => {
                setMicrophoneEnabled(true); // UNMUTE MIC after 600ms acoustic silence
                isAudioPlaying = false;
                liveTranscript = "";
                if (callback) callback();
            }, 600);
        }

        if (b64Audio) {
            currentPlayingAudio = new Audio(b64Audio);
            currentPlayingAudio.onended = () => {
                currentPlayingAudio = null;
                onPlaybackFinished();
            };
            currentPlayingAudio.onerror = () => {
                currentPlayingAudio = null;
                fallbackBrowserTTS(word, onPlaybackFinished);
            };
            currentPlayingAudio.play().catch(() => {
                currentPlayingAudio = null;
                fallbackBrowserTTS(word, onPlaybackFinished);
            });
        } else {
            // Fetch dynamically via Azure Neural TTS endpoint
            fetch(`${moduleAsrUrl}/tts_phoneme_guide`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ text: word, voice: voiceToUse })
            })
            .then(r => r.json())
            .then(d => {
                if (d && d.audio) {
                    if (cleaned) wordAudioMap[cleaned] = d.audio;
                    currentPlayingAudio = new Audio(d.audio);
                    currentPlayingAudio.onended = () => {
                        currentPlayingAudio = null;
                        onPlaybackFinished();
                    };
                    currentPlayingAudio.onerror = () => {
                        currentPlayingAudio = null;
                        fallbackBrowserTTS(word, onPlaybackFinished);
                    };
                    currentPlayingAudio.play().catch(() => {
                        currentPlayingAudio = null;
                        fallbackBrowserTTS(word, onPlaybackFinished);
                    });
                } else {
                    fallbackBrowserTTS(word, onPlaybackFinished);
                }
            })
            .catch(() => {
                fallbackBrowserTTS(word, onPlaybackFinished);
            });
        }
    }

    // Progressive Sequential Phonics Sound-Out Player (wan -> wander -> wandered)
    function playProgressiveSoundOut(steps, voiceName) {
        if (!steps || steps.length === 0) return;
        let stepIdx = 0;

        function playNextStep() {
            if (stepIdx >= steps.length) {
                return;
            }
            const currentStep = steps[stepIdx];
            const targetWord = currentStep.spoken_target || currentStep.blend_so_far || currentStep.phoneme || currentStep.formula;
            stepIdx++;

            safePlayWordAudio(targetWord, voiceName, () => {
                if (stepIdx < steps.length) {
                    setTimeout(playNextStep, 350); // 350ms natural teacher cadence pause between sound steps
                }
            });
        }

        playNextStep();
    }

    function fallbackBrowserTTS(word, callback) {
        if ('speechSynthesis' in window) {
            try { window.speechSynthesis.cancel(); } catch(e) {}
            const utter = new SpeechSynthesisUtterance(word);
            utter.lang = 'en-US';
            utter.rate = 0.85;
            utter.onend = () => { if (callback) callback(); };
            utter.onerror = () => { if (callback) callback(); };
            window.speechSynthesis.speak(utter);
        } else {
            if (callback) callback();
        }
    }

    function stopAllMedia() {
        stopAllPlayingAudio();
        if (processorNode) {
            try { processorNode.disconnect(); } catch(e) {}
            processorNode = null;
        }
        if (audioContext) {
            try { audioContext.close(); } catch(e) {}
            audioContext = null;
        }
        if (microphoneStream) {
            microphoneStream.getTracks().forEach(t => { try { t.stop(); } catch(e) {} });
            microphoneStream = null;
        }
        if (ws) {
            try {
                if (ws.readyState === WebSocket.OPEN) {
                    ws.send(JSON.stringify({ type: "stop" }));
                }
                ws.close();
            } catch(e) {}
            ws = null;
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
        if (interventionTimer) {
            clearTimeout(interventionTimer);
            interventionTimer = null;
        }
        isAudioPlaying = false;
    }

    function renderCurrentLineUI() {
        const lineContainer = document.getElementById("ra-instructional-line");
        const lineProgress = document.getElementById("ra-instructional-progress");
        const prevLinesContainer = document.getElementById("ra-instructional-prev-lines");

        if (!lineContainer || currentLineIndex >= lines.length) return;

        if (lineProgress) {
            lineProgress.textContent = `Line ${currentLineIndex + 1} of ${lines.length}`;
        }

        const currentLineText = lines[currentLineIndex];
        const tokens = currentLineText.split(/\s+/);

        let html = "";
        tokens.forEach((token, idx) => {
            const rec = passageWordRecords.find(r => r.line === currentLineIndex && r.index === idx);
            const st = rec ? rec.status : 'pending';
            const isFocus = idx === currentWordIndex;
            
            let cls = "ra-inst-word";
            if (st === 'good') {
                cls += " ra-inst-word-good";
            } else if (st === 'miscue') {
                cls += " ra-inst-word-miscue";
            } else if (idx < currentWordIndex) {
                cls += " ra-inst-word-read";
            }

            if (isFocus) {
                cls += " ra-inst-word-focus";
            }

            html += `<span class="${cls}" id="ra-word-${currentLineIndex}-${idx}" onclick="InstructionalReader.playWord('${token}')">${token}</span> `;
        });

        lineContainer.innerHTML = html;

        if (prevLinesContainer) {
            if (currentLineIndex > 0) {
                prevLinesContainer.innerHTML = lines.slice(0, currentLineIndex).map(l => `<div class="ra-inst-prev-line">✓ ${l}</div>`).join('');
                prevLinesContainer.style.display = "block";
            } else {
                prevLinesContainer.style.display = "none";
            }
        }
        renderFinishLineUI();
    }

    // Render / Update Syllable Tiles with Live Green / Red / Yellow Highlights
    function updateSyllableTilesUI() {
        const container = document.getElementById("ra-syllables-container");
        if (!container || !isolatedSyllables || isolatedSyllables.length <= 1) return;

        let html = "";
        isolatedSyllables.forEach((syl, idx) => {
            const st = syllableStatus[idx] || 'pending';
            let cls = "ra-syllable-tile";
            let indicator = "";

            if (st === 'good') {
                cls += " ra-syllable-tile-good";
                indicator = " ✓";
            } else if (st === 'miscue') {
                cls += " ra-syllable-tile-miscue";
                indicator = " ✕";
            } else if (idx === activeSyllableIndex) {
                cls += " ra-syllable-tile-focus";
            }

            html += `<span class="${cls}" id="ra-syl-tile-${idx}" onclick="InstructionalReader.playWord('${syl}')">${syl}${indicator}</span>`;
            if (idx < isolatedSyllables.length - 1) {
                html += `<span class="ra-syllable-divider">·</span>`;
            }
        });

        container.innerHTML = html;
    }

    function updateMasteryBadgeUI() {
        const chip = document.getElementById("ra-mastery-chip");
        const promptEl = document.getElementById("ra-iso-prompt-text");
        if (!chip) return;

        if (wordMasteryCount === 0) {
            chip.className = "ra-mastery-chip";
            chip.innerHTML = `🎯 ${wordMasteryTarget}x Mastery Check: <strong>0 of ${wordMasteryTarget} Completed</strong>`;
            if (promptEl) promptEl.innerHTML = `👩‍🏫 <em>Sound out each syllable tile, or say the full word (${wordMasteryTarget}x to master):</em>`;
        } else if (wordMasteryCount > 0 && wordMasteryCount < wordMasteryTarget) {
            const remaining = wordMasteryTarget - wordMasteryCount;
            chip.className = "ra-mastery-chip ra-mastery-chip-progress ra-mastery-chip-1x";
            chip.innerHTML = `⭐ <strong>${wordMasteryCount} of ${wordMasteryTarget} Completed!</strong> Say it ${remaining} more time${remaining > 1 ? 's' : ''} to master it!`;
            if (promptEl) promptEl.innerHTML = `🌟 <em>Great job! Now say <strong>"${isolatedTargetWord}"</strong> again:</em>`;
        } else if (wordMasteryCount >= wordMasteryTarget) {
            chip.className = "ra-mastery-chip ra-mastery-chip-complete ra-mastery-chip-2x";
            chip.innerHTML = `🌟🌟 <strong>Mastered! (${wordMasteryTarget} of ${wordMasteryTarget} Completed)</strong>`;
            if (promptEl) promptEl.innerHTML = `🎉 <em>Word Mastered! Returning to story...</em>`;
        }
    }

    function renderFinishLineUI() {
        const boxContainer = document.querySelector(".ra-instructional-box");
        if (!boxContainer) return;

        let finishLineBox = document.getElementById("ra-finish-line-container");
        if (!finishLineBox) {
            finishLineBox = document.createElement("div");
            finishLineBox.id = "ra-finish-line-container";
            finishLineBox.className = "ra-finish-line-container";
            if (boxContainer.parentNode) {
                if (boxContainer.nextSibling) {
                    boxContainer.parentNode.insertBefore(finishLineBox, boxContainer.nextSibling);
                } else {
                    boxContainer.parentNode.appendChild(finishLineBox);
                }
            }
        }

        const readRecords = passageWordRecords.filter(r => r.status === 'good' || r.status === 'miscue');
        if (readRecords.length === 0) {
            finishLineBox.style.display = "none";
            return;
        }

        finishLineBox.style.display = "block";

        let html = `
            <div class="ra-finish-line-label">Completed Words (${readRecords.length}):</div>
            <div class="ra-finish-chips-wrapper" id="ra-finish-chips-wrapper">
        `;

        readRecords.forEach(rec => {
            const isGood = rec.status === 'good';
            const chipCls = isGood ? 'ra-finish-chip-good' : 'ra-finish-chip-miscue';
            const statusBadge = isGood ? '✓' : '✕';
            
            const guide = pronunciationCache.get(cleanWord(rec.word)) || {};
            const targetIpa = guide.ipa || `/${rec.word}/`;
            
            const azureRes = rec.azureResult || {};
            const scoreVal = azureRes.accuracy_score !== undefined ? `${Math.round(azureRes.accuracy_score)}%` : (isGood ? '100%' : 'Miscue');
            const errType = azureRes.error_type || (isGood ? 'Fluent' : 'Mispronunciation');

            let phonemesHtml = "";
            if (azureRes.phoneme_results && azureRes.phoneme_results.length > 0) {
                phonemesHtml = azureRes.phoneme_results.map(ph => {
                    const phCls = ph.result === 'CORRECT' ? 'ra-ph-good' : 'ra-ph-miscue';
                    return `<span class="ra-ph-chip ${phCls}">${ph.phoneme}: ${Math.round(ph.accuracy)}%</span>`;
                }).join(' ');
            } else if (guide.phonemes && guide.phonemes.length > 0) {
                phonemesHtml = guide.phonemes.map(ph => `<span class="ra-ph-chip ra-ph-good">${ph}</span>`).join(' ');
            }

            html += `
                <div class="ra-finish-chip ${chipCls}" onclick="InstructionalReader.playWord('${rec.word}')">
                    <span>${rec.word}</span> <small>${statusBadge}</small>
                    <div class="ra-phoneme-tooltip">
                        <div class="ra-tooltip-header">Word: <strong>${rec.word}</strong> <span class="ra-tooltip-score">${scoreVal}</span></div>
                        <div class="ra-tooltip-ipa">Target IPA: <code>[ ${targetIpa} ]</code></div>
                        <div class="ra-tooltip-ph-breakdown">${phonemesHtml}</div>
                        <div class="ra-tooltip-footer">Evaluation: <strong>${errType}</strong> (Click to Hear)</div>
                    </div>
                </div>
            `;
        });

        html += `</div>`;
        finishLineBox.innerHTML = html;
    }

    function markCurrentWordMiscue(azureWRes) {
        const targetRec = passageWordRecords.find(r => r.line === currentLineIndex && r.index === currentWordIndex);
        if (targetRec) {
            targetRec.status = 'miscue';
            if (azureWRes) targetRec.azureResult = azureWRes;
            miscueCount++;
            const miscueBadge = document.getElementById("ra-miscue-badge");
            if (miscueBadge) miscueBadge.textContent = `${miscueCount} Miscue${miscueCount > 1 ? 's' : ''}`;
        }
        renderFinishLineUI();
    }

    function markCurrentWordGood(azureWRes) {
        const targetRec = passageWordRecords.find(r => r.line === currentLineIndex && r.index === currentWordIndex);
        if (targetRec) {
            targetRec.status = 'good';
            if (azureWRes) targetRec.azureResult = azureWRes;
        }
        renderFinishLineUI();
    }

    // Google-Style Phonetic Respelling & Progressive Blending Card (Dynamic Multi-Tier Generation)
    async function enterWordIsolationMode(targetWord, voiceName, asrServiceUrl) {
        if (isIsolatedMode) return;
        isIsolatedMode = true;
        isIntervening = true;
        isolatedTargetWord = cleanWord(targetWord);
        activeSyllableIndex = 0;
        wordMasteryCount = 0; // Reset mastery requirement (0/N)

        markCurrentWordMiscue();

        const lineContainer = document.getElementById("ra-instructional-line");
        const coachBox = document.getElementById("ra-coach-bubble");

        // Dim the rest of the sentence
        if (lineContainer) {
            lineContainer.classList.add("ra-line-dimmed");
        }

        let guide = await getPronunciationData(targetWord, asrServiceUrl);

        isolatedRespelling = guide.respelling || targetWord;
        isolatedIpa = guide.ipa || `/${targetWord}/`;
        isolatedSyllables = guide.syllables || [targetWord];
        isolatedBlendingSteps = guide.blending_steps || [];
        syllableStatus = new Array(isolatedSyllables.length).fill('pending');

        let isoCard = document.getElementById("ra-isolation-card");
        if (!isoCard) {
            isoCard = document.createElement("div");
            isoCard.id = "ra-isolation-card";
            isoCard.className = "ra-isolation-card";
            if (lineContainer && lineContainer.parentNode) {
                lineContainer.parentNode.insertBefore(isoCard, lineContainer.nextSibling);
            }
        }

        // Render Syllables (only if multi-syllabic)
        let syllablesHtml = "";
        if (isolatedSyllables.length > 1) {
            syllablesHtml = `<div class="ra-syllable-container" id="ra-syllables-container"></div>`;
        }

        // Render Progressive Blending Steps Rows
        let stepsHtml = "";
        if (isolatedBlendingSteps.length > 1) {
            const stepRows = isolatedBlendingSteps.map((st, idx) => {
                const label = st.label || `Step ${st.step || (idx + 1)}`;
                const formula = st.formula || (st.blend_so_far ? `/${st.phoneme || ''}/ (${st.readable || ''}) → [ ${st.blend_so_far} ]` : `[ ${isolatedTargetWord} ]`);
                const targetWord = st.spoken_target || st.blend_so_far || st.phoneme || isolatedTargetWord;
                return `
                <div class="ra-blending-step-row" onclick="InstructionalReader.playWord('${targetWord}')">
                    <span class="ra-step-badge">${label}</span>
                    <span class="ra-step-formula">${formula}</span>
                    <span class="ra-step-sound-btn">🔊 Hear</span>
                </div>
                `;
            }).join('');

            stepsHtml = `
                <div class="ra-blending-steps-container">
                    <div style="font-size: 0.95rem; font-weight: 700; color: #475569; margin-bottom: 8px;">📚 Progressive Sound-Out Steps:</div>
                    ${stepRows}
                </div>
            `;
        }

        const soundoutBtnHtml = (isolatedBlendingSteps.length > 1) ? `
            <button class="ra-soundout-btn" onclick="InstructionalReader.playSteps()">
                <span>▶</span> Play Progressive Sound-Out
            </button>
        ` : '';

        isoCard.innerHTML = `
            <div class="ra-isolation-header">👩‍🏫 Teacher Sound-Out & Blending Guide:</div>
            <div class="ra-mastery-container">
                <span class="ra-mastery-chip" id="ra-mastery-chip">🎯 ${wordMasteryTarget}x Mastery Check: <strong>0 of ${wordMasteryTarget} Completed</strong></span>
            </div>
            <div class="ra-target-word-display">${targetWord}</div>
            <div class="ra-phonetic-badge-container">
                <span class="ra-phonetic-respelling">Phonetic: <strong>[ ${isolatedRespelling} ]</strong></span>
                <span class="ra-ipa-guide">${isolatedIpa}</span>
            </div>
            ${syllablesHtml}
            ${stepsHtml}
            <div class="ra-blending-actions">
                ${soundoutBtnHtml}
                <button class="ra-listen-btn" onclick="InstructionalReader.playWord('${targetWord}')">
                    <span>🔊</span> Hear Full Word
                </button>
            </div>
            <div class="ra-isolation-prompt" id="ra-iso-prompt-text">👩‍🏫 <em>Sound out each syllable tile, or say the full word (${wordMasteryTarget}x to master):</em></div>
        `;
        isoCard.style.display = "block";

        updateSyllableTilesUI();
        updateMasteryBadgeUI();

        if (coachBox) coachBox.style.display = "none";

        // Play whole word teacher audio with loopback protection
        safePlayWordAudio(targetWord, voiceName, () => {
            isIntervening = false;
        });
    }

    function exitWordIsolationMode() {
        isIsolatedMode = false;
        isIntervening = false;
        isolatedTargetWord = "";
        isolatedSyllables = [];
        isolatedBlendingSteps = [];
        activeSyllableIndex = 0;
        syllableStatus = [];
        wordMasteryCount = 0;
        coachingAttemptNumber = 1;

        const lineContainer = document.getElementById("ra-instructional-line");
        const isoCard = document.getElementById("ra-isolation-card");
        const coachBox = document.getElementById("ra-coach-bubble");

        if (lineContainer) {
            lineContainer.classList.remove("ra-line-dimmed");
        }
        if (isoCard) {
            isoCard.style.display = "none";
        }
        if (coachBox) {
            coachBox.style.display = "none";
        }
    }

    function init(config) {
        lines = splitPassageIntoLines(config.passage || "");
        currentLineIndex = 0;
        currentWordIndex = 0;
        miscueCount = 0;
        wordMasteryCount = 0;
        if (config.mastery_repetitions !== undefined && config.mastery_repetitions !== null) {
            const parsedTarget = parseInt(config.mastery_repetitions, 10);
            wordMasteryTarget = (!isNaN(parsedTarget) && parsedTarget > 0) ? parsedTarget : 2;
        } else {
            wordMasteryTarget = 2;
        }
        readingStartTime = null;
        totalReadingTimeSeconds = 0;
        calculatedReadingSpeedWPM = 0;

        // Initialize full word records map covering every word in order
        passageWordRecords = [];
        lines.forEach((lineText, lIdx) => {
            const tokens = lineText.split(/\s+/);
            tokens.forEach((tok, wIdx) => {
                const clean = cleanWord(tok);
                if (clean) {
                    passageWordRecords.push({
                        word: clean,
                        line: lIdx,
                        index: wIdx,
                        status: "good", // default to good until coached
                        spoken: clean
                    });
                }
            });
        });

        const startBtn = document.getElementById("ra-btn-inst-start");
        const coachBox = document.getElementById("ra-coach-bubble");
        const statusText = document.getElementById("ra-inst-status");
        const asrServiceUrl = config.asr_service_url || ASR_SERVICE_URL;
        const voice = config.tts_voice || "en-US-JennyNeural";
        moduleAsrUrl = asrServiceUrl;
        moduleVoice = voice;

        renderCurrentLineUI();

        // 1. Pre-fetch word TTS audio via OpenAI TTS API in background
        const allWords = (config.passage || "").split(/\s+/);
        fetch(`${asrServiceUrl}/tts_words`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                words: allWords,
                voice: voice,
                instructions: config.tts_personality_prompt || "",
                speed: 0.88
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.audio_map) wordAudioMap = data.audio_map;
        })
        .catch(e => console.warn("TTS audio pre-fetch fallback to browser:", e));

        // 2. Pre-fetch dynamic pronunciation representations for all unique words (100% Dynamic)
        const uniqueWords = [...new Set((config.passage || "").toLowerCase().match(/[a-z0-9]+/g) || [])];
        if (uniqueWords.length > 0) {
            fetch(`${asrServiceUrl}/batch_phoneme_analysis`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ words: uniqueWords })
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.results) {
                    for (const [w, guide] of Object.entries(data.results)) {
                        pronunciationCache.set(cleanWord(w), guide);
                    }
                }
            })
            .catch(e => console.warn("Batch pronunciation pre-fetch warning:", e));
        }

        function updateLiveStatus(statusMsg) {
            if (statusText) {
                statusText.textContent = statusMsg;
            }
        }

        function handleCoachingCompletion() {
            wordMasteryCount++;
            updateMasteryBadgeUI();

            if (wordMasteryCount < wordMasteryTarget) {
                // Flash all green and reset for next mastery attempt
                syllableStatus = new Array(isolatedSyllables.length).fill('good');
                updateSyllableTilesUI();

                setTimeout(() => {
                    activeSyllableIndex = 0;
                    syllableStatus = new Array(isolatedSyllables.length).fill('pending');
                    updateSyllableTilesUI();
                }, 800);
            } else {
                // Target repetitions completed -> Full mastery achieved!
                syllableStatus = new Array(isolatedSyllables.length).fill('good');
                updateSyllableTilesUI();

                setTimeout(() => {
                    advanceWordSuccessfully();
                }, 600);
            }
        }

        function handleSpokenTranscript(spokenPhrase, wordResults) {
            // Anti-exploit: strictly drop any speech received while system audio is playing
            if (isAudioPlaying || currentLineIndex >= lines.length) return;

            const currentLineTokens = lines[currentLineIndex].split(/\s+/);
            if (currentWordIndex >= currentLineTokens.length) return;

            const targetToken = currentLineTokens[currentWordIndex];
            const targetClean = cleanWord(targetToken);

            const spokenTokens = spokenPhrase.trim().toLowerCase().split(/\s+/);
            const latestSpoken = cleanWord(spokenTokens[spokenTokens.length - 1]);
            const fullSpokenChunk = cleanWord(spokenTokens.slice(-4).join(" "));
            const wordsList = Array.isArray(wordResults) ? wordResults : [];

            // Comprehensive phoneme & blending evaluation
            const evalResult = evaluatePronunciation({
                targetWord: targetClean,
                targetSyllables: isIsolatedMode ? isolatedSyllables : [targetClean],
                spokenTranscript: spokenPhrase,
                attemptNumber: coachingAttemptNumber
            });

            // --- Case A: Inside Teacher Sound-Out Guide (Dynamic Mastery & Syllable Matching) ---
            if (isIsolatedMode) {
                // 1. Direct Whole-Word Fluent Pronunciation Check (Bypasses remaining tiles if spoken smoothly)
                const isWholeWordSpoken = matchWholeWord(targetClean, latestSpoken) ||
                                          matchWholeWord(targetClean, fullSpokenChunk) ||
                                          matchWholeWord(targetClean, spokenPhrase) ||
                                          evalResult.errorType === 'CORRECT_FLUENT';

                if (isWholeWordSpoken) {
                    liveTranscript = "";
                    finalTranscript = "";
                    handleCoachingCompletion();
                    return;
                }

                // 2. Active Syllable Sound-Out & Progressive Cumulative Blending Check
                if (isolatedSyllables.length > 1 && activeSyllableIndex < isolatedSyllables.length) {
                    const targetSyl = cleanWord(isolatedSyllables[activeSyllableIndex]);
                    const cumulativeBlend = cleanWord(isolatedSyllables.slice(0, activeSyllableIndex + 1).join(''));

                    const isSylMatched = matchSyllable(targetSyl, latestSpoken) ||
                                         matchSyllable(targetSyl, fullSpokenChunk) ||
                                         matchSyllable(targetSyl, spokenPhrase) ||
                                         matchWholeWord(cumulativeBlend, latestSpoken) ||
                                         matchWholeWord(cumulativeBlend, fullSpokenChunk) ||
                                         matchWholeWord(cumulativeBlend, spokenPhrase);

                    if (isSylMatched) {
                        syllableStatus[activeSyllableIndex] = 'good';
                        activeSyllableIndex++;
                        updateSyllableTilesUI();

                        liveTranscript = "";
                        finalTranscript = "";

                        if (activeSyllableIndex >= isolatedSyllables.length) {
                            handleCoachingCompletion();
                            return;
                        }
                    } else if (latestSpoken.length >= 2 && !matchSyllable(targetSyl, latestSpoken) && !matchWholeWord(targetClean, latestSpoken)) {
                        syllableStatus[activeSyllableIndex] = 'miscue';
                        updateSyllableTilesUI();

                        setTimeout(() => {
                            if (syllableStatus[activeSyllableIndex] === 'miscue') {
                                syllableStatus[activeSyllableIndex] = 'focus';
                                updateSyllableTilesUI();
                            }
                        }, 800);
                    }
                } else if (isolatedSyllables.length <= 1) {
                    if (evalResult.errorType === 'CORRECT_NEEDS_SMOOTHING') {
                        const promptEl = document.getElementById("ra-iso-prompt-text");
                        if (promptEl) {
                            promptEl.innerHTML = `⚠️ <span class="ra-blend-needs-smoothing" style="color: #b45309; font-weight: 700;">${evalResult.feedbackPrompt}</span>`;
                        }
                        coachingAttemptNumber++;
                        safePlayWordAudio(targetToken, voice, () => {});
                        return;
                    }
                }
                return;
            }

            // --- Case B: Normal Line-by-Line Reading ---
            // Multi-word catch-up loop: scans incoming stream tokens & Azure acoustic scores
            let wordAdvancedInTurn = false;
            let currentLine = lines[currentLineIndex];
            if (!currentLine) return;
            let lineTokens = currentLine.split(/\s+/);
            let lastMatchedSpokenIdx = 0;

            while (currentLineIndex < lines.length && currentWordIndex < lineTokens.length) {
                const activeToken = lineTokens[currentWordIndex];
                const activeClean = cleanWord(activeToken);

                // Calculate global passage index of activeToken
                let targetPassageIdx = 0;
                for (let l = 0; l < currentLineIndex; l++) {
                    targetPassageIdx += (lines[l] || "").split(/\s+/).filter(Boolean).length;
                }
                targetPassageIdx += currentWordIndex;

                // Look up Azure per-word acoustic assessment if present (by clean word or passage index)
                const azureWRes = wordsList.find(w => cleanWord(w.word) === activeClean || w.passage_idx === targetPassageIdx);

                if (azureWRes) {
                    const isWordMatch = cleanWord(azureWRes.word) === activeClean || matchWholeWord(activeClean, cleanWord(azureWRes.word));

                    if (isWordMatch) {
                        const isMiscue = azureWRes.is_miscue ||
                                         azureWRes.error_type === "Mispronunciation" ||
                                         (azureWRes.accuracy_score !== undefined && azureWRes.accuracy_score < 60.0);

                        if (isMiscue) {
                            if (azureWRes.error_type === "Omission") {
                                // Word skipped: mark miscue and advance without interrupting continuous reading
                                markCurrentWordMiscue(azureWRes);
                                wordAdvancedInTurn = true;
                                const prevLineIndex = currentLineIndex;
                                advanceWordSuccessfully();

                                if (currentLineIndex >= lines.length) break;
                                if (currentLineIndex !== prevLineIndex) {
                                    currentLine = lines[currentLineIndex];
                                    if (!currentLine) break;
                                    lineTokens = currentLine.split(/\s+/);
                                }
                                continue;
                            } else {
                                // Mispronunciation: mark miscue, update UI, launch Coach Mode
                                markCurrentWordMiscue(azureWRes);
                                renderCurrentLineUI();
                                enterWordIsolationMode(activeToken, voice, asrServiceUrl);
                                break;
                            }
                        } else {
                            // Azure confirmed accurate pronunciation! Mark good, advance word.
                            markCurrentWordGood(azureWRes);
                            wordAdvancedInTurn = true;
                            const prevLineIndex = currentLineIndex;
                            advanceWordSuccessfully();

                            if (currentLineIndex >= lines.length) break;
                            if (currentLineIndex !== prevLineIndex) {
                                currentLine = lines[currentLineIndex];
                                if (!currentLine) break;
                                lineTokens = currentLine.split(/\s+/);
                            }
                            continue;
                        }
                    } else {
                        // Check if spoken word matches a future word in current line (Word Omission)
                        const futureMatchOffset = lineTokens.slice(currentWordIndex + 1).findIndex(t => matchWholeWord(cleanWord(t), cleanWord(azureWRes.word)));
                        if (futureMatchOffset !== -1) {
                            // Student skipped activeToken! Mark Omission Miscue
                            markCurrentWordMiscue({ error_type: 'Omission', accuracy_score: 0 });
                            const prevLineIndex = currentLineIndex;
                            advanceWordSuccessfully();

                            if (currentLineIndex >= lines.length) break;
                            if (currentLineIndex !== prevLineIndex) {
                                currentLine = lines[currentLineIndex];
                                if (!currentLine) break;
                                lineTokens = currentLine.split(/\s+/);
                            }
                            continue;
                        }
                    }
                }

                // Fallback string matching if explicit Azure per-word result is not in partial buffer yet
                // Match remaining spoken tokens in strict sequential order
                const remainingSpoken = spokenTokens.slice(lastMatchedSpokenIdx);
                const matchedRelIdx = remainingSpoken.findIndex(t => matchWholeWord(activeClean, cleanWord(t)));

                if (matchedRelIdx !== -1) {
                    lastMatchedSpokenIdx += matchedRelIdx + 1;
                    markCurrentWordGood();
                    wordAdvancedInTurn = true;
                    const prevLineIndex = currentLineIndex;
                    advanceWordSuccessfully();

                    if (currentLineIndex >= lines.length) break;
                    if (currentLineIndex !== prevLineIndex) {
                        currentLine = lines[currentLineIndex];
                        if (!currentLine) break;
                        lineTokens = currentLine.split(/\s+/);
                    }
                } else if (remainingSpoken.length === 1 && cleanWord(remainingSpoken[0]).length >= 3 && !matchWholeWord(activeClean, cleanWord(remainingSpoken[0]))) {
                    // Single spoken token attempted at active position but mispronounced: trigger Coach Mode
                    markCurrentWordMiscue();
                    renderCurrentLineUI();
                    enterWordIsolationMode(activeToken, voice, asrServiceUrl);
                    break;
                } else {
                    break;
                }
            }

            if (!wordAdvancedInTurn && !isIsolatedMode && spokenPhrase.trim().length > 0) {
                const curToken = lineTokens[currentWordIndex] || "";
                if (curToken) resetHesitationTimer(curToken);
            }
        }

        function advanceWordSuccessfully() {
            if (interventionTimer) {
                clearTimeout(interventionTimer);
                interventionTimer = null;
            }

            if (isIsolatedMode) {
                exitWordIsolationMode();
            }

            if (coachBox) coachBox.style.display = "none";

            const currentLineTokens = lines[currentLineIndex].split(/\s+/);

            // Mark word good in active line
            const activeWordEl = document.getElementById(`ra-word-${currentLineIndex}-${currentWordIndex}`);
            if (activeWordEl) {
                activeWordEl.classList.remove("ra-inst-word-focus", "ra-inst-word-miscue");
                activeWordEl.classList.add("ra-inst-word-good");
            }

            currentWordIndex++;

            // Check if line complete
            if (currentWordIndex >= currentLineTokens.length) {
                currentLineIndex++;
                currentWordIndex = 0;

                if (currentLineIndex < lines.length) {
                    renderCurrentLineUI();
                    resetHesitationTimer(lines[currentLineIndex].split(/\s+/)[0]);
                } else {
                    finishStory();
                }
            } else {
                renderCurrentLineUI();
                resetHesitationTimer(currentLineTokens[currentWordIndex]);
            }
        }

        function resetHesitationTimer(nextWord) {
            if (interventionTimer) clearTimeout(interventionTimer);
            if (!nextWord || isIsolatedMode) return;
            interventionTimer = setTimeout(() => {
                enterWordIsolationMode(nextWord, voice, asrServiceUrl);
            }, 7000); // 7.0s hesitation timeout (gives reader space to decode without interruption)
        }

        async function startWebRTCEngine() {
            try {
                let wsUrl = asrServiceUrl || "http://localhost:8010";
                if (wsUrl.startsWith("http://")) {
                    wsUrl = "ws://" + wsUrl.substring(7);
                } else if (wsUrl.startsWith("https://")) {
                    wsUrl = "wss://" + wsUrl.substring(8);
                } else if (!wsUrl.startsWith("ws://") && !wsUrl.startsWith("wss://")) {
                    wsUrl = "ws://" + wsUrl;
                }
                wsUrl = wsUrl.replace(/\/+$/, "") + "/ws/stream";

                activeEngineName = "Streaming Acoustic ASR";
                ws = new WebSocket(wsUrl);
                ws.binaryType = "arraybuffer";

                ws.onopen = async () => {
                    ws.send(JSON.stringify({
                        type: "start",
                        target: lines.join(" ") || "",
                        attempt_id: 1,
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

                    audioContext = new (window.AudioContext || window.webkitAudioContext)();
                    if (audioContext.state === 'suspended') {
                        await audioContext.resume();
                    }
                    const source = audioContext.createMediaStreamSource(microphoneStream);
                    processorNode = audioContext.createScriptProcessor(2048, 1, 1);

                    const inputSampleRate = audioContext.sampleRate;
                    const targetSampleRate = 16000;

                    processorNode.onaudioprocess = (e) => {
                        if (!isRecording || isAudioPlaying || !ws || ws.readyState !== WebSocket.OPEN) return;
                        const inputData = e.inputBuffer.getChannelData(0);

                        let resampled;
                        if (inputSampleRate === targetSampleRate) {
                            resampled = inputData;
                        } else {
                            const ratio = inputSampleRate / targetSampleRate;
                            const newLength = Math.round(inputData.length / ratio);
                            resampled = new Float32Array(newLength);
                            for (let i = 0; i < newLength; i++) {
                                resampled[i] = inputData[Math.min(Math.round(i * ratio), inputData.length - 1)];
                            }
                        }
                        ws.send(resampled.buffer);
                    };

                    source.connect(processorNode);
                    processorNode.connect(audioContext.destination);

                    updateLiveStatus(`🎙️ Listening (${activeEngineName})... Reading Line ${currentLineIndex + 1} of ${lines.length}.`);
                };

                ws.onmessage = (event) => {
                    try {
                        const msg = JSON.parse(event.data);
                        if ((msg.type === "partial" || msg.type === "final") && msg.result) {
                            const raw = msg.result.detected ? msg.result.detected.raw : (msg.text || "");
                            const wordResults = msg.result.words || msg.word_results || [];
                            if (raw || wordResults.length > 0) {
                                liveTranscript = raw;
                                handleSpokenTranscript(raw, wordResults);
                            }
                        }
                    } catch(e) {}
                };

                ws.onerror = (err) => {
                    console.warn("WebSocket Streaming ASR connection error:", err);
                    activeEngineName = "Browser Speech Engine (Fallback)";
                    updateLiveStatus(`Listening (${activeEngineName})... Reading Line ${currentLineIndex + 1} of ${lines.length}.`);
                };

            } catch (err) {
                console.error("Instructional Streaming ASR start error:", err);
                activeEngineName = "Browser Speech Engine (Fallback)";
                updateLiveStatus(`Listening (${activeEngineName})... Reading Line ${currentLineIndex + 1} of ${lines.length}.`);
            }
        }

        function finishStory() {
            stopAllMedia();
            if (isIsolatedMode) exitWordIsolationMode();
            isRecording = false;

            // Reading Speed = (No. of words read ÷ Reading time in seconds) × 60
            if (readingStartTime) {
                totalReadingTimeSeconds = Math.max(1, Math.round((Date.now() - readingStartTime) / 1000));
            } else {
                totalReadingTimeSeconds = 30;
            }

            const totalWords = passageWordRecords.length;
            const accuracyScore = (totalWords > 0) ? Math.max(0, ((totalWords - miscueCount) / totalWords) * 100).toFixed(2) : "100.00";
            calculatedReadingSpeedWPM = Math.round(((totalWords / totalReadingTimeSeconds) * 60) * 100) / 100;

            if (statusText) {
                statusText.innerHTML = `🎉 <strong>Story Completed!</strong> Total Words: ${totalWords} | Miscues: ${miscueCount} | Reading Accuracy: <strong>${accuracyScore}%</strong> | ⚡ Reading Speed: <strong>${calculatedReadingSpeedWPM} WPM</strong> (${totalReadingTimeSeconds}s).`;
            }

            // Show Comprehension / Quiz Section
            const qSection = document.getElementById("ra-instructional-quiz");
            const linkedQuizCard = document.getElementById("ra-linked-quiz-card");

            if (linkedQuizCard) {
                linkedQuizCard.style.display = "block";
                linkedQuizCard.scrollIntoView({ behavior: "smooth", block: "start" });
            } else if (qSection) {
                qSection.style.display = "block";
                qSection.scrollIntoView({ behavior: "smooth", block: "start" });
            }

            if (startBtn) {
                startBtn.disabled = true;
                startBtn.textContent = "✓ Story Completed";
            }
        }

        if (startBtn) {
            startBtn.addEventListener("click", async () => {
                if (!isRecording) {
                    isRecording = true;
                    if (!readingStartTime) {
                        readingStartTime = Date.now();
                    }
                    startBtn.textContent = "⏹ Stop Reading";
                    startBtn.className = "ra-btn ra-btn-done";

                    renderCurrentLineUI();
                    updateLiveStatus("Connecting to speech engine...");

                    await startWebRTCEngine();

                    const curLineWords = (lines[currentLineIndex] || "").split(/\s+/);
                    const activeWord = curLineWords[currentWordIndex] || curLineWords[0];
                    resetHesitationTimer(activeWord);

                } else {
                    stopAllMedia();
                    if (isIsolatedMode) exitWordIsolationMode();
                    isRecording = false;
                    startBtn.textContent = "▶ Resume Reading";
                    startBtn.className = "ra-btn ra-btn-start";
                    if (statusText) {
                        statusText.textContent = `Paused at Line ${currentLineIndex + 1} of ${lines.length}. Click [Resume Reading] to continue.`;
                    }
                }
            });
        }

        // Handle Questionnaire submission with Python evaluate_instructional endpoint
        const submitBtn = document.getElementById("ra-btn-inst-submit");
        if (submitBtn) {
            submitBtn.addEventListener("click", async () => {
                submitBtn.disabled = true;
                if (statusText) statusText.textContent = "Evaluating assessment via Python service...";

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

                // Prepare exact per-word feedback array for Moodle previous attempts view
                const formattedWordFeedback = passageWordRecords.map(r => ({
                    word: r.word,
                    status: r.status, // 'good' or 'miscue'
                    similarity: (r.status === 'good') ? 1.0 : 0.0,
                    spoken: (r.status === 'good') ? r.word : ''
                }));

                const totalWords = passageWordRecords.length;
                let clientCalculatedAccuracy = (totalWords > 0) ? Math.max(0, ((totalWords - miscueCount) / totalWords) * 100) : 100.0;
                clientCalculatedAccuracy = Math.round(clientCalculatedAccuracy * 100) / 100;

                let evalData = {
                    accuracy_score: clientCalculatedAccuracy,
                    comprehension_score: 100.0,
                    final_grade: clientCalculatedAccuracy,
                    reading_time: totalReadingTimeSeconds,
                    reading_speed: calculatedReadingSpeedWPM,
                    word_feedback: formattedWordFeedback
                };

                try {
                    const evalResp = await fetch(`${asrServiceUrl}/evaluate_instructional`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({
                            passage: config.passage,
                            transcript: finalTranscript,
                            miscue_count: miscueCount,
                            word_feedback: formattedWordFeedback,
                            reading_time: totalReadingTimeSeconds,
                            reading_speed: calculatedReadingSpeedWPM,
                            answers: studentAnswers,
                            correct_answers: questions.map(q => q.correct !== undefined ? q.correct : 0),
                            readingassessmentid: config.readingassessmentid,
                            userid: config.userid
                        })
                    });

                    if (evalResp.ok) {
                        evalData = await evalResp.json();
                    }
                } catch (e) {
                    console.warn("Using client-side evaluation fallback:", e);
                }

                try {
                    const wwwroot = config.wwwroot || window.location.origin;
                    const submitUrl = `${wwwroot}/mod/readingassessment/view.php?id=${config.cmid}&action=submit`;

                    const params = new URLSearchParams({
                        transcript: finalTranscript || config.passage,
                        accuracy_score: evalData.accuracy_score,
                        comprehension_score: evalData.comprehension_score,
                        final_grade: evalData.final_grade,
                        reading_time: totalReadingTimeSeconds,
                        reading_speed: calculatedReadingSpeedWPM,
                        miscues_json: JSON.stringify(evalData.word_feedback || formattedWordFeedback),
                        answers_json: JSON.stringify(studentAnswers),
                        asr_engine: 'instructional',
                        sesskey: config.sesskey || ''
                    });

                    await fetch(submitUrl, {
                        method: "POST",
                        headers: { "Content-Type": "application/x-www-form-urlencoded" },
                        body: params
                    });

                    if (statusText) statusText.textContent = "Instructional Assessment submitted successfully! Reloading...";
                    setTimeout(() => window.location.reload(), 1200);

                } catch (e) {
                    console.error("Evaluation submission error:", e);
                    if (statusText) statusText.textContent = "Error submitting evaluation: " + e.message;
                    submitBtn.disabled = false;
                }
            });
        }
    }

    return {
        init: init,
        playWord: function(word) {
            safePlayWordAudio(word, moduleVoice);
        },
        playSteps: function() {
            playProgressiveSoundOut(isolatedBlendingSteps, moduleVoice);
        },
        matchSyllable: matchSyllable,
        matchWholeWord: matchWholeWord,
        evaluatePronunciation: evaluatePronunciation
    };
})();
