import os
import re
import json
import base64
import asyncio
import jellyfish
from aiohttp import web
import aiohttp_cors
from openai import AsyncOpenAI

try:
    import pyphen
    PYPHEN_DIC = pyphen.Pyphen(lang='en_US')
except Exception:
    PYPHEN_DIC = None

try:
    import eng_to_ipa as ipa_lib
except Exception:
    ipa_lib = None

CACHE_FILE_PATH = os.path.join(os.path.dirname(__file__), "phonetic_cache.json")
PHONETIC_CACHE = {}

def load_cache():
    global PHONETIC_CACHE
    if os.path.isfile(CACHE_FILE_PATH):
        try:
            with open(CACHE_FILE_PATH, "r", encoding="utf-8") as f:
                PHONETIC_CACHE = json.load(f)
        except Exception as e:
            print("Notice: Starting with fresh phonetic cache:", e)
            PHONETIC_CACHE = {}

def save_cache():
    try:
        with open(CACHE_FILE_PATH, "w", encoding="utf-8") as f:
            json.dump(PHONETIC_CACHE, f, indent=2, ensure_ascii=False)
    except Exception as e:
        print("Warning: Could not save phonetic cache:", e)

load_cache()

def load_env_files():
    """
    Search common .env locations and set environment variables if missing.
    """
    candidates = [
        os.path.join(os.path.dirname(__file__), ".env"),
        "/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/.env",
        "/var/www/andrew/.env",
        os.path.expanduser("~/.env")
    ]
    for path in candidates:
        if os.path.isfile(path):
            try:
                with open(path, "r", encoding="utf-8", errors="ignore") as f:
                    for line in f:
                        line = line.strip()
                        if line and not line.startswith("#") and "=" in line:
                            parts = line.split("=", 1)
                            k = parts[0].strip().replace("export ", "")
                            v = parts[1].strip().strip("'\"")
                            if k and k not in os.environ:
                                os.environ[k] = v
            except Exception:
                pass

load_env_files()

def get_openai_client():
    api_key = os.environ.get("OPENAI_API_KEY", "")
    if not api_key:
        return None
    return AsyncOpenAI(api_key=api_key)

async def index(request):
    return web.FileResponse("./static/index.html") if os.path.exists("./static/index.html") else web.Response(text="Dynamic NLP ASR & Instructional Reader Service Running")

async def create_session(request):
    """
    Create an ephemeral client secret for browser-based WebRTC streaming to OpenAI Realtime API.
    """
    client = get_openai_client()
    if not client:
        return web.json_response({
            "error": "OPENAI_API_KEY environment variable is missing. Please set OPENAI_API_KEY in Moodle Site Admin or .env file."
        }, status=500)

    try:
        session = await client.realtime.client_secrets.create(
            session={
                "type": "transcription",
                "audio": {
                    "input": {
                        "transcription": {
                            "model": "gpt-live-transcribe",
                            "language": "en",
                            "delay": "minimal",
                        },
                        "turn_detection": None,
                    }
                },
            }
        )
        return web.json_response({
            "client_secret": session.value,
        })
    except Exception as e:
        return web.json_response({
            "error": f"OpenAI Realtime API error: {str(e)}"
        }, status=500)

def normalize_text(text: str) -> list[str]:
    """
    Clean text by lowercasing and stripping punctuation/whitespace.
    """
    if not text:
        return []
    cleaned = re.sub(r'[^\w\s]', '', text.lower())
    return cleaned.split()

def count_syllables(word: str) -> int:
    """
    Estimate English syllable count dynamically using pyphen and morphology.
    """
    clean_w = re.sub(r'[^a-z]', '', word.lower())
    if not clean_w:
        return 0
    if len(clean_w) <= 3:
        return 1

    monosyllables = {
        "edge", "dodge", "bridge", "badge", "judge", "school", "friend", "thought",
        "through", "night", "knight", "straight", "bright", "caught", "brought",
        "spring", "string", "strong", "scream", "please", "house", "mouse", "ground"
    }
    if clean_w in monosyllables:
        return 1

    if PYPHEN_DIC:
        try:
            hyphenated = PYPHEN_DIC.inserted(clean_w)
            parts = hyphenated.split('-')
            if len(parts) > 1:
                return len(parts)
        except Exception:
            pass

    w = re.sub(r'e$', '', clean_w)
    if not re.search(r'(ted|ded)$', clean_w):
        w = re.sub(r'ed$', '', w)
    w = re.sub(r'es$', '', w)

    vowel_groups = re.findall(r'[aeiouy]+', w)
    count = len(vowel_groups)
    return max(1, count)

