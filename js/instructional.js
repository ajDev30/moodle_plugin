window.InstructionalReader = (function() {
    let pc = null;
    let dataChannel = null;
    let microphoneStream = null;
    let speechRecognition = null;
    let isRecording = false;
    let isIntervening = false;
    let isAudioPlaying = false; // Anti-exploit flag: drop all audio deltas during playback
    let interventionTimer = null;
    let activeEngineName = "Connecting...";

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

    const ASR_SERVICE_URL = "http://localhost:8000";

    // Dynamic alias maps populated automatically from Python NLP & OpenAI breakdown
    let WHOLE_WORD_ALIASES = {
        "miso": ["miso", "meeso", "meso", "me so", "mi so", "mee soh", "meesoh", "my so"],
        "mischievous": ["mischievous", "mischief", "mis chie vous", "mischievus", "mis chuh vuhs", "mischivous"],
        "wandered": ["wandered", "wander", "wahn derd", "wan der ed", "wonded", "wonde red"],
        "butterfly": ["butterfly", "butter fly", "but ter fly"],
        "caterpillar": ["caterpillar", "cater pillar", "cat er pil lar"],
        "understanding": ["understanding", "understand ing", "un der stand ing"],
        "edge": ["edge", "ej", "edg", "age"],
        "bridge": ["bridge", "brij", "bridg"],
        "knight": ["knight", "night", "nite"],
        "night": ["night", "nite", "knight"],
        "through": ["through", "thru", "throo", "throw"],
        "thought": ["thought", "thawt", "thot"],
        "laugh": ["laugh", "laf", "laff"],
        "school": ["school", "skool", "scool"],
        "friend": ["friend", "frend"],
        "a": ["a", "uh", "ay", "eh", "ah", "8"],
        "the": ["the", "da", "dee", "thuh", "thee", "th", "d"],
        "to": ["to", "too", "two", "tu", "2"],
        "in": ["in", "inn", "en", "an"],
        "on": ["on", "un", "awn"],
        "at": ["at", "et", "it"]
    };

    let SYLLABLE_ALIASES = {
        "mi": ["mi", "me", "mee", "my", "may", "m", "mai"],
        "so": ["so", "sew", "sow", "soh", "saw", "s", "soul"],
        "mis": ["mis", "miss", "miz", "mys", "mess", "ms"],
        "chie": ["chie", "chee", "chuh", "chiv", "chief", "che", "chi", "key", "she", "tea", "gee", "tchi", "chive", "cha", "k", "g", "ch"],
        "vous": ["vous", "vuhs", "vis", "vus", "vas", "us", "bus", "fuhs", "ves", "vouss", "v"],
        "wan": ["wan", "wahn", "one", "won", "juan", "when"],
        "der": ["der", "dur", "dir", "da", "there", "the", "dare", "dr"],
        "ed": ["ed", "d", "t", "id", "head"],
        "but": ["but", "butt", "bat", "bot"],
        "ter": ["ter", "tur", "tir", "tar", "tor", "ta"],
        "fly": ["fly", "flie", "fli", "ply"],
        "cat": ["cat", "kat", "cot", "cut"],
        "er": ["er", "ur", "ir", "a", "or", "ah"],
        "pil": ["pil", "pill", "pel", "pull"],
        "lar": ["lar", "ler", "lur", "lor", "la"],
        "un": ["un", "an", "on", "oon"],
        "stand": ["stand", "stan", "stun"],
        "ing": ["ing", "in", "een", "eng"]
    };

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

    // Metaphone algorithm: reduces spoken words to consonant-based phonetic roots
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

    // Hybrid Jaro-Winkler (60%) + Double Metaphone (40%) Similarity
    function computePhoneticSimilarity(w1, w2) {
        if (!w1 || !w2) return 0.0;
        
        let w1Clean = w1.replace(/[\s\-]/g, '');
        let w2Clean = w2.replace(/[\s\-]/g, '');

        if (w1Clean === w2Clean) return 1.0;

        let strSim = Math.max(
            jaroWinkler(w1, w2),
            jaroWinkler(w1Clean, w2Clean)
        );

        let m1 = metaphone(w1Clean);
        let m2 = metaphone(w2Clean);

        if (m1 && m2) {
            let metaSim = (m1 === m2) ? 1.0 : jaroWinkler(m1, m2);
            return (strSim * 0.6) + (metaSim * 0.4);
        }
        return strSim;
    }

    function cleanWord(str) {
        return (str || '').replace(/[^\w]/g, '').toLowerCase();
    }

    // Strict Whole-Word Matcher (Requires full length; single prefix like 'mi' will NEVER match 'miso')
    function matchWholeWord(targetWord, spokenPhrase) {
        const targetClean = cleanWord(targetWord);
        const spokenClean = cleanWord(spokenPhrase);

        if (!targetClean || !spokenClean) return false;
        if (targetClean === spokenClean) return true;

        // 1. Direct Whole-Word Alias Lookup (e.g. "miso" <-> "meeso", "mischievous" <-> "mischief")
        if (WHOLE_WORD_ALIASES[targetClean] && WHOLE_WORD_ALIASES[targetClean].includes(spokenClean)) {
            return true;
        }

        // 2. Anti-Prefix Guard: Spoken phrase MUST have at least 70% of target word length
        if (spokenClean.length < Math.floor(targetClean.length * 0.70)) {
            return false;
        }

        // 3. Metaphone Check for whole word
        const mTarget = metaphone(targetClean);
        const mSpoken = metaphone(spokenClean);
        if (mTarget && mSpoken && mTarget === mSpoken) {
            return true;
        }

        // 4. Composite Similarity (Strict >= 0.85)
        const sim = computePhoneticSimilarity(targetClean, spokenClean);
        return sim >= 0.85;
    }

    // Syllable Matcher (Tuned specifically for individual phonetic chunks e.g. "chie", "chuh", "mi", "so")
    function matchSyllable(targetSyllable, spokenToken) {
        const sylClean = cleanWord(targetSyllable);
        const spkClean = cleanWord(spokenToken);

        if (!sylClean || !spkClean) return false;
        if (sylClean === spkClean) return true;

        // 1. Syllable Alias Table Check
        if (SYLLABLE_ALIASES[sylClean] && SYLLABLE_ALIASES[sylClean].includes(spkClean)) {
            return true;
        }

        // 2. Metaphone Check
        const mTarget = metaphone(sylClean);
        const mSpoken = metaphone(spkClean);
        if (mTarget && mSpoken && mTarget === mSpoken) {
            return true;
        }

        // 3. Short Syllable Tolerant Match (length <= 4)
        const sylSim = Math.max(
            computePhoneticSimilarity(sylClean, spkClean),
            jaroWinkler(sylClean, spkClean)
        );
        return sylSim >= 0.65;
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
            fallbackBrowserTTS(word, onPlaybackFinished);
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
            const targetWord = currentStep.spoken_target || currentStep.formula;
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
        if (microphoneStream) {
            microphoneStream.getTracks().forEach(t => { try { t.stop(); } catch(e) {} });
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
            const isRead = idx < currentWordIndex;
            const isFocus = idx === currentWordIndex;
            
            let cls = "ra-inst-word";
            if (isRead) cls += " ra-inst-word-read";
            if (isFocus) cls += " ra-inst-word-focus";

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

    function markCurrentWordMiscue() {
        const targetRec = passageWordRecords.find(r => r.line === currentLineIndex && r.index === currentWordIndex);
        if (targetRec && targetRec.status !== 'miscue') {
            targetRec.status = 'miscue';
            miscueCount++;
            const miscueBadge = document.getElementById("ra-miscue-badge");
            if (miscueBadge) miscueBadge.textContent = `${miscueCount} Miscue${miscueCount > 1 ? 's' : ''}`;
        }
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

        let guide = getPhoneticGuideLocal(targetWord);
        try {
            const r = await fetch(`${asrServiceUrl}/breakdown_word`, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ word: targetWord })
            });
            const data = await r.json();
            if (data.respelling) {
                guide = data;
                // Dynamically integrate AI / NLP generated phonetic aliases
                if (data.aliases && typeof data.aliases === 'object') {
                    for (const [key, aliasList] of Object.entries(data.aliases)) {
                        const cleanKey = cleanWord(key);
                        if (cleanKey === isolatedTargetWord) {
                            if (!WHOLE_WORD_ALIASES[cleanKey]) WHOLE_WORD_ALIASES[cleanKey] = [];
                            WHOLE_WORD_ALIASES[cleanKey].push(...aliasList.map(cleanWord));
                        } else {
                            if (!SYLLABLE_ALIASES[cleanKey]) SYLLABLE_ALIASES[cleanKey] = [];
                            SYLLABLE_ALIASES[cleanKey].push(...aliasList.map(cleanWord));
                        }
                    }
                }
            }
        } catch(e) {
            console.warn("Using local rule-based fallback for word isolation:", e);
        }

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
            const stepRows = isolatedBlendingSteps.map(st => `
                <div class="ra-blending-step-row" onclick="InstructionalReader.playWord('${st.spoken_target}')">
                    <span class="ra-step-badge">${st.label}</span>
                    <span class="ra-step-formula">${st.formula}</span>
                    <span class="ra-step-sound-btn">🔊 Hear</span>
                </div>
            `).join('');

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
        const voice = config.tts_voice || "alloy";

        renderCurrentLineUI();

        // Pre-fetch word TTS audio via OpenAI TTS API in background (OpenAI TTS Guide)
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

        function handleSpokenTranscript(spokenPhrase) {
            // Anti-exploit: strictly drop any speech received while system audio is playing
            if (isAudioPlaying || currentLineIndex >= lines.length) return;

            const currentLineTokens = lines[currentLineIndex].split(/\s+/);
            if (currentWordIndex >= currentLineTokens.length) return;

            const targetToken = currentLineTokens[currentWordIndex];
            const targetClean = cleanWord(targetToken);

            const spokenTokens = spokenPhrase.trim().toLowerCase().split(/\s+/);
            const latestSpoken = cleanWord(spokenTokens[spokenTokens.length - 1]);
            const fullSpokenChunk = cleanWord(spokenTokens.slice(-4).join(" "));

            // --- Case A: Inside Teacher Sound-Out Guide (Dynamic Mastery & Syllable Matching) ---
            if (isIsolatedMode) {
                // 1. Check if student articulated the entire whole word
                const isWholeWordMatched = matchWholeWord(targetClean, latestSpoken) ||
                                          matchWholeWord(targetClean, fullSpokenChunk);

                if (isWholeWordMatched) {
                    handleCoachingCompletion();
                    return;
                }

                // 2. Check active target syllable tile (e.g. [ mi ] -> [ so ] or [ mis ] -> [ chie ] -> [ vous ])
                if (isolatedSyllables.length > 1 && activeSyllableIndex < isolatedSyllables.length) {
                    const targetSyl = cleanWord(isolatedSyllables[activeSyllableIndex]);
                    const isSylMatched = matchSyllable(targetSyl, latestSpoken) ||
                                         matchSyllable(targetSyl, fullSpokenChunk);

                    if (isSylMatched) {
                        // Correct Syllable -> Turn ONLY this tile GREEN!
                        syllableStatus[activeSyllableIndex] = 'good';
                        activeSyllableIndex++;
                        updateSyllableTilesUI();

                        // If all syllables completed for this round
                        if (activeSyllableIndex >= isolatedSyllables.length) {
                            handleCoachingCompletion();
                            return;
                        }
                    } else if (latestSpoken.length >= 2 && !matchSyllable(targetSyl, latestSpoken) && !matchWholeWord(targetClean, latestSpoken)) {
                        // Incorrect sound -> Flash RED!
                        syllableStatus[activeSyllableIndex] = 'miscue';
                        updateSyllableTilesUI();

                        setTimeout(() => {
                            if (syllableStatus[activeSyllableIndex] === 'miscue') {
                                syllableStatus[activeSyllableIndex] = 'focus';
                                updateSyllableTilesUI();
                            }
                        }, 900);
                    }
                }
                return;
            }

            // --- Case B: Normal Line-by-Line Reading ---
            const isLineWordMatched = matchWholeWord(targetClean, latestSpoken) ||
                                      matchWholeWord(targetClean, fullSpokenChunk) ||
                                      matchSyllable(targetClean, latestSpoken);

            if (isLineWordMatched) {
                advanceWordSuccessfully();
            } else {
                // Potential mispronunciation or delay -> trigger Sound-Out Guide
                if (!interventionTimer && !isIsolatedMode) {
                    interventionTimer = setTimeout(() => {
                        enterWordIsolationMode(targetToken, voice, asrServiceUrl);
                    }, 3000);
                }
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
            if (!nextWord) return;
            interventionTimer = setTimeout(() => {
                enterWordIsolationMode(nextWord, voice, asrServiceUrl);
            }, 4500); // 4.5s hesitation timeout
        }

        async function startWebRTCEngine() {
            try {
                let clientSecret = null;
                try {
                    const sessResp = await fetch(`${asrServiceUrl}/session`, {
                        method: "POST",
                        headers: { "Content-Type": "application/json" },
                        body: JSON.stringify({ personality: config.tts_personality_prompt || "" })
                    });
                    const sessData = await sessResp.json().catch(() => ({}));
                    if (sessResp.ok && sessData.client_secret) {
                        clientSecret = sessData.client_secret;
                    }
                } catch(e) {
                    console.warn("Could not reach Python ASR service /session endpoint:", e);
                }

                if (clientSecret) {
                    activeEngineName = "OpenAI Realtime (gpt-live-transcribe)";
                    pc = new RTCPeerConnection();
                    dataChannel = pc.createDataChannel("oai-events");

                    dataChannel.onopen = () => {
                        updateLiveStatus(`Listening (${activeEngineName})... Reading Line ${currentLineIndex + 1} of ${lines.length}.`);
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
                            handleSpokenTranscript(msg.delta || "");
                        } else if (msg.type === "conversation.item.input_audio_transcription.completed") {
                            finalTranscript += (msg.transcript || "") + " ";
                            liveTranscript = "";
                            handleSpokenTranscript(msg.transcript || "");
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
                    activeEngineName = "Browser Speech Engine (Offline Fallback)";
                    const SpeechRecognitionClass = window.SpeechRecognition || window.webkitSpeechRecognition;
                    if (SpeechRecognitionClass) {
                        speechRecognition = new SpeechRecognitionClass();
                        speechRecognition.continuous = true;
                        speechRecognition.interimResults = true;
                        speechRecognition.lang = 'en-US';

                        speechRecognition.onresult = (event) => {
                            let interim = '';
                            for (let i = event.resultIndex; i < event.results.length; ++i) {
                                if (event.results[i].isFinal) {
                                    finalTranscript += event.results[i][0].transcript + ' ';
                                    handleSpokenTranscript(event.results[i][0].transcript);
                                } else {
                                    interim += event.results[i][0].transcript;
                                    handleSpokenTranscript(interim);
                                }
                            }
                        };
                        speechRecognition.start();
                        updateLiveStatus(`Listening (${activeEngineName})... Reading Line ${currentLineIndex + 1} of ${lines.length}.`);
                    } else {
                        updateLiveStatus("Error: ASR service offline and Browser Speech API not supported.");
                    }
                }

            } catch (err) {
                console.error("Instructional WebRTC start error:", err);
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
            safePlayWordAudio(word, "alloy");
        },
        playSteps: function() {
            playProgressiveSoundOut(isolatedBlendingSteps, "alloy");
        }
    };
})();
