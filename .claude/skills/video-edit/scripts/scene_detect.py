#!/usr/bin/env python3
"""
scene_detect.py — Detect scene cuts and audio peaks in a video.
Outputs a .analysis.json next to the source video.

Usage:
    python scene_detect.py <video_path> [--threshold 27.0]

Requires: pip install scenedetect[opencv] librosa ffmpeg-python
"""
import json, sys, argparse, subprocess, os, tempfile
from pathlib import Path
import numpy as np


def detect_scenes(video_path: Path, threshold: float = 27.0) -> list:
    from scenedetect import open_video, SceneManager
    from scenedetect.detectors import ContentDetector

    video = open_video(str(video_path))
    sm = SceneManager()
    sm.add_detector(ContentDetector(threshold=threshold))
    sm.detect_scenes(video, show_progress=False)
    scenes = sm.get_scene_list()

    result = []
    for i, (start, end) in enumerate(scenes):
        result.append({
            "scene_idx": i,
            "start_s": round(start.get_seconds(), 3),
            "end_s": round(end.get_seconds(), 3),
            "duration_s": round((end - start).get_seconds(), 3),
            "start_frame": start.get_frames(),
            "end_frame": end.get_frames(),
        })
    return result


def detect_silence(video_path: Path, noise_db: float = -35, min_dur: float = 0.3) -> list:
    """Use ffmpeg silencedetect — no model needed."""
    cmd = [
        "ffmpeg", "-i", str(video_path),
        "-af", f"silencedetect=noise={noise_db}dB:duration={min_dur}",
        "-f", "null", "-", "-loglevel", "info"
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    output = result.stderr

    silences = []
    start = None
    for line in output.split("\n"):
        if "silence_start" in line:
            try:
                start = float(line.split("silence_start: ")[1].split()[0])
            except (IndexError, ValueError):
                pass
        elif "silence_end" in line and start is not None:
            try:
                end_val = float(line.split("silence_end: ")[1].split("|")[0].strip())
                dur = float(line.split("silence_duration: ")[1].split()[0])
                silences.append({
                    "start": round(start, 3),
                    "end": round(end_val, 3),
                    "duration": round(dur, 3),
                })
                start = None
            except (IndexError, ValueError):
                pass
    return silences


def analyze_audio(video_path: Path) -> dict:
    import librosa

    with tempfile.NamedTemporaryFile(suffix=".wav", delete=False) as tmp:
        wav_path = tmp.name

    try:
        subprocess.run([
            "ffmpeg", "-i", str(video_path),
            "-ac", "1", "-ar", "22050", "-vn",
            wav_path, "-y", "-loglevel", "quiet"
        ], check=True)

        y, sr = librosa.load(wav_path, sr=22050, mono=True)
    finally:
        os.unlink(wav_path)

    # RMS energy over time
    rms = librosa.feature.rms(y=y, frame_length=2048, hop_length=512)[0]

    # Tempo + beats
    tempo, beats = librosa.beat.beat_track(y=y, sr=sr)
    tempo = float(np.atleast_1d(tempo)[0])
    beat_times = librosa.frames_to_time(beats, sr=sr).tolist()

    # Onset detection (good for cut sync)
    onsets = librosa.onset.onset_detect(y=y, sr=sr, units="time")

    # Pacing: RMS bucketed into thirds
    third = max(len(rms) // 3, 1)
    rms_thirds = [
        round(float(np.mean(rms[:third])), 4),
        round(float(np.mean(rms[third:2*third])), 4),
        round(float(np.mean(rms[2*third:])), 4),
    ]

    return {
        "tempo_bpm": round(float(tempo), 1),
        "beat_times_s": [round(t, 3) for t in beat_times[:50]],  # first 50 beats
        "onset_times_s": [round(float(t), 3) for t in onsets[:100]],
        "mean_rms": round(float(np.mean(rms)), 4),
        "max_rms": round(float(np.max(rms)), 4),
        "rms_thirds_open_mid_close": rms_thirds,
    }


FILLER_WORDS = {
    "alright", "okay", "so", "um", "uh", "like", "basically", "literally",
    "actually", "wait", "honestly", "right", "just",
}
RESTART_PHRASES = [
    "no wait", "actually wait", "let me", "okay so", "alright so",
    "hold on", "sorry", "start over", "let me start", "i messed up",
]


def detect_mistakes(transcript: dict) -> list[dict]:
    """Scan transcript for repeated phrases, filler cues, and explicit restarts."""
    words = [
        w for seg in transcript.get("segments", [])
        for w in seg.get("words", [])
    ]
    if not words:
        return []

    mistakes = []
    n = len(words)

    # 1. Repeated ngrams: look for 3+ word sequences that repeat within 15s
    for size in (4, 3):
        for i in range(n - size):
            seq = tuple(w["word"].lower().strip(".,!?\"'") for w in words[i:i + size])
            window_end = words[i]["start"] + 15.0
            for j in range(i + 1, n - size + 1):
                if words[j]["start"] > window_end:
                    break
                seq2 = tuple(w["word"].lower().strip(".,!?\"'") for w in words[j:j + size])
                if seq == seq2:
                    # Flag the FIRST occurrence (the mistaken take)
                    already = any(
                        abs(m["start_s"] - words[i]["start"]) < 0.5
                        for m in mistakes
                    )
                    if not already:
                        mistakes.append({
                            "type": "repeated_phrase",
                            "start_s": round(words[i]["start"], 3),
                            "end_s": round(words[i + size - 1]["end"], 3),
                            "text": " ".join(w["word"] for w in words[i:i + size]),
                        })

    # 2. Explicit restart cue phrases
    full_text = " ".join(w["word"].lower() for w in words)
    for phrase in RESTART_PHRASES:
        idx = 0
        while True:
            pos = full_text.find(phrase, idx)
            if pos == -1:
                break
            # Map char position back to word index
            char_count = 0
            for wi, w in enumerate(words):
                if char_count >= pos:
                    mistakes.append({
                        "type": "restart_cue",
                        "start_s": round(w["start"], 3),
                        "end_s": round(w["end"], 3),
                        "text": phrase,
                    })
                    break
                char_count += len(w["word"]) + 1
            idx = pos + len(phrase)

    # 3. Isolated filler words (single-word, high-confidence)
    for w in words:
        clean = w["word"].lower().strip(".,!?\"'")
        if clean in FILLER_WORDS and w.get("prob", 1.0) > 0.85:
            mistakes.append({
                "type": "filler",
                "start_s": round(w["start"], 3),
                "end_s": round(w["end"], 3),
                "text": w["word"],
            })

    # Sort by start time and deduplicate overlapping entries
    mistakes.sort(key=lambda m: m["start_s"])
    deduped = []
    for m in mistakes:
        if deduped and m["start_s"] < deduped[-1]["end_s"] and m["type"] == deduped[-1]["type"]:
            deduped[-1]["end_s"] = max(deduped[-1]["end_s"], m["end_s"])
        else:
            deduped.append(m)

    return deduped


def get_metadata(video_path: Path) -> dict:
    cmd = [
        "ffprobe", "-v", "quiet", "-print_format", "json",
        "-show_streams", "-show_format", str(video_path)
    ]
    result = subprocess.run(cmd, capture_output=True, text=True)
    probe = json.loads(result.stdout)

    vstream = next((s for s in probe["streams"] if s["codec_type"] == "video"), {})
    astream = next((s for s in probe["streams"] if s["codec_type"] == "audio"), {})
    duration = float(probe["format"].get("duration", 0))

    fps_raw = vstream.get("r_frame_rate", "30/1")
    num, den = fps_raw.split("/")
    fps = round(float(num) / float(den), 2)

    w = int(vstream.get("width", 0))
    h = int(vstream.get("height", 0))

    return {
        "duration_s": round(duration, 3),
        "width": w,
        "height": h,
        "fps": fps,
        "orientation": "vertical" if h > w else "horizontal",
        "total_frames": round(duration * fps),
        "video_codec": vstream.get("codec_name", ""),
        "audio_codec": astream.get("codec_name", ""),
        "audio_sample_rate": astream.get("sample_rate", ""),
    }


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("video", help="Path to source video")
    parser.add_argument("--threshold", type=float, default=27.0, help="Scene change threshold (lower = more sensitive)")
    parser.add_argument("--out", help="Output JSON path (default: next to video)")
    args = parser.parse_args()

    video_path = Path(args.video)
    if not video_path.exists():
        print(f"Error: {video_path} not found", file=sys.stderr)
        sys.exit(1)

    out_path = Path(args.out) if args.out else video_path.with_suffix(".analysis.json")

    print(f"Analyzing: {video_path.name}", flush=True)

    print("  → metadata...", flush=True)
    meta = get_metadata(video_path)

    print("  → scene detection...", flush=True)
    scenes = detect_scenes(video_path, args.threshold)
    print(f"     {len(scenes)} scenes found", flush=True)

    print("  → silence detection...", flush=True)
    silences = detect_silence(video_path)
    print(f"     {len(silences)} silence regions", flush=True)

    print("  → audio analysis...", flush=True)
    audio = analyze_audio(video_path)
    print(f"     tempo={audio['tempo_bpm']}bpm", flush=True)

    transcript_path = video_path.with_suffix(".transcript.json")
    mistakes = []
    if transcript_path.exists():
        print("  → mistake detection...", flush=True)
        transcript = json.loads(transcript_path.read_text())
        mistakes = detect_mistakes(transcript)
        repeated = sum(1 for m in mistakes if m["type"] == "repeated_phrase")
        fillers = sum(1 for m in mistakes if m["type"] == "filler")
        restarts = sum(1 for m in mistakes if m["type"] == "restart_cue")
        print(f"     {len(mistakes)} flagged ({repeated} repeats, {fillers} fillers, {restarts} restarts)", flush=True)
    else:
        print("  → mistake detection skipped (no transcript — run transcribe.py first)", flush=True)

    durations = [s["duration_s"] for s in scenes]
    third = max(len(durations) // 3, 1)
    pace_thirds = [
        round(float(np.mean(durations[:third])), 3),
        round(float(np.mean(durations[third:2*third])), 3),
        round(float(np.mean(durations[2*third:])), 3),
    ] if durations else [0, 0, 0]

    result = {
        "video": video_path.name,
        **meta,
        "scene_count": len(scenes),
        "scenes": scenes,
        "avg_scene_duration_s": round(float(np.mean(durations)), 3) if durations else 0,
        "median_scene_duration_s": round(float(np.median(durations)), 3) if durations else 0,
        "pacing_open_mid_close_s": pace_thirds,
        "silence_count": len(silences),
        "silences": silences,
        "audio": audio,
        "mistakes": mistakes,
    }

    out_path.write_text(json.dumps(result, indent=2))
    print(f"Saved → {out_path}")


if __name__ == "__main__":
    main()