def ipa_to_google_respelling(ipa_str: str, word: str) -> str:
    """
    Converts standard IPA transcription into readable Google-style phonetic respelling.
    """
    if not ipa_str or ipa_str == f"/{word}/":
        return word

    s = ipa_str.strip('/').strip()
    
    mapping = [
        ('tʃ', 'ch'), ('ʧ', 'ch'), ('dʒ', 'j'), ('ʤ', 'j'),
        ('θ', 'th'), ('ð', 'th'), ('ʃ', 'sh'), ('ʒ', 'zh'),
        ('ŋ', 'ng'), ('j', 'y'),
        ('eɪ', 'ay'), ('aɪ', 'eye'), ('ɔɪ', 'oy'),
        ('oʊ', 'oh'), ('aʊ', 'ow'),
        ('iː', 'ee'), ('uː', 'oo'),
        ('ʊ', 'uu'), ('ɪ', 'i'), ('ɛ', 'e'), ('æ', 'a'),
        ('ɑː', 'ah'), ('ɑ', 'ah'), ('ɒ', 'o'), ('ɔː', 'aw'),
        ('ɔ', 'aw'), ('ʌ', 'u'), ('ə', 'uh'),
        ('ər', 'er'), ('ɛər', 'air'), ('ɪər', 'eer')
    ]
    
    res = s
    for k, v in mapping:
        res = res.replace(k, v)
        
    res = res.replace('ˈ', '').replace('ˌ', '').replace('.', '-')
    return res

def generate_dynamic_phonetic_guide(clean_w: str) -> dict:
    """
    Dynamic Third-Party NLP Pipeline (pyphen + eng_to_ipa + CMUDict).
    """
    # 1. Monosyllabic check
    syl_count = count_syllables(clean_w)
    is_mono = (syl_count <= 1)

    # 2. Syllable Segmentation via pyphen
    if is_mono:
        syllables = [clean_w]
    elif PYPHEN_DIC:
        try:
            hyphenated = PYPHEN_DIC.inserted(clean_w)
            parts = hyphenated.split('-') if hyphenated else [clean_w]
            # Refine multi-syllable segmentation
            if len(parts) == 1 and len(clean_w) > 4:
                pattern = r'[^aeiouy]+[aeiouy]+(?:[^aeiouy]+(?![aeiouy]))?'
                matches = re.findall(pattern, clean_w)
                syllables = matches if (matches and "".join(matches) == clean_w) else [clean_w]
            else:
                syllables = parts
        except Exception:
            syllables = [clean_w]
    else:
        pattern = r'[^aeiouy]+[aeiouy]+(?:[^aeiouy]+(?![aeiouy]))?'
        matches = re.findall(pattern, clean_w)
        syllables = matches if (matches and "".join(matches) == clean_w) else [clean_w]

    # 3. IPA Transcription via eng_to_ipa (CMUDict)
    ipa_str = f"/{clean_w}/"
    if ipa_lib:
        try:
            converted = ipa_lib.convert(clean_w)
            if converted and not converted.endswith("*"):
                ipa_str = f"/{converted}/"
        except Exception:
            pass

    # 4. Google-Style Phonetic Respelling
    respelling = ipa_to_google_respelling(ipa_str, clean_w)
    if respelling == clean_w and len(syllables) > 1:
        respelling = "-".join(syllables)

    # 5. Progressive Cumulative Blending Steps
    blending_steps = []
    if is_mono:
        blending_steps = [{"label": "Whole Word", "formula": f"[ {clean_w} ]", "spoken_target": clean_w}]
    else:
        accum = ""
        for idx, s in enumerate(syllables):
            accum += s
            if idx == 0:
                blending_steps.append({"label": f"Step {idx+1}", "formula": f"[ {s} ]", "spoken_target": s})
            else:
                prev_chunk = "".join(syllables[:idx])
                blending_steps.append({"label": f"Step {idx+1}", "formula": f"[ {prev_chunk} ] + [ {s} ] ➔ \"{accum}\"", "spoken_target": accum})

    # 6. Automatic Phonetic Aliases for Speech Recognition
    aliases = {clean_w: [clean_w]}
    for s in syllables:
        aliases[s] = [s]

    return {
        "word": clean_w,
        "respelling": respelling,
        "ipa": ipa_str,
        "syllables": syllables,
        "blending_steps": blending_steps,
        "is_monosyllable": is_mono,
        "aliases": aliases
    }

