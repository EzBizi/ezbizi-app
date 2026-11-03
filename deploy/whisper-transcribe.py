"""Transcribe one call recording on this server, and print the words.

Run by `php artisan ezbizi:transcribe-lead-calls` (CallTranscripts) with the
interpreter in /opt/whisper/venv, where faster-whisper and the model live
(installed 2026-09-29; see deploy/ezbizi-transcribe.service). The audio never
leaves the box.

distil-large-v3, int8, 4 threads: measured 9x faster than the call on this
box's Xeon (a 6-minute call in 40 s). Usage: whisper-transcribe.py <audio file>
"""
import sys

from faster_whisper import WhisperModel

model = WhisperModel(
    "distil-large-v3",
    device="cpu",
    compute_type="int8",
    cpu_threads=6,
    download_root="/opt/whisper/models",
    local_files_only=True,
)
segments, _ = model.transcribe(
    sys.argv[1],
    language="en",
    vad_filter=True,
    beam_size=1,
    initial_prompt="Thank you for calling A-1 Chimney. Chimney sweep, fireplace, flue, "
    "chimney inspection, level 2 inspection, liner, crown, damper.",
)
print(" ".join(s.text.strip() for s in segments).strip())
