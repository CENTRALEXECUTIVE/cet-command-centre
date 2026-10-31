#!/usr/bin/env python3
"""
transcribe.py — Word-level transcription using faster-whisper.
Outputs a .transcript.json next to the source video.

Usage:
    python transcribe.py <video_path> [--model base|small|medium]

Requires: pip install faster-whisper
Note: Downloads model on first run (~150MB for base, ~500MB for medium).
      medium recommended for production; base for quick tests.
"""
import json, sys, time, argparse
from pathlib import Path


def transcribe(video_path: Path, model_size: str = "medium") -> dict:
    from faster_whisper import WhisperModel

    print(f"Loading Whisper {model_size} model...", flush=True)
    model = WhisperModel(model_size, device="cpu", compute_type="int8")

    print(f"Transcribing: {video_path.name}", flush=True)
    t0 = time.time()

    segments, info = model.transcribe(
        str(video_path),
        language="en",
        word_timestamps=True,
        vad_filter=True,
        vad_parameters={"min_silence_duration_ms": 400},
    )

    output = {
        "video": video_path.name,
        "duration_s": info.duration,
        "language": info.language,
        "segments": [],
    }

    for seg in segments:
        words = []
        if seg.words:
            for w in seg.words:
                words.append({
                    "word": w.word.strip(),
                    "start": round(w.start, 3),
                    "end": round(w.end, 3),
                    "prob": round(w.probability, 3),
                })
        output["segments"].append({
            "start": round(seg.start, 3),
            "end": round(seg.end, 3),
            "text": seg.text.strip(),
            "words": words,
        })

    elapsed = time.time() - t0
    total_words = sum(len(s["words"]) for s in output["segments"])
    print(f"Done in {elapsed:.0f}s — {len(output['segments'])} segments, {total_words} words", flush=True)

    return output


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("video", help="Path to source video")
    parser.add_argument("--model", default="medium", choices=["tiny", "base", "small", "medium", "large"])
    parser.add_argument("--out", help="Output JSON path (default: next to video)")
    args = parser.parse_args()

    video_path = Path(args.video)
    if not video_path.exists():
        print(f"Error: {video_path} not found", file=sys.stderr)
        sys.exit(1)

    out_path = Path(args.out) if args.out else video_path.with_suffix(".transcript.json")

    result = transcribe(video_path, args.model)
    out_path.write_text(json.dumps(result, indent=2))
    print(f"Saved → {out_path}")


if __name__ == "__main__":
    main()