async def get_or_create_phonetic_guide(word: str) -> dict:
    """
    Multi-Tier Phonetic Lookup with Persistent LRU Cache.
    """
    clean_w = re.sub(r'[^\w]', '', word.lower())
    if not clean_w:
        return {
            "word": "",
            "respelling": "",
            "ipa": "",
            "syllables": [],
            "blending_steps": [],
            "is_monosyllable": True,
            "aliases": {}
        }

    # 1. Check persistent on-disk / memory cache
    if clean_w in PHONETIC_CACHE:
        return PHONETIC_CACHE[clean_w]

    # 2. Dynamic generation using pyphen & eng_to_ipa NLP pipeline
    guide = generate_dynamic_phonetic_guide(clean_w)

    # 3. Store in persistent cache
    PHONETIC_CACHE[clean_w] = guide
    save_cache()

    return guide

def compute_phonetic_similarity(w1: str, w2: str) -> float:
    """
    Hybrid Phonetic Similarity Algorithm:
    Combines Jaro-Winkler string similarity (60%) with Metaphone phonetic root similarity (40%).
    Also handles concatenated spaced syllable utterances (e.g., 'wan der ed' matching 'wandered').
    """
    if not w1 or not w2:
        return 0.0
    
    w1_clean = w1.replace(" ", "").replace("-", "")
    w2_clean = w2.replace(" ", "").replace("-", "")

    if w1_clean == w2_clean:
        return 1.0

    str_sim = max(
        jellyfish.jaro_winkler_similarity(w1, w2),
        jellyfish.jaro_winkler_similarity(w1_clean, w2_clean)
    )

    m1 = jellyfish.metaphone(w1_clean)
    m2 = jellyfish.metaphone(w2_clean)

    if m1 and m2:
        if m1 == m2:
            meta_sim = 1.0
        else:
            meta_sim = jellyfish.jaro_winkler_similarity(m1, m2)
        composite = (str_sim * 0.6) + (meta_sim * 0.4)
        return round(min(1.0, max(0.0, composite)), 4)

    return round(str_sim, 4)

def align_and_score(expected_text: str, spoken_text: str):
    """
    Align target passage tokens with spoken transcript tokens using Hybrid Jaro-Winkler + Metaphone similarity.
    Formula: Reading accuracy = (Words - Miscues) / Words * 100
    3-Tier Classification:
    - Green (Good): similarity >= 0.90
    - Yellow (Slight Improvement): 0.75 <= similarity < 0.90
    - Red (Miscue / Poor): similarity < 0.75
    """
    expected_words = normalize_text(expected_text)
    spoken_words = normalize_text(spoken_text)

    if not expected_words:
        return {
            "total_words": 0,
            "good_count": 0,
            "improvement_count": 0,
            "miscue_count": 0,
            "accuracy_score": 100.0,
            "word_feedback": []
        }

    word_feedback = []
    good_count = 0
    improvement_count = 0
    miscue_count = 0

    spoken_index = 0
    spoken_len = len(spoken_words)

    for exp_word in expected_words:
        best_sim = 0.0
        best_match_idx = -1

        window_end = min(spoken_index + 4, spoken_len)
        for idx in range(spoken_index, window_end):
            spk_word = spoken_words[idx]
            sim = compute_phonetic_similarity(exp_word, spk_word)
            if sim > best_sim:
                best_sim = sim
                best_match_idx = idx

        if best_match_idx != -1:
            matched_spoken_word = spoken_words[best_match_idx]
            if best_sim >= 0.90:
                status = "good"  # Green
                good_count += 1
                spoken_index = best_match_idx + 1
            elif best_sim >= 0.75:
                status = "improvement"  # Yellow
                improvement_count += 1
                spoken_index = best_match_idx + 1
            else:
                status = "miscue"  # Red
                miscue_count += 1
        else:
            status = "miscue"  # Red
            best_sim = 0.0
            matched_spoken_word = ""
            miscue_count += 1

        word_feedback.append({
            "word": exp_word,
            "status": status,
            "similarity": round(best_sim, 4),
            "spoken": matched_spoken_word
        })

    total_words = len(expected_words)
    # Reading accuracy = (Words – Miscues) ÷ Words × 100
    accuracy = round(max(0.0, ((total_words - miscue_count) / total_words) * 100.0), 2) if total_words > 0 else 100.0

    return {
        "total_words": total_words,
        "good_count": good_count,
        "improvement_count": improvement_count,
        "miscue_count": miscue_count,
        "accuracy_score": accuracy,
        "word_feedback": word_feedback
    }

