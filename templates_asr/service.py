"""
Unified Streaming ASR & Acoustic Reading Assessment Service (v5.0)
==================================================================
AZURE EDITION — replaces Wav2Vec2 + OpenAI with Azure Cognitive Services Speech.

ASR Engine: Azure Speech SDK (PronunciationAssessmentConfig)
  - Real-time streaming ASR with per-phoneme acoustic scores
  - Acoustic OMISSION/INSERTION/MISPRONUNCIATION detection
  - No local GPU/model download required

TTS Engine: Azure Text-to-Speech (Neural voices via Azure Speech SDK)
  - Replaces OpenAI TTS for word coaching audio

Architecture unchanged:
  - WebSocket /ws/stream — receives raw PCM audio chunks from browser
  - /evaluate, /evaluate_instructional, /phoneme_evaluate — REST scoring
  - /tts_words, /tts_phoneme_guide — Azure TTS coaching audio
  - Phoneme alignment, fluency detection, VAD logic retained
"""

import asyncio
import base64
import difflib
import io
import json
import logging
import os
import re
import shutil
import string
import tempfile
import threading
import time
import uuid
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple

try:
    import jellyfish
except ImportError:
    class JellyfishFallback:
        @staticmethod
        def jaro_winkler_similarity(s1, s2):
            if s1 == s2: return 1.0
            l1, l2 = len(s1), len(s2)
            if l1 == 0 or l2 == 0: return 0.0
            match_bound = max(l1, l2) // 2 - 1
            s1_matches = [False] * l1
            s2_matches = [False] * l2
            matches = 0
            for i in range(l1):
                start = max(0, i - match_bound)
                end = min(i + match_bound + 1, l2)
                for j in range(start, end):
                    if s2_matches[j] or s1[i] != s2[j]: continue
                    s1_matches[i] = True
                    s2_matches[j] = True
                    matches += 1
                    break
            if matches == 0: return 0.0
            trans = 0
            k = 0
            for i in range(l1):
                if not s1_matches[i]: continue
                while not s2_matches[k]: k += 1
                if s1[i] != s2[k]: trans += 1
                k += 1
            weight = (matches / l1 + matches / l2 + (matches - trans / 2) / matches) / 3.0
            p = 0.1
            prefix = 0
            for i in range(min(4, min(l1, l2))):
                if s1[i] == s2[i]: prefix += 1
                else: break
            return weight + prefix * p * (1.0 - weight)

        @staticmethod
        def metaphone(word):
            if not word: return ""
            return word.upper()[:4]

    jellyfish = JellyfishFallback()

import numpy as np
from fastapi import FastAPI, HTTPException, WebSocket, WebSocketDisconnect
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import HTMLResponse, JSONResponse

try:
    import azure.cognitiveservices.speech as speechsdk
    AZURE_SDK_AVAILABLE = True
except ImportError:
    AZURE_SDK_AVAILABLE = False
    speechsdk = None

try:
    import pyphen
    PYPHEN_DIC = pyphen.Pyphen(lang='en_US')
except Exception:
    PYPHEN_DIC = None

try:
    import eng_to_ipa as ipa_lib
except Exception:
    ipa_lib = None

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
)
logger = logging.getLogger("streamASR")

# ============================================================
# CONFIGURATION CONSTANTS
# ============================================================

