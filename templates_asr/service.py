import os
import re
import jellyfish
from aiohttp import web
import aiohttp_cors
from openai import AsyncOpenAI

def load_env_files():
    """
    Search common .env locations and set environment variables if missing.
    """
    candidates = [
        os.path.join(os.path.dirname(__file__), ".env"),
        "/var/www/andrew/AralProgram_ASR/.env/config.php",
        "/var/www/andrew/AralProgram_ASR/.env",
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
    return web.FileResponse("./static/index.html") if os.path.exists("./static/index.html") else web.Response(text="ASR Service Running")

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

def align_and_score(expected_text: str, spoken_text: str):
    """
    Align target passage tokens with spoken transcript tokens using Jellyfish Jaro-Winkler similarity.
    3-Tier Classification:
    - Green (Good): similarity >= 0.92 (Full credit 1.0)
    - Yellow (Slight Improvement): 0.75 <= similarity < 0.92 (Partial credit 0.5)
    - Red (Miscue / Poor): similarity < 0.75 (No credit 0.0)
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
            sim = jellyfish.jaro_winkler_similarity(exp_word, spk_word)
            if sim > best_sim:
                best_sim = sim
                best_match_idx = idx

        if best_match_idx != -1:
            matched_spoken_word = spoken_words[best_match_idx]
            if best_sim >= 0.92:
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
    weighted_score = good_count + (0.5 * improvement_count)
    accuracy = round(max(0.0, (weighted_score / total_words) * 100.0), 2) if total_words > 0 else 100.0

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

        # 1. Evaluate Reading Accuracy using 3-tier Jaro-Winkler matching
        reading_res = align_and_score(passage, transcript)
        accuracy_score = reading_res["accuracy_score"]

        # 2. Evaluate Comprehension Score
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

        # 3. Calculate Composite Grade
        composite_grade = round((accuracy_score * 0.6) + (comprehension_score * 0.4), 2)

        response_payload = {
            "accuracy_score": accuracy_score,
            "comprehension_score": comprehension_score,
            "final_grade": composite_grade,
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
    route_transcribe = app.router.add_post("/transcribe", transcribe)

    if os.path.exists("./static"):
        app.router.add_static("/static/", "./static/")

    cors.add(route_index)
    cors.add(route_session)
    cors.add(route_eval)
    cors.add(route_transcribe)

    return app

if __name__ == "__main__":
    app = init_app()
    port = int(os.environ.get("PORT", 8000))
    print(f"Starting ASR & Scoring Service on port {port}...")
    web.run_app(app, host="0.0.0.0", port=port)