async def evaluate(request):
    """
    Evaluate student reading attempt & comprehension questions.
    """
    try:
        data = await request.json()
        passage = data.get("passage", "")
        transcript = data.get("transcript", "")
        student_answers = data.get("answers", [])
        correct_answers = data.get("correct_answers", [])

        reading_res = align_and_score(passage, transcript)
        accuracy_score = reading_res["accuracy_score"]

        total_questions = len(correct_answers)
        correct_q_count = 0
        answers_eval = []

        for i in range(total_questions):
            ans = student_answers[i] if i < len(student_answers) else None
            c_ans = correct_answers[i]
            is_correct = (ans is not None and int(ans) == int(c_ans))
            if is_correct:
                correct_q_count += 1
            answers_eval.append({
                "question_index": i,
                "student_answer": ans,
                "correct_answer": c_ans,
                "is_correct": is_correct
            })

        comprehension_score = round((correct_q_count / total_questions) * 100.0, 2) if total_questions > 0 else 100.0
        composite_grade = round((accuracy_score * 0.6) + (comprehension_score * 0.4), 2)

        reading_time = data.get("reading_time", 0)
        reading_speed = data.get("reading_speed", 0.0)

        response_payload = {
            "accuracy_score": accuracy_score,
            "comprehension_score": comprehension_score,
            "final_grade": composite_grade,
            "reading_time": reading_time,
            "reading_speed": reading_speed,
            "total_words": reading_res["total_words"],
            "good_count": reading_res["good_count"],
            "improvement_count": reading_res["improvement_count"],
            "miscue_count": reading_res["miscue_count"],
            "word_feedback": reading_res["word_feedback"],
            "answers_evaluated": answers_eval,
            "transcript_received": transcript
        }

        return web.json_response(response_payload)
    except Exception as e:
        return web.json_response({"error": str(e)}, status=400)