# Load .env files before reading constants
def load_env_files():
    """Load environment variables from common .env paths."""
    candidates = [
        os.path.join(os.path.dirname(__file__), ".env"),
        os.path.join(os.path.dirname(__file__), "../venv_asr/.env"),
        "/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/.env",
        "/var/www/andrew/.env",
        os.path.expanduser("~/.env"),
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
                            if k and v:
                                os.environ[k] = v
            except Exception:
                pass

load_env_files()

APP_HOST = os.getenv("STREAM_HOST", "0.0.0.0")
APP_PORT = int(os.getenv("STREAM_PORT", os.getenv("PORT", "8010")))
SAMPLE_RATE = 16000
DEBUG_ACOUSTIC = os.getenv("DEBUG_ACOUSTIC", "false").lower() in ("true", "1")

# Azure Speech API
AZURE_KEY    = os.environ.get("AZURE_SPEECH_KEY", os.environ.get("RA_AZURE_KEY", ""))
AZURE_REGION = os.environ.get("AZURE_SPEECH_REGION", os.environ.get("RA_AZURE_REGION", "southeastasia"))
AZURE_LANG   = os.environ.get("AZURE_SPEECH_LANGUAGE", "en-US")

# Audio format from browser (WebM/Opus via MediaRecorder)
AUDIO_FORMAT = os.getenv("AUDIO_FORMAT", "webm")  # "webm" or "pcm"

# Resource limits
MAX_SESSIONS             = int(os.getenv("MAX_SESSIONS", "50"))
WEBSOCKET_IDLE_TIMEOUT_SEC = float(os.getenv("WEBSOCKET_IDLE_TIMEOUT_SEC", "120.0"))
MAX_ATTEMPT_DURATION_MS  = int(os.getenv("MAX_ATTEMPT_DURATION_MS", "120000"))

# VAD (used for sending feedback even though Azure handles speech detection)
SILENCE_RMS_THRESHOLD = 0.0045
FRAME_MS              = 20
FRAME_SIZE            = int(SAMPLE_RATE * (FRAME_MS / 1000.0))

# Scoring thresholds
MASTER_THRESHOLD      = 0.80
ACCEPTABLE_THRESHOLD  = 0.65
BLENDING_PASS_THRESHOLD = 0.80

# Azure error-type → internal miscue type mapping
AZURE_ERROR_TYPE_MAP = {
    "None":             "CORRECT",
    "Omission":         "OMISSION",
    "Insertion":        "INSERTION",
    "Mispronunciation": "MISPRONUNCIATION",
}

BASE_DIR = Path(__file__).resolve().parent


def load_env_files():
    """Load environment variables from common .env paths."""
    candidates = [
        os.path.join(os.path.dirname(__file__), ".env"),
        os.path.join(os.path.dirname(__file__), "../venv_asr/.env"),
        "/var/www/andrew/moodle/moodle/mod/readingassessment/venv_asr/.env",
        "/var/www/andrew/.env",
        os.path.expanduser("~/.env"),
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
                            if k and v:
                                os.environ[k] = v
            except Exception:
                pass


load_env_files()

# Re-read after loading .env
AZURE_KEY    = os.environ.get("AZURE_SPEECH_KEY", os.environ.get("RA_AZURE_KEY", ""))
AZURE_REGION = os.environ.get("AZURE_SPEECH_REGION", os.environ.get("RA_AZURE_REGION", "southeastasia"))
AZURE_LANG   = os.environ.get("AZURE_SPEECH_LANGUAGE", "en-US")


# ============================================================
# FASTAPI APP & CORS
# ============================================================

app = FastAPI(
    title="Streaming ASR & Reading Assessment Service — Azure Edition",
    version="5.0.0",
)

app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)


# ============================================================
# AZURE SPEECH SESSION
# ============================================================

class AzureSession:
    """
    Wraps an Azure SpeechRecognizer with PronunciationAssessmentConfig
    for one student reading attempt.

    Audio is pushed via PushAudioInputStream so we can stream
    browser WebM/PCM bytes directly without writing to disk.
    """

    def __init__(
        self,
        session_id: str,
        passage_text: str,
        azure_key: str,
        azure_region: str,
        azure_lang: str = "en-US",
    ):
        self.session_id    = session_id
        self.passage_text  = passage_text
        self.azure_key     = azure_key
        self.azure_region  = azure_region
        self.azure_lang    = azure_lang

        # Recognition results aggregated here
        self.word_results:       List[Dict[str, Any]] = []
        self.utterance_scores:   List[Dict[str, Any]] = []
        self.recognized_text:    str = ""
        self.state:              str = "idle"  # idle / recognizing / done / error
        self._event_loop:        Optional[asyncio.AbstractEventLoop] = None
        self.ws_queue:           Optional[asyncio.Queue] = None

        # Azure SDK objects (created in start())
        self._speech_config:     Optional[Any] = None
        self._push_stream:       Optional[Any] = None
        self._audio_config:      Optional[Any] = None
        self._recognizer:        Optional[Any] = None

        self._next_passage_idx:  int = 0

    def start(self, loop: asyncio.AbstractEventLoop, ws_queue: asyncio.Queue) -> None:
        """Initialize Azure recognizer and start continuous recognition."""
        if not AZURE_SDK_AVAILABLE:
            raise RuntimeError("azure-cognitiveservices-speech not installed.")
        if not self.azure_key:
            raise RuntimeError("AZURE_SPEECH_KEY not set.")

        self._event_loop = loop
        self.ws_queue    = ws_queue

        # Speech config
        self._speech_config = speechsdk.SpeechConfig(
            subscription=self.azure_key,
            region=self.azure_region,
        )
        self._speech_config.speech_recognition_language = self.azure_lang

        # Pronunciation assessment
        pron_config = speechsdk.PronunciationAssessmentConfig(
            reference_text=self.passage_text,
            grading_system=speechsdk.PronunciationAssessmentGradingSystem.HundredMark,
            granularity=speechsdk.PronunciationAssessmentGranularity.Phoneme,
            enable_miscue=True,
        )
        try:
            pron_config.enable_prosody_assessment()
        except Exception:
            pass

        # 16kHz Mono 16-bit PCM stream format
        stream_format = speechsdk.audio.AudioStreamFormat(
            samples_per_second=16000,
            bits_per_sample=16,
            channels=1
        )
        self._push_stream = speechsdk.audio.PushAudioInputStream(stream_format=stream_format)

        self._audio_config = speechsdk.audio.AudioConfig(stream=self._push_stream)

        self._recognizer = speechsdk.SpeechRecognizer(
            speech_config=self._speech_config,
            audio_config=self._audio_config,
        )
        pron_config.apply_to(self._recognizer)

        # Callbacks (called from Azure SDK C++ thread — must use thread-safe bridge)
        self._recognizer.recognized.connect(self._on_recognized)
        self._recognizer.session_stopped.connect(self._on_stopped)
        self._recognizer.canceled.connect(self._on_canceled)

        self._recognizer.start_continuous_recognition_async()
        self.state = "recognizing"
        logger.info(f"session={self.session_id} event=azure_recognition_started region={self.azure_region}")

    def push_audio(self, audio_bytes: bytes) -> None:
        """Feed raw audio bytes into the Azure push stream (handling both Int16 PCM and Float32 PCM)."""
        if not self._push_stream or self.state != "recognizing":
            return
        try:
            if len(audio_bytes) > 0 and len(audio_bytes) % 4 == 0:
                float_samples = np.frombuffer(audio_bytes, dtype=np.float32)
                # Only convert if sample values fall in standard Float32 audio range [-1.0, 1.0]
                if len(float_samples) > 0 and np.max(np.abs(float_samples)) <= 1.0:
                    int16_samples = (np.clip(float_samples, -1.0, 1.0) * 32767.0).astype(np.int16)
                    self._push_stream.write(int16_samples.tobytes())
                    return
            self._push_stream.write(audio_bytes)
        except Exception as err:
            logger.warning(f"session={self.session_id} push_audio error: {err}")

    def stop(self) -> None:
        """Signal end of audio and stop recognition."""
        if self._push_stream:
            self._push_stream.close()
        if self._recognizer and self.state == "recognizing":
            self._recognizer.stop_continuous_recognition_async()
        self.state = "done"

    def _align_miscues_with_reference(self, raw_word_results: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
        """
        Official Microsoft Azure Speech SDK Reference-Text Alignment Algorithm
        Adapted for Real-time Continuous Recognition Streaming.
        
        Aligns recognized words from the current audio utterance against a local window of reference
        passage text starting from self._next_passage_idx.
        Does NOT synthesize Omission miscues for trailing unread passage words past the end
        of the current spoken utterance chunk.
        """
        if not self.passage_text or not raw_word_results:
            return raw_word_results

        ref_words_all = [w.strip(string.punctuation).lower() for w in self.passage_text.split() if w.strip(string.punctuation)]
        rec_words_clean = [w.get("word", "").strip(string.punctuation).lower() for w in raw_word_results]

        # Enforce Bounded Local Search Window to prevent short words (e.g., 'a', 'the') matching distant sentences
        search_window_size = max(len(rec_words_clean) + 3, 6)
        ref_words_slice = ref_words_all[self._next_passage_idx : self._next_passage_idx + search_window_size]

        if not ref_words_slice:
            ref_words_slice = ref_words_all[self._next_passage_idx:]
        if not ref_words_slice:
            ref_words_slice = ref_words_all
            self._next_passage_idx = 0

        matcher = difflib.SequenceMatcher(None, ref_words_slice, rec_words_clean)
        final_words = []
        max_matched_ref_idx = -1

        for tag, i1, i2, j1, j2 in matcher.get_opcodes():
            if tag in ('insert', 'replace'):
                for idx_offset, w in enumerate(raw_word_results[j1:j2]):
                    w_copy = dict(w)
                    ref_idx = i1 + idx_offset
                    if ref_idx < len(ref_words_slice):
                        actual_idx = self._next_passage_idx + ref_idx
                        w_copy["passage_idx"] = actual_idx
                        max_matched_ref_idx = max(max_matched_ref_idx, ref_idx)

                    acc = float(w_copy.get("accuracy_score", 100.0) or 100.0)
                    err = w_copy.get("error_type", "None")
                    if err == "None" or acc >= 60.0:
                        w_copy["is_miscue"] = False
                        w_copy["miscue_type"] = "NONE"
                    else:
                        w_copy["is_miscue"] = True
                        w_copy["miscue_type"] = "INSERTION" if tag == 'insert' else "MISPRONUNCIATION"
                        if err == "None":
                            w_copy["error_type"] = "Insertion" if tag == 'insert' else "Mispronunciation"
                    final_words.append(w_copy)

            if tag == 'delete':
                # Convert to Omission ONLY if there are spoken words following this gap in the current utterance chunk
                if j1 < len(rec_words_clean):
                    for ref_offset in range(i1, i2):
                        actual_idx = self._next_passage_idx + ref_offset
                        ref_word_text = ref_words_all[actual_idx] if actual_idx < len(ref_words_all) else ""
                        final_words.append({
                            "word": ref_word_text,
                            "passage_idx": actual_idx,
                            "miscue_type": "OMISSION",
                            "is_miscue": True,
                            "pronunciation_score": 0.0,
                            "accuracy_score": 0.0,
                            "error_type": "Omission",
                            "phoneme_results": []
                        })
                        max_matched_ref_idx = max(max_matched_ref_idx, ref_offset)

            if tag == 'equal':
                for idx_offset, w in enumerate(raw_word_results[j1:j2]):
                    w_copy = dict(w)
                    ref_idx = i1 + idx_offset
                    if ref_idx < len(ref_words_slice):
                        actual_idx = self._next_passage_idx + ref_idx
                        w_copy["passage_idx"] = actual_idx
                        max_matched_ref_idx = max(max_matched_ref_idx, ref_idx)
                    w_copy["is_miscue"] = False
                    w_copy["miscue_type"] = "NONE"
                    final_words.append(w_copy)

        if max_matched_ref_idx >= 0:
            self._next_passage_idx += (max_matched_ref_idx + 1)

        return final_words

    def _on_recognized(self, evt) -> None:
        """Called by Azure SDK when a recognition result is ready (C++ thread)."""
        result = evt.result
        if result.reason != speechsdk.ResultReason.RecognizedSpeech:
            return

        pron_result = speechsdk.PronunciationAssessmentResult(result)
        scores = {
            "accuracy_score":     pron_result.accuracy_score,
            "fluency_score":      pron_result.fluency_score,
            "completeness_score": pron_result.completeness_score,
            "pron_score":         pron_result.pronunciation_score,
        }
        self.utterance_scores.append(scores)
        self.recognized_text += " " + result.text

        raw_word_results = []
        for w in pron_result.words:
            wr = self._parse_word(w)
            if wr:
                raw_word_results.append(wr)

        aligned_words = self._align_miscues_with_reference(raw_word_results)
        word_results = []
        for aw in aligned_words:
            self.word_results.append(aw)
            word_results.append(aw)

        if self._event_loop and self.ws_queue is not None:
            text_clean = result.text.strip()
            msg = {
                "type":       "partial",
                "text":       text_clean,
                "scores":     scores,
                "word_results": word_results,
                "result": {
                    "text": text_clean,
                    "detected": {
                        "raw": text_clean,
                        "transcript": text_clean,
                    },
                    "words": word_results,
                    "scores": scores,
                }
            }
            asyncio.run_coroutine_threadsafe(self.ws_queue.put(msg), self._event_loop)

    def _on_stopped(self, evt) -> None:
        self.state = "done"
        if self._event_loop and self.ws_queue is not None:
            asyncio.run_coroutine_threadsafe(self.ws_queue.put({"type": "done"}), self._event_loop)

    def _on_canceled(self, evt) -> None:
        logger.warning(f"session={self.session_id} event=azure_canceled reason={evt.reason}")
        self.state = "error"
        if self._event_loop and self.ws_queue is not None:
            asyncio.run_coroutine_threadsafe(
                self.ws_queue.put({"type": "error", "reason": str(evt.reason)}),
                self._event_loop
            )

    def _parse_word(self, azure_word) -> Optional[Dict[str, Any]]:
        """Parse raw Azure Speech SDK WordResult object into dictionary."""
        try:
            accuracy = float(azure_word.accuracy_score) if hasattr(azure_word, "accuracy_score") else 0.0
            error_type = getattr(azure_word, "error_type", "None") or "None"
            miscue_type = AZURE_ERROR_TYPE_MAP.get(error_type, "NONE")
            is_miscue = error_type in ("Mispronunciation", "Omission") or accuracy < 60.0

            # Phoneme results
            phoneme_results = []
            for ph in (azure_word.phonemes or []):
                ph_acc = float(ph.accuracy_score) if hasattr(ph, "accuracy_score") else 0.0
                if ph_acc < 20.0:
                    ph_result = "OMISSION"
                elif ph_acc < 60.0:
                    ph_result = "MISPRONUNCIATION"
                else:
                    ph_result = "CORRECT"
                phoneme_results.append({
                    "phoneme": ph.phoneme,
                    "accuracy": round(ph_acc, 1),
                    "result":   ph_result,
                })

            return {
                "word":               azure_word.word,
                "passage_idx":        None,
                "miscue_type":        miscue_type,
                "is_miscue":          is_miscue,
                "pronunciation_score": round(accuracy / 100.0, 4),
                "accuracy_score":     round(accuracy, 1),
                "error_type":         error_type,
                "phoneme_results":    phoneme_results,
                "word_start":         None,
                "word_end":           None,
            }
        except Exception as e:
            logger.error(f"session={self.session_id} _parse_word error: {e}")
            return None

    def get_final_assessment(self) -> Dict[str, Any]:
        """Build the final assessment dict from accumulated Azure results with SequenceMatcher miscue alignment."""
        import difflib
        import string

        ref_words = [w.strip(string.punctuation).lower() for w in self.passage_text.split() if w.strip(string.punctuation)]
        rec_words = [w for w in self.word_results]

        if ref_words and rec_words:
            rec_word_texts = [w["word"].lower().strip(string.punctuation) for w in rec_words]
            matcher = difflib.SequenceMatcher(None, ref_words, rec_word_texts)
            aligned_words = []
            for tag, i1, i2, j1, j2 in matcher.get_opcodes():
                if tag == 'replace':
                    for idx_offset, w in enumerate(rec_words[j1:j2]):
                        w_copy = dict(w)
                        acc = float(w_copy.get("accuracy_score", 100.0) or 100.0)
                        err = w_copy.get("error_type", "None")
                        if err == "None" or acc >= 60.0:
                            w_copy["is_miscue"] = False
                            w_copy["miscue_type"] = "NONE"
                        else:
                            w_copy["is_miscue"] = True
                            w_copy["miscue_type"] = "MISPRONUNCIATION"
                        aligned_words.append(w_copy)
                elif tag == 'insert':
                    for w in rec_words[j1:j2]:
                        w_copy = dict(w)
                        w_copy["is_miscue"] = True
                        w_copy["miscue_type"] = "INSERTION"
                        aligned_words.append(w_copy)
                elif tag == 'delete':
                    for ref_w in ref_words[i1:i2]:
                        aligned_words.append({
                            "word": ref_w,
                            "passage_idx": None,
                            "miscue_type": "OMISSION",
                            "is_miscue": True,
                            "pronunciation_score": 0.0,
                            "accuracy_score": 0.0,
                            "error_type": "Omission",
                            "phoneme_results": [],
                            "word_start": None,
                            "word_end": None,
                        })
                elif tag == 'equal':
                    for w in rec_words[j1:j2]:
                        w_copy = dict(w)
                        w_copy["is_miscue"] = False
                        w_copy["miscue_type"] = "NONE"
                        aligned_words.append(w_copy)
            words = aligned_words
        else:
            words = rec_words

        n_words    = len([w for w in words if w["miscue_type"] != "INSERTION"])
        n_miscues  = len([w for w in words if w["is_miscue"]])
        n_correct  = max(0, n_words - n_miscues)
        accuracy   = round((n_correct / n_words) * 100.0, 2) if n_words > 0 else 0.0

        # Mean utterance scores
        mean_acc   = round(np.mean([s["accuracy_score"]     for s in self.utterance_scores]), 1) if self.utterance_scores else 0.0
        mean_flu   = round(np.mean([s["fluency_score"]      for s in self.utterance_scores]), 1) if self.utterance_scores else 0.0
        mean_comp  = round(np.mean([s["completeness_score"] for s in self.utterance_scores]), 1) if self.utterance_scores else 0.0
        mean_pron  = round(np.mean([s["pron_score"]         for s in self.utterance_scores]), 1) if self.utterance_scores else 0.0

        mastery = accuracy >= (MASTER_THRESHOLD * 100)
        status  = "MASTER" if mastery else ("ACCEPTABLE" if accuracy >= ACCEPTABLE_THRESHOLD * 100 else "NEEDS_PRACTICE")

        rec_text = self.recognized_text.strip()
        return {
            "status":   status,
            "mastery":  mastery,
            "detected": {
                "raw": rec_text,
                "transcript": rec_text,
            },
            "scores": {
                "reading_accuracy":  accuracy,
                "word_accuracy":     round(accuracy / 100.0, 4),
                "phoneme_accuracy":  round(mean_acc / 100.0, 4),
                "azure_accuracy":    mean_acc,
                "azure_fluency":     mean_flu,
                "azure_completeness": mean_comp,
                "azure_pron":        mean_pron,
                "overall_score":     mean_pron,
            },
            "words":        words,
            "recognized":   rec_text,
            "word_count":   n_words,
            "miscue_count": n_miscues,
            "correct_count": n_correct,
        }


# Thread-safe session registry
session_registry: Dict[str, Any] = {}
registry_lock = asyncio.Lock()


# ============================================================
# PHONOLOGICAL HELPERS (unchanged from v4.0)
# ============================================================

FUNCTION_WORD_VARIANTS: Dict[str, List[List[str]]] = {
    "a": [["ə"], ["ʌ"], ["æ"], ["eɪ"], ["a"]],
    "the": [["ð", "ə"], ["ð", "iː"], ["ð", "ɪ"], ["d", "ə"], ["d", "iː"]],
    "an": [["ə", "n"], ["æ", "n"], ["ʌ", "n"]],
    "to": [["t", "ə"], ["t", "uː"], ["t", "ʊ"]],
    "of": [["ə", "v"], ["ʌ", "v"], ["ɒ", "v"], ["a", "v"]],
    "and": [["ə", "n", "d"], ["ə", "n"], ["æ", "n", "d"], ["n", "d"]],
    "was": [["w", "ə", "z"], ["w", "ʌ", "z"], ["w", "ɒ", "z"]],
    "for": [["f", "ə", "r"], ["f", "ɔː", "r"], ["f", "ɚ"]],
    "is": [["ɪ", "z"], ["ə", "z"], ["iː", "z"]],
    "in": [["ɪ", "n"], ["ə", "n"], ["iː", "n"]],
    "it": [["ɪ", "t"], ["ə", "t"]],
    "at": [["æ", "t"], ["ə", "t"]],
    "on": [["ɒ", "n"], ["ɑː", "n"], ["ɔː", "n"]],
    "as": [["æ", "z"], ["ə", "z"]],
    "with": [["w", "ɪ", "ð"], ["w", "ɪ", "θ"]],
    "that": [["ð", "æ", "t"], ["ð", "ə", "t"]],
    "from": [["f", "r", "ɒ", "m"], ["f", "r", "ə", "m"]],
}


class PronunciationProfile:
    VOWEL_EQUIVALENCES = {
        "æ": {"æ": 0.0, "a": 0.0, "ɐ": 0.0, "ə": 0.05, "e": 0.10},
        "ʌ": {"ʌ": 0.0, "a": 0.0, "ɐ": 0.0, "ə": 0.0, "u": 0.10},
        "ɑː": {"ɑː": 0.0, "a": 0.0, "oʊ": 0.05, "ɔː": 0.0, "ɒ": 0.0},
        "iː": {"iː": 0.0, "i": 0.0, "ɪ": 0.0, "eɪ": 0.05},
        "uː": {"uː": 0.0, "u": 0.0, "ʊ": 0.0, "oʊ": 0.05},
        "ə": {"ə": 0.0, "ʌ": 0.0, "a": 0.0, "eɪ": 0.0, "æ": 0.0, "ɪ": 0.05},
        "eɪ": {"eɪ": 0.0, "e": 0.0, "ɛ": 0.05, "ə": 0.0, "a": 0.0},
        "ɪ": {"ɪ": 0.0, "i": 0.0, "iː": 0.0, "ə": 0.05},
        "ɛ": {"ɛ": 0.0, "e": 0.0, "æ": 0.05, "eɪ": 0.05},
        "ɔː": {"ɔː": 0.0, "ɒ": 0.0, "ɑː": 0.0, "oʊ": 0.05},
        "oʊ": {"oʊ": 0.0, "o": 0.0, "ɔː": 0.05},
        "aɪ": {"aɪ": 0.0, "a": 0.05, "i": 0.05},
        "aʊ": {"aʊ": 0.0, "oʊ": 0.05},
    }
    RHOTIC_EQUIVALENCES = {
        "ɹ": {"ɹ": 0.0, "r": 0.0, "ɾ": 0.0, "ɚ": 0.0},
        "r": {"r": 0.0, "ɹ": 0.0, "ɾ": 0.0, "ɚ": 0.0},
        "ɚ": {"ɚ": 0.0, "ɹ": 0.0, "r": 0.0, "ə": 0.0, "ɝ": 0.0},
        "ɝ": {"ɝ": 0.0, "ɚ": 0.0, "ɹ": 0.0, "r": 0.0, "ə": 0.0},
    }
    DEVELOPMENTAL_EQUIVALENCES = {
        "f": {"f": 0.0, "p": 0.05},
        "v": {"v": 0.0, "b": 0.05},
        "θ": {"θ": 0.0, "t": 0.05, "s": 0.05, "f": 0.05},
        "ð": {"ð": 0.0, "d": 0.05, "z": 0.05, "v": 0.05},
        "ʃ": {"ʃ": 0.0, "s": 0.05, "tʃ": 0.05},
        "tʃ": {"tʃ": 0.0, "ts": 0.05, "ʃ": 0.05},
        "dʒ": {"dʒ": 0.0, "dz": 0.05, "j": 0.05},
    }

    @classmethod
    def check_compatibility(cls, target: str, detected: str) -> Tuple[bool, str, float]:
        if target == detected:
            return True, "EXACT", 0.0
        if target in cls.VOWEL_EQUIVALENCES and detected in cls.VOWEL_EQUIVALENCES[target]:
            return True, "VOWEL_REDUCTION_VARIATION", cls.VOWEL_EQUIVALENCES[target][detected]
        if target in cls.RHOTIC_EQUIVALENCES and detected in cls.RHOTIC_EQUIVALENCES[target]:
            return True, "RHOTIC_VARIATION", cls.RHOTIC_EQUIVALENCES[target][detected]
        if target in cls.DEVELOPMENTAL_EQUIVALENCES and detected in cls.DEVELOPMENTAL_EQUIVALENCES[target]:
            return True, "DEVELOPMENTAL_TOLERANCE", cls.DEVELOPMENTAL_EQUIVALENCES[target][detected]
        return False, "MISMATCH", 1.0


def normalize_phoneme_symbol(symbol: str) -> str:
    if not symbol:
        return ""
    symbol = str(symbol).strip()
    for ch in ["ˈ", "ˌ", "▁", "<unk>", "#"]:
        symbol = symbol.replace(ch, "")
    return symbol


def is_vowel_phoneme(phone: str) -> bool:
    return normalize_phoneme_symbol(phone) in {
        "æ", "ʌ", "ɑː", "iː", "uː", "ə", "a", "e", "i", "o", "u",
        "ɪ", "ʊ", "ɛ", "ɔː", "ɒ", "eɪ", "aɪ", "ɔɪ", "aʊ", "oʊ", "ɚ", "ɝ", "ɐ",
    }


def dynamic_syllabify_word(word: str) -> Tuple[int, List[List[str]], List[str], List[int]]:
    """Syllabify a word using pyphen or basic vowel counting."""
    clean_w = re.sub(r"[^a-zA-Z']", "", word.lower())
    if not clean_w:
        return 1, [[]], [], [1]

    if clean_w in FUNCTION_WORD_VARIANTS:
        phones = FUNCTION_WORD_VARIANTS[clean_w][0]
        return 1, [phones], phones, [0 if clean_w in {"a", "the", "to", "of", "and"} else 1]

    # Use pyphen for syllable count
    if PYPHEN_DIC:
        try:
            hyphenated = PYPHEN_DIC.inserted(clean_w)
            syllables_text = hyphenated.split("-")
            syl_count = max(1, len(syllables_text))
        except Exception:
            syl_count = 1
    else:
        vowels = re.findall(r"[aeiouAEIOU]", clean_w)
        syl_count = max(1, len(vowels))

    # Approximate IPA using eng_to_ipa if available
    if ipa_lib:
        try:
            ipa_str = ipa_lib.convert(clean_w)
            phones = [c for c in ipa_str if c.strip() and c not in {"ˈ", "ˌ"}]
        except Exception:
            phones = list(clean_w)
    else:
        phones = list(clean_w)

    stress = [1 if i == 0 else 0 for i in range(syl_count)]
    return syl_count, [phones], phones, stress


def get_target_info(text: str) -> Dict[str, Any]:
    text = text.strip()
    if not text:
        raise ValueError("Target text cannot be empty.")

    words = re.findall(r"[a-zA-Z']+", text)
    if not words:
        words = [text]

    word_details = []
    all_phonemes = []
    phoneme_cursor = 0

    for idx, w in enumerate(words):
        s_count, syllables, phones, stress_pat = dynamic_syllabify_word(w)
        span_start = phoneme_cursor
        span_end   = phoneme_cursor + len(phones)
        variants   = FUNCTION_WORD_VARIANTS.get(w.lower(), [phones])

        word_details.append({
            "index":            idx,
            "word":             w,
            "syllables":        syllables,
            "syllable_count":   s_count,
            "phonemes":         phones,
            "phoneme_count":    len(phones),
            "stress":           stress_pat,
            "variants":         variants,
            "start_phoneme_idx": span_start,
            "end_phoneme_idx":  span_end,
        })
        all_phonemes.extend(phones)
        phoneme_cursor = span_end

    return {
        "text":          text,
        "is_sentence":   len(words) > 1,
        "word_count":    len(words),
        "words":         word_details,
        "phonemes":      all_phonemes,
        "total_phonemes": len(all_phonemes),
    }


# ============================================================
# WEBSOCKET — STREAMING ASR
# ============================================================

@app.websocket("/ws/stream")
@app.websocket("/ws")
async def websocket_stream_endpoint(websocket: WebSocket):
    await websocket.accept()

    conn_id     = str(uuid.uuid4())
    session_id  = str(uuid.uuid4())
    azure_session: Optional[AzureSession] = None
    ws_queue    = asyncio.Queue()
    loop        = asyncio.get_running_loop()
    attempt_history: List[Dict[str, Any]] = []
    current_target  = "The quick brown fox jumps over the lazy dog"

    logger.info(f"session={session_id} connection={conn_id} event=client_connected")

    async def _pump_queue():
        """Forward queued Azure recognition messages to the client WebSocket."""
        while True:
            try:
                msg = await asyncio.wait_for(ws_queue.get(), timeout=1.0)
                if msg.get("type") == "done":
                    break
                await websocket.send_json(msg)
            except asyncio.TimeoutError:
                continue
            except Exception:
                break

    pump_task: Optional[asyncio.Task] = None

    try:
        while True:
            try:
                message = await asyncio.wait_for(
                    websocket.receive(), timeout=WEBSOCKET_IDLE_TIMEOUT_SEC
                )
            except asyncio.TimeoutError:
                await websocket.send_json({"type": "timeout", "message": "Session closed due to inactivity."})
                break

            if message.get("type") == "websocket.disconnect":
                break

            # ── Text control messages ──────────────────────────────────────────
            if "text" in message and message["text"]:
                try:
                    data    = json.loads(message["text"])
                    msg_type = data.get("type", "")

                    if msg_type in {"start", "init", "start_attempt", "config"}:
                        target_text  = (
                            data.get("target") or data.get("target_text") or
                            data.get("text")   or data.get("word") or current_target
                        )
                        current_target = target_text.strip()
                        attempt_id     = data.get("attempt_id", 1)
                        is_retry       = bool(data.get("is_retry", attempt_id > 1))

                        # Clean up previous Azure session
                        if azure_session:
                            try:
                                azure_session.stop()
                            except Exception:
                                pass
                        if pump_task:
                            pump_task.cancel()

                        ws_queue   = asyncio.Queue()
                        azure_session = AzureSession(
                            session_id=session_id,
                            passage_text=current_target,
                            azure_key=AZURE_KEY,
                            azure_region=AZURE_REGION,
                            azure_lang=AZURE_LANG,
                        )
                        try:
                            azure_session.start(loop, ws_queue)
                        except Exception as e:
                            await websocket.send_json({"type": "error", "message": str(e)})
                            break

                        async with registry_lock:
                            session_registry[session_id] = azure_session

                        pump_task = asyncio.create_task(_pump_queue())

                        ready_type = "attempt_ready" if msg_type == "start_attempt" else "ready"
                        await websocket.send_json({
                            "type":        ready_type,
                            "session_id":  session_id,
                            "attempt_id":  attempt_id,
                            "is_retry":    is_retry,
                            "target":      get_target_info(current_target),
                            "message":     f"Session ready for: '{current_target}'",
                            "engine":      "azure",
                            "azure_region": AZURE_REGION,
                        })

                    elif msg_type == "stop":
                        if azure_session:
                            azure_session.stop()
                            # Wait briefly for final recognition
                            await asyncio.sleep(0.5)
                            if pump_task:
                                await asyncio.wait_for(pump_task, timeout=5.0)

                            final_result = azure_session.get_final_assessment()
                            attempt_history.append({
                                "session_id":  session_id,
                                "attempt_id":  data.get("attempt_id", 1),
                                "target":      current_target,
                                "status":      final_result.get("status"),
                                "mastery":     final_result.get("mastery", False),
                                "overall_score": final_result.get("scores", {}).get("overall_score", 0.0),
                                "timestamp":   time.time(),
                            })
                            await websocket.send_json({
                                "type":            "final",
                                "result":          final_result,
                                "attempt_history": attempt_history,
                                "auto_stopped":    False,
                            })

                    elif msg_type in {"reset_history", "clear_history"}:
                        attempt_history.clear()
                        await websocket.send_json({
                            "type":       "history_reset",
                            "session_id": session_id,
                            "message":    "Attempt history reset.",
                        })

                except json.JSONDecodeError:
                    pass

            # ── Binary audio data ──────────────────────────────────────────────
            elif "bytes" in message and message["bytes"]:
                if azure_session and azure_session.state == "recognizing":
                    azure_session.push_audio(message["bytes"])
                    # Basic energy feedback to client
                    await websocket.send_json({
                        "type":       "vad",
                        "is_speech":  True,
                        "bytes_recv": len(message["bytes"]),
                    })

    except WebSocketDisconnect:
        pass
    except Exception as exc:
        logger.error(f"session={session_id} event=websocket_error error={exc}")
    finally:
        if azure_session:
            try:
                azure_session.stop()
            except Exception:
                pass
        if pump_task:
            pump_task.cancel()
        async with registry_lock:
            session_registry.pop(session_id, None)
        logger.info(f"session={session_id} event=client_disconnected")


# ============================================================
# REST EVALUATION & NLP ENDPOINTS (unchanged logic)
# ============================================================

def normalize_text(text: str) -> List[str]:
    if not text:
        return []
    cleaned = re.sub(r'[^\w\s]', '', text.lower())
    return cleaned.split()


def compute_phonetic_similarity(w1: str, w2: str) -> float:
    if not w1 or not w2:
        return 0.0
    w1_clean = w1.replace(" ", "").replace("-", "")
    w2_clean = w2.replace(" ", "").replace("-", "")
    if w1_clean == w2_clean:
        return 1.0
    if w1_clean == "a" and w2_clean in {"uh", "ay", "a", "ah", "ey"}:
        return 1.0
    if w1_clean == "the" and w2_clean in {"the", "thuh", "thee", "tha"}:
        return 1.0
    str_sim = max(
        jellyfish.jaro_winkler_similarity(w1, w2),
        jellyfish.jaro_winkler_similarity(w1_clean, w2_clean),
    )
    m1 = jellyfish.metaphone(w1_clean)
    m2 = jellyfish.metaphone(w2_clean)
    if m1 and m2:
        meta_sim = 1.0 if m1 == m2 else jellyfish.jaro_winkler_similarity(m1, m2)
        return round(min(1.0, max(0.0, (str_sim * 0.6) + (meta_sim * 0.4))), 4)
    return round(str_sim, 4)


def align_and_score(expected_text: str, spoken_text: str) -> Dict[str, Any]:
    expected_words = normalize_text(expected_text)
    spoken_words   = normalize_text(spoken_text)

    if not expected_words:
        return {"total_words": 0, "good_count": 0, "improvement_count": 0,
                "miscue_count": 0, "accuracy_score": 100.0, "word_feedback": []}

    word_feedback    = []
    good_count       = 0
    improvement_count = 0
    miscue_count     = 0
    spoken_index     = 0
    spoken_len       = len(spoken_words)

    for exp_word in expected_words:
        best_sim      = 0.0
        best_match_idx = -1
        window_end    = min(spoken_index + 4, spoken_len)
        for idx in range(spoken_index, window_end):
            sim = compute_phonetic_similarity(exp_word, spoken_words[idx])
            if sim > best_sim:
                best_sim      = sim
                best_match_idx = idx

        if best_match_idx != -1:
            matched = spoken_words[best_match_idx]
            if best_sim >= 0.88:
                status = "good";       good_count       += 1; spoken_index = best_match_idx + 1
            elif best_sim >= 0.72:
                status = "improvement"; improvement_count += 1; spoken_index = best_match_idx + 1
            else:
                status = "miscue";     miscue_count += 1
        else:
            status  = "miscue"
            matched = ""
            miscue_count += 1

        word_feedback.append({"word": exp_word, "status": status, "similarity": round(best_sim, 4), "spoken": matched})

    total_words = len(expected_words)
    accuracy = round(max(0.0, ((total_words - miscue_count) / total_words) * 100.0), 2) if total_words > 0 else 100.0

    return {
        "total_words": total_words, "good_count": good_count,
        "improvement_count": improvement_count, "miscue_count": miscue_count,
        "accuracy_score": accuracy, "word_feedback": word_feedback,
    }


@app.post("/evaluate")
async def evaluate_endpoint(payload: Dict[str, Any]):
    expected_text = (payload.get("expected_text") or payload.get("reference_text") or payload.get("text") or "").strip()
    spoken_text   = (payload.get("spoken_text")   or payload.get("transcript")      or "").strip()

    if not expected_text:
        raise HTTPException(status_code=400, detail="expected_text is required")

    result = align_and_score(expected_text, spoken_text)

    return {
        "expected_text":       expected_text,
        "spoken_text":         spoken_text,
        "total_words":         result["total_words"],
        "good_count":          result["good_count"],
        "improvement_count":   result["improvement_count"],
        "miscue_count":        result["miscue_count"],
        "accuracy_score":      result["accuracy_score"],
        "word_feedback":       result["word_feedback"],
        "engine":              "azure",
    }


@app.post("/evaluate_instructional")
async def evaluate_instructional_endpoint(payload: Dict[str, Any]):
    return await evaluate_endpoint(payload)


@app.post("/phoneme_evaluate")
async def phoneme_evaluate_endpoint(payload: Dict[str, Any]):
    word         = (payload.get("word") or payload.get("target") or "").strip()
    spoken_word  = (payload.get("spoken") or payload.get("spoken_text") or "").strip()

    if not word:
        raise HTTPException(status_code=400, detail="word is required")

    target_info = get_target_info(word)
    result = align_and_score(word, spoken_word)

    return {
        "word":          word,
        "spoken":        spoken_word,
        "target_info":   target_info,
        "score":         result,
        "engine":        "azure",
    }


# ============================================================
# PHONEME GUIDE & ANALYSIS
# ============================================================

def segment_word_into_phonemes(word: str) -> List[str]:
    if ipa_lib:
        try:
            ipa_str = ipa_lib.convert(word.lower())
            return [c for c in ipa_str if c.strip() and c not in {"ˈ", "ˌ", " "}]
        except Exception:
            pass
    return list(word.lower())


def ipa_to_readable(ipa_char: str, word: str) -> str:
    """Convert an IPA character to a reader-friendly description."""
    ipa_guide = {
        "æ": "ah (as in 'cat')", "ʌ": "uh (as in 'cup')", "ɑː": "ah (as in 'car')",
        "iː": "ee (as in 'see')", "uː": "oo (as in 'food')", "ə": "uh (as in 'about')",
        "ɪ": "ih (as in 'bit')", "ʊ": "uh (as in 'book')", "ɛ": "eh (as in 'bed')",
        "ɔː": "aw (as in 'saw')", "oʊ": "oh (as in 'go')", "eɪ": "ay (as in 'day')",
        "aɪ": "eye", "aʊ": "ow (as in 'cow')", "ɔɪ": "oy", "ɚ": "er",
        "ð": "th (voiced, as in 'the')", "θ": "th (as in 'think')",
        "ʃ": "sh", "ʒ": "zh", "tʃ": "ch", "dʒ": "j", "ŋ": "ng",
    }
    return ipa_guide.get(ipa_char, ipa_char)


async def get_or_create_phonetic_guide(word: str) -> Dict[str, Any]:
    clean_w = re.sub(r'[^\w]', '', word.lower())
    if not clean_w:
        return {"word": word, "phonemes": [], "blending_steps": [], "ipa": ""}

    phonemes = segment_word_into_phonemes(clean_w)
    syl_count, syllables, _, stress = dynamic_syllabify_word(clean_w)

    blending_steps = []
    for i, ph in enumerate(phonemes):
        blend = "".join(phonemes[:i + 1])
        readable = ipa_to_readable(ph, clean_w)
        blending_steps.append({
            "step":          i + 1,
            "phoneme":       ph,
            "readable":      readable,
            "blend_so_far":  blend,
            "label":         f"Step {i + 1}",
            "formula":       f"/{ph}/ ({readable}) → [ {blend} ]",
            "spoken_target": blend,
        })

    return {
        "word":           clean_w,
        "phonemes":       phonemes,
        "ipa":            "".join(phonemes),
        "syllable_count": syl_count,
        "syllables":      syllables,
        "stress":         stress,
        "blending_steps": blending_steps,
    }


@app.post("/phoneme_analysis")
@app.post("/breakdown_word")
async def phoneme_analysis_endpoint(payload: Dict[str, Any]):
    word = payload.get("word") or payload.get("target_word") or ""
    return await get_or_create_phonetic_guide(word)


@app.post("/batch_phoneme_analysis")
async def batch_phoneme_analysis_endpoint(payload: Dict[str, Any]):
    words = payload.get("words", [])
    results = {}
    for w in words:
        clean_w = re.sub(r'[^\w]', '', str(w).lower())
        if clean_w and clean_w not in results:
            results[clean_w] = await get_or_create_phonetic_guide(clean_w)
    return {"results": results}


@app.post("/progressive_blend_steps")
async def progressive_blend_steps_endpoint(payload: Dict[str, Any]):
    word = (payload.get("word") or "").strip().lower()
    if not word:
        return {"error": "No word provided"}
    guide = await get_or_create_phonetic_guide(word)
    return {"word": word, "phonemes": guide["phonemes"], "steps": guide["blending_steps"]}


# ============================================================
# AZURE TTS — replaces OpenAI TTS
# ============================================================

async def _azure_tts_speak(text: str, voice: str = "en-US-JennyNeural") -> Optional[bytes]:
    """Synthesize text to MP3 bytes using Azure TTS (Neural voice)."""
    if not AZURE_KEY or not AZURE_SDK_AVAILABLE:
        return None
    try:
        speech_config = speechsdk.SpeechConfig(subscription=AZURE_KEY, region=AZURE_REGION)
        speech_config.speech_synthesis_voice_name = voice
        speech_config.set_speech_synthesis_output_format(
            speechsdk.SpeechSynthesisOutputFormat.Audio16Khz32KBitRateMonoMp3
        )
        loop = asyncio.get_running_loop()

        def _synth():
            synthesizer = speechsdk.SpeechSynthesizer(
                speech_config=speech_config, audio_config=None
            )
            result = synthesizer.speak_text_async(text).get()
            if result.reason == speechsdk.ResultReason.SynthesizingAudioCompleted:
                return bytes(result.audio_data)
            return None

        return await loop.run_in_executor(None, _synth)
    except Exception as e:
        logger.warning(f"Azure TTS failed: {e}")
        return None


@app.post("/tts_words")
async def tts_words_endpoint(payload: Dict[str, Any]):
    words   = payload.get("words", [])
    voice   = payload.get("voice", "en-US-JennyNeural")
    audio_map = {}

    unique_words = list(dict.fromkeys([
        re.sub(r'[^\w]', '', str(w).lower()) for w in words if str(w).strip()
    ]))[:60]

    for w in unique_words:
        if not w:
            continue
        audio_bytes = await _azure_tts_speak(w, voice=voice)
        if audio_bytes:
            audio_map[w] = "data:audio/mp3;base64," + base64.b64encode(audio_bytes).decode("utf-8")

    return {"audio_map": audio_map, "engine": "azure_tts"}


@app.post("/tts_phoneme_guide")
async def tts_phoneme_guide_endpoint(payload: Dict[str, Any]):
    text  = payload.get("text", "")
    voice = payload.get("voice", "en-US-JennyNeural")

    if not text:
        return {"error": "No text provided"}

    audio_bytes = await _azure_tts_speak(text, voice=voice)
    if audio_bytes:
        b64 = base64.b64encode(audio_bytes).decode("utf-8")
        return {"audio": f"data:audio/mp3;base64,{b64}", "engine": "azure_tts"}
    return {"audio": None, "fallback": "speech_synthesis", "reason": "Azure TTS unavailable"}


# ============================================================
# LETTER PHONICS EVALUATION
# ============================================================

LETTER_PHONEMES = {
    "a": ["a", "ah", "ay", "ae", "eh", "uh", "apple", "ant", "at"],
    "e": ["e", "eh", "ee", "egg", "elephant", "ed"],
    "i": ["i", "ih", "eye", "ee", "it", "in", "igloo"],
    "o": ["o", "ah", "oh", "aw", "octopus", "on"],
    "u": ["u", "uh", "yu", "oo", "umbrella", "up"],
    "b": ["b", "ba", "beh", "bee", "buh", "bat", "ball"],
    "c": ["c", "k", "ka", "keh", "kuh", "see", "cat"],
    "d": ["d", "da", "deh", "duh", "dee", "dog"],
    "f": ["f", "ef", "fa", "feh", "fuh", "fish"],
    "g": ["g", "ga", "geh", "guh", "gee", "goat"],
    "h": ["h", "ha", "heh", "huh", "aitch", "hat"],
    "j": ["j", "ja", "jeh", "juh", "jay", "jam"],
    "k": ["k", "ka", "keh", "kuh", "kay", "kite"],
    "l": ["l", "el", "la", "leh", "luh", "lion"],
    "m": ["m", "em", "ma", "meh", "muh", "moon"],
    "n": ["n", "en", "na", "neh", "nuh", "nut"],
    "p": ["p", "pa", "peh", "puh", "pee", "pig"],
    "r": ["r", "ar", "er", "ra", "reh", "ring"],
    "s": ["s", "es", "sa", "seh", "suh", "sun"],
    "t": ["t", "ta", "teh", "tuh", "tee", "top"],
    "v": ["v", "va", "veh", "vuh", "vee", "van"],
    "w": ["w", "wa", "weh", "wuh", "win"],
    "x": ["x", "ks", "ex", "box", "fox"],
    "y": ["y", "ya", "yeh", "yuh", "yellow"],
    "z": ["z", "za", "zeh", "zuh", "zee", "zebra"],
    "sh": ["sh", "sha", "sheh", "ship", "shoe"],
    "ch": ["ch", "cha", "chair", "chin"],
    "th": ["th", "the", "tha", "thumb", "think"],
}


@app.post("/phonics_letter_eval")
async def phonics_letter_eval_endpoint(payload: Dict[str, Any]):
    target_letter     = (payload.get("letter") or "").strip().lower()
    spoken_transcript = (payload.get("transcript") or "").strip().lower()

    if not target_letter:
        return {"matched": False, "reason": "No target letter provided"}

    spoken_tokens    = [re.sub(r"[^\w]", "", t) for t in spoken_transcript.split() if t]
    allowed_phonemes = LETTER_PHONEMES.get(target_letter, [target_letter])
    matched          = False
    matched_token    = ""

    for token in spoken_tokens:
        if token in allowed_phonemes or token == target_letter:
            matched       = True
            matched_token = token
            break

    if not matched:
        for token in spoken_tokens:
            for ph in allowed_phonemes:
                if jellyfish.jaro_winkler_similarity(token, ph) >= 0.80:
                    matched       = True
                    matched_token = token
                    break
            if matched:
                break

    return {"matched": matched, "target": target_letter, "spoken": spoken_transcript, "matched_token": matched_token}


# ============================================================
# UTILITY ENDPOINTS
# ============================================================

@app.post("/session")
async def create_session_endpoint():
    return {
        "engine":    "azure_speech",
        "ws_url":    f"ws://{APP_HOST}:{APP_PORT}/ws/stream",
        "status":    "ready",
        "azure_key_set": bool(AZURE_KEY),
        "azure_region":  AZURE_REGION,
    }


@app.get("/target")
async def target_endpoint(text: str):
    try:
        return get_target_info(text)
    except Exception as exc:
        raise HTTPException(status_code=400, detail=str(exc))


@app.get("/version")
async def version_endpoint():
    return {
        "version":  "5.0.0",
        "service":  "streamASR_Azure",
        "engine":   "azure_cognitiveservices_speech",
        "protocol": "moodle_acoustic_v5.0",
    }


@app.get("/health")
async def health_endpoint():
    key = AZURE_KEY or os.environ.get("AZURE_SPEECH_KEY") or os.environ.get("RA_AZURE_KEY") or ""
    region = AZURE_REGION or os.environ.get("AZURE_SPEECH_REGION") or "southeastasia"
    return {
        "status":           "ok",
        "service":          "streamASR_Azure",
        "version":          "5.0.0",
        "azure_key_set":    bool(key),
        "azure_region":     region,
        "azure_sdk":        AZURE_SDK_AVAILABLE,
        "active_sessions":  len(session_registry),
    }


@app.get("/")
async def root_endpoint():
    return HTMLResponse(
        "<h2>Streaming ASR — Azure Edition (v5.0)</h2>"
        "<p>WebSocket: <code>/ws/stream</code></p>"
        f"<p>Azure region: <code>{AZURE_REGION}</code> | Key set: <code>{bool(AZURE_KEY)}</code></p>"
    )


@app.on_event("startup")
async def startup_event():
    if not AZURE_SDK_AVAILABLE:
        logger.error("azure-cognitiveservices-speech is NOT installed! Run: pip install azure-cognitiveservices-speech")
    elif not AZURE_KEY:
        logger.warning("AZURE_SPEECH_KEY / RA_AZURE_KEY not set — ASR will fail.")
    else:
        logger.info(f"Azure Speech SDK ready. Region={AZURE_REGION}")

    logger.info("=" * 60)
    logger.info(f"Streaming ASR — Azure Edition running at http://{APP_HOST}:{APP_PORT}")
    logger.info(f"WebSocket endpoint: ws://{APP_HOST}:{APP_PORT}/ws/stream")
    logger.info("=" * 60)


@app.on_event("shutdown")
async def shutdown_event():
    logger.info("Shutting down Streaming ASR — Azure Edition.")


if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host=APP_HOST, port=APP_PORT)