async def evaluate_instructional(request):
    """
    Dedicated evaluation endpoint for Instructional Readers, strictly calculating:
    Reading accuracy = (Words - Miscues) / Words * 100
    """
    try:
        data = await request.json()
        passage = data.get("passage", "")
        transcript = data.get("transcript", "")
        client_miscues = data.get("miscue_count", 0)
        client_word_feedback = data.get("word_feedback", [])
        reading_time = data.get("reading_time", 0)
        reading_speed = data.get("reading_speed", 0.0)
        student_answers = data.get("answers", [])
        correct_answers = data.get("correct_answers", [])

        expected_tokens = normalize_text(passage)
        total_words = len(expected_tokens)

        # Total miscues from actual guided reading session
        total_miscues = min(total_words, max(0, int(client_miscues)))
        
        # Formula: Reading accuracy = (Words – Miscues) ÷ Words × 100
        good_words = max(0, total_words - total_miscues)
        accuracy_score = round((good_words / total_words) * 100.0, 2) if total_words > 0 else 100.0

        # Construct or preserve accurate word feedback
        if client_word_feedback and isinstance(client_word_feedback, list) and len(client_word_feedback) == total_words:
            final_word_feedback = client_word_feedback
        else:
            # Construct deterministic word feedback
            final_word_feedback = []
            remaining_miscues = total_miscues
            for w in expected_tokens:
                if remaining_miscues > 0:
                    status = "miscue"
                    remaining_miscues -= 1
                else:
                    status = "good"
                final_word_feedback.append({
                    "word": w,
                    "status": status,
                    "similarity": 1.0 if status == "good" else 0.0,
                    "spoken": w if status == "good" else ""
                })

        total_questions = len(correct_answers)
        correct_q_count = 0
        answers_eval = []

        for i in range(total_questions):
            ans = student_answers[i] if i < len(student_answers) else None
            c_ans = correct_answers[i]
            is_correct = (ans is not None and int(ans) == int(c_ans))
            if is_correct:
                correct_q_count += 1
            answers_eval.append({
                "question_index": i,
                "student_answer": ans,
                "correct_answer": c_ans,
                "is_correct": is_correct
            })

        comprehension_score = round((correct_q_count / total_questions) * 100.0, 2) if total_questions > 0 else 100.0
        composite_grade = round((accuracy_score * 0.6) + (comprehension_score * 0.4), 2)

        return web.json_response({
            "accuracy_score": accuracy_score,
            "comprehension_score": comprehension_score,
            "final_grade": composite_grade,
            "reading_time": reading_time,
            "reading_speed": reading_speed,
            "total_words": total_words,
            "miscue_count": total_miscues,
            "good_count": good_words,
            "word_feedback": final_word_feedback,
            "answers_evaluated": answers_eval,
            "transcript_received": transcript
        })
    except Exception as e:
        return web.json_response({"error": str(e)}, status=400)

async def breakdown_word(request):
    """
    Dynamic breakdown of any target word with multi-tier NLP & AI generation.
    """
    try:
        data = await request.json()
        word = data.get("word", "")
        guide = await get_or_create_phonetic_guide(word)
        return web.json_response(guide)
    except Exception as e:
        return web.json_response({"error": str(e)}, status=400)

async def tts_words(request):
    """
    Generate word/phoneme-level audio pronunciations via OpenAI TTS API.
    Supports OpenAI instructions (personality steering) and speed control.
    """
    client = get_openai_client()
    if not client:
        return web.json_response({"audio_map": {}})

    try:
        data = await request.json()
        words = data.get("words", [])
        voice = data.get("voice", "alloy")
        instructions = data.get("instructions") or data.get("personality") or ""
        speed = float(data.get("speed", 0.88))
        if speed < 0.25 or speed > 4.0:
            speed = 0.88

        audio_map = {}
        unique_words = list(dict.fromkeys([re.sub(r'[^\w]', '', w.lower()) for w in words if w.strip()]))[:60]
        tts_models = ["gpt-4o-mini-tts", "tts-1-hd", "tts-1-hd-1106", "tts-1"]

        for w in unique_words:
            if not w:
                continue
            for model_name in tts_models:
                try:
                    params = {
                        "model": model_name,
                        "voice": voice,
                        "input": w,
                        "speed": speed,
                        "response_format": "mp3"
                    }
                    if "gpt-4o" in model_name and instructions:
                        params["instructions"] = instructions

                    response = await client.audio.speech.create(**params)
                    audio_bytes = response.content
                    b64_audio = base64.b64encode(audio_bytes).decode("utf-8")
                    audio_map[w] = f"data:audio/mp3;base64,{b64_audio}"
                    break
                except Exception as ex:
                    pass

        return web.json_response({"audio_map": audio_map})
    except Exception as e:
        return web.json_response({"audio_map": {}, "notice": str(e)}, status=200)

async def transcribe(request):
    """
    Fallback REST transcription endpoint using OpenAI Whisper API.
    """
    client = get_openai_client()
    if not client:
        return web.json_response({"error": "OPENAI_API_KEY environment variable is missing"}, status=500)

    try:
        reader = await request.multipart()
        field = await reader.next()
        if field.name != 'audio':
            return web.json_response({"error": "Expected audio field"}, status=400)

        filename = field.filename or "audio.webm"
        file_bytes = await field.read()

        transcript_res = await client.audio.transcriptions.create(
            model="whisper-1",
            file=(filename, file_bytes),
            language="en"
        )
        return web.json_response({"transcript": transcript_res.text})
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)

LETTER_PHONEMES = {
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
    "th": ["th", "the", "tha", "theh", "thuh", "thumb", "think", "three"]
}

async def phonics_letter_eval(request):
    """
    Evaluates student articulation of individual letters / phonemes for emergent non-readers.
    """
    try:
        data = await request.json()
        target_letter = (data.get("letter") or "").strip().lower()
        spoken_transcript = (data.get("transcript") or "").strip().lower()

        if not target_letter:
            return web.json_response({"matched": False, "reason": "No target letter provided"})

        spoken_tokens = [re.sub(r"[^\w]", "", t) for t in spoken_transcript.split() if t]
        allowed_phonemes = LETTER_PHONEMES.get(target_letter, [target_letter])

        matched = False
        matched_token = ""

        # Direct token match against phoneme table
        for token in spoken_tokens:
            if token in allowed_phonemes or token == target_letter:
                matched = True
                matched_token = token
                break

        # Fuzzy phonetic similarity fallback
        if not matched:
            for token in spoken_tokens:
                for ph in allowed_phonemes:
                    sim = jellyfish.jaro_winkler_similarity(token, ph)
                    if sim >= 0.82:
                        matched = True
                        matched_token = token
                        break
                if matched:
                    break

        return web.json_response({
            "matched": matched,
            "target": target_letter,
            "spoken": spoken_transcript,
            "matched_token": matched_token
        })
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)

async def tts_phoneme_guide(request):
    """
    Generates OpenAI TTS audio guide for letter sounds or letter-by-letter blending words.
    """
    client = get_openai_client()
    if not client:
        return web.json_response({"audio": None, "fallback": "speech_synthesis"}, status=200)

    try:
        data = await request.json()
        text_to_speak = data.get("text", "")
        voice = data.get("voice", "alloy")
        instructions = data.get("instructions") or data.get("personality") or ""
        speed = float(data.get("speed", 0.88))
        if speed < 0.25 or speed > 4.0:
            speed = 0.88

        if not text_to_speak:
            return web.json_response({"error": "No text provided"}, status=400)

        tts_models = ["gpt-4o-mini-tts", "tts-1-hd", "tts-1-hd-1106", "tts-1"]
        audio_b64 = None

        for model_name in tts_models:
            try:
                params = {
                    "model": model_name,
                    "voice": voice,
                    "input": text_to_speak,
                    "speed": speed,
                    "response_format": "mp3"
                }
                if "gpt-4o" in model_name and instructions:
                    params["instructions"] = instructions

                response = await client.audio.speech.create(**params)
                audio_bytes = response.content
                audio_b64 = base64.b64encode(audio_bytes).decode('utf-8')
                break
            except Exception as ex:
                pass

        if audio_b64:
            return web.json_response({"audio": f"data:audio/mp3;base64,{audio_b64}"})
        else:
            return web.json_response({"audio": None, "fallback": "speech_synthesis"}, status=200)
    except Exception as e:
        return web.json_response({"audio": None, "fallback": "speech_synthesis", "reason": str(e)}, status=200)

COMMON_DIGRAPHS = ["sh", "ch", "th", "wh", "ph", "ck", "qu", "ng", "ea", "ee", "oo", "ai", "oa", "ar", "or", "er", "ir", "ur"]

def segment_word_into_phonemes(word):
    """
    Segment a simple word into its constituent phonemes/digraphs for progressive successive blending.
    e.g. 'fish' -> ['f', 'i', 'sh']
         'cat'  -> ['c', 'a', 't']
         'sun'  -> ['s', 'u', 'n']
    """
    word = word.lower().strip()
    phonemes = []
    i = 0
    while i < len(word):
        matched = False
        for dg in COMMON_DIGRAPHS:
            if word[i:].startswith(dg):
                phonemes.append(dg)
                i += len(dg)
                matched = True
                break
        if not matched:
            phonemes.append(word[i])
            i += 1
    return phonemes

def generate_progressive_steps(word):
    """
    Generates successive progressive phoneme blending steps.
    e.g. for 'fish':
      Step 1: /f/ (sound)
      Step 2: /f/ + /i/ = /fi/
      Step 3: /fi/ + /sh/ = /fish/
      Step 4: Say the whole word naturally: fish!
    """
    word = word.lower().strip()
    phonemes = segment_word_into_phonemes(word)
    steps = []

    if not phonemes:
        return []

    # Step 1: First Sound
    first_p = phonemes[0]
    steps.append({
        "step_num": 1,
        "type": "first_sound",
        "accumulated": first_p,
        "phoneme": f"/{first_p}/",
        "formula": f"/{first_p}/",
        "prompt": f"Listen to the first sound: /{first_p}/",
        "audio_text": f"Listen: /{first_p}/."
    })

    # Successive additions
    accum = first_p
    for idx in range(1, len(phonemes)):
        next_p = phonemes[idx]
        prev_accum = accum
        accum += next_p

        steps.append({
            "step_num": idx + 1,
            "type": "blend_step",
            "prev_chunk": prev_accum,
            "next_sound": next_p,
            "accumulated": accum,
            "formula": f"/{prev_accum}/ + /{next_p}/ → /{accum}/",
            "prompt": f"Connect the sounds: /{prev_accum}/ + /{next_p}/ → /{accum}/",
            "audio_text": f"/{prev_accum}/ plus /{next_p}/... /{accum}/."
        })

    # Final Whole Word
    steps.append({
        "step_num": len(steps) + 1,
        "type": "whole_word",
        "accumulated": word,
        "formula": f"Blend it all together: {word.upper()}!",
        "prompt": f"Now blend all the sounds together into one word: {word.upper()}!",
        "audio_text": f"Now blend it all together... {word}!"
    })

    return steps

async def progressive_blend_steps(request):
    """
    API endpoint returning progressive continuous phoneme blending steps for any word.
    """
    try:
        data = await request.json()
        word = (data.get("word") or "").strip().lower()
        if not word:
            return web.json_response({"error": "No word provided"}, status=400)

        steps = generate_progressive_steps(word)
        return web.json_response({
            "word": word,
            "phonemes": segment_word_into_phonemes(word),
            "steps": steps
        })
    except Exception as e:
        return web.json_response({"error": str(e)}, status=500)

def init_app():
    app = web.Application()

    cors = aiohttp_cors.setup(app, defaults={
        "*": aiohttp_cors.ResourceOptions(
            allow_credentials=True,
            expose_headers="*",
            allow_headers="*",
        )
    })

    route_index = app.router.add_get("/", index)
    route_session = app.router.add_post("/session", create_session)
    route_eval = app.router.add_post("/evaluate", evaluate)
    route_eval_inst = app.router.add_post("/evaluate_instructional", evaluate_instructional)
    route_breakdown = app.router.add_post("/breakdown_word", breakdown_word)
    route_tts = app.router.add_post("/tts_words", tts_words)
    route_transcribe = app.router.add_post("/transcribe", transcribe)
    route_phonics_eval = app.router.add_post("/phonics_letter_eval", phonics_letter_eval)
    route_phonics_tts = app.router.add_post("/tts_phoneme_guide", tts_phoneme_guide)
    route_prog_blend = app.router.add_post("/progressive_blend_steps", progressive_blend_steps)

    if os.path.exists("./static"):
        app.router.add_static("/static/", "./static/")

    cors.add(route_index)
    cors.add(route_session)
    cors.add(route_eval)
    cors.add(route_eval_inst)
    cors.add(route_breakdown)
    cors.add(route_tts)
    cors.add(route_transcribe)
    cors.add(route_phonics_eval)
    cors.add(route_phonics_tts)
    cors.add(route_prog_blend)

    return app

if __name__ == "__main__":
    app = init_app()
    port = int(os.environ.get("PORT", 8000))
    print(f"Starting Dynamic NLP Phonetic ASR Service on port {port}...")
    web.run_app(app, host="0.0.0.0", port=port)
