#!/usr/bin/env python3
"""Download a social video, transcribe it and save frames for Claude to look at.

usage: watch.py URL_OR_FILE --out DIR [--every SECONDS] [--cookies-from-browser NAME]
"""
import argparse, glob, json, os, re, shutil, subprocess, sys


def need(mod, pkg):
    try:
        __import__(mod)
        return True
    except ImportError:
        r = subprocess.run([sys.executable, "-m", "pip", "install", "-q", pkg], capture_output=True, text=True)
        if r.returncode:
            print(f"! could not install {pkg}: {r.stderr.strip()[-200:]}")
            return False
        return True


def vtt_to_text(path):
    lines, last = [], None
    for raw in open(path, encoding="utf-8", errors="ignore"):
        t = re.sub(r"<[^>]+>", "", raw).strip()
        if not t or t == "WEBVTT" or "-->" in t or t.isdigit() or t.startswith(("Kind:", "Language:", "NOTE")):
            continue
        if t != last:
            lines.append(t)
        last = t
    return " ".join(lines)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("src")
    ap.add_argument("--out", required=True)
    ap.add_argument("--every", type=float, default=0, help="seconds between frames (default: spread 16 over the video)")
    ap.add_argument("--cookies-from-browser")
    a = ap.parse_args()
    os.makedirs(os.path.join(a.out, "frames"), exist_ok=True)
    info, video, notes, transcript = {}, None, [], ""

    if os.path.exists(a.src):
        video = a.src
    else:
        if not need("yt_dlp", "yt-dlp"):
            sys.exit(1)
        import yt_dlp
        opts = {
            "outtmpl": os.path.join(a.out, "video.%(ext)s"), "quiet": True, "no_warnings": True,
            "format": "best[height<=720]/best", "writesubtitles": True, "writeautomaticsub": True,
            "subtitleslangs": ["en.*", "en"], "subtitlesformat": "vtt", "retries": 2,
        }
        if a.cookies_from_browser:
            opts["cookiesfrombrowser"] = (a.cookies_from_browser,)
        with yt_dlp.YoutubeDL(opts) as y:
            try:
                info = y.extract_info(a.src, download=False) or {}
            except Exception as e:
                notes.append(f"Could not read the page: {e}")
            if info:
                try:
                    y.process_ie_result(info, download=True)
                except Exception as e:
                    notes.append(f"Video file not downloaded ({str(e)[:160]}). Caption and stats only.")
        found = [f for f in glob.glob(os.path.join(a.out, "video.*")) if not f.endswith((".vtt", ".json", ".part"))]
        video = found[0] if found else None
        subs = glob.glob(os.path.join(a.out, "*.vtt"))
        if subs:
            transcript = vtt_to_text(subs[0])

    duration = float(info.get("duration") or 0)
    frames = []
    if video and shutil.which("ffmpeg"):
        if not duration:
            p = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "format=duration", "-of", "csv=p=0", video], capture_output=True, text=True)
            duration = float(p.stdout.strip() or 0)
        every = a.every or max(2.0, duration / 16 if duration else 3.0)
        subprocess.run(["ffmpeg", "-loglevel", "error", "-y", "-i", video, "-vf", f"fps=1/{every},scale=720:-2", "-frames:v", "16",
                        os.path.join(a.out, "frames", "f%02d.jpg")])
        frames = sorted(glob.glob(os.path.join(a.out, "frames", "*.jpg")))
        frames = [(f, round(i * every, 1)) for i, f in enumerate(frames)]
        if not transcript and need("faster_whisper", "faster-whisper"):
            try:
                from faster_whisper import WhisperModel
                model = WhisperModel("base", device="cpu", compute_type="int8")
                segs, _ = model.transcribe(video, vad_filter=True)
                transcript = " ".join(s.text.strip() for s in segs)
            except Exception as e:
                notes.append(f"Speech-to-text failed ({str(e)[:160]}).")
    elif not video:
        notes.append("No video file, so no frames or speech-to-text.")

    stats = {k: info.get(k) for k in ("view_count", "like_count", "comment_count", "repost_count") if info.get(k) is not None}
    r = [f"# {info.get('title') or os.path.basename(a.src)}", "",
         f"- Source: {info.get('webpage_url') or a.src}",
         f"- Author: {info.get('uploader') or info.get('channel') or '?'} ({info.get('uploader_id') or ''})",
         f"- Length: {round(duration)} s" if duration else "- Length: unknown",
         f"- Posted: {info.get('upload_date') or '?'}",
         f"- Stats: {json.dumps(stats)}" if stats else "- Stats: none", "",
         "## Caption", "", (info.get("description") or "(none)").strip(), "",
         "## Transcript", "", transcript or "(none)", "",
         "## Frames", ""]
    r += [f"- {f}  (~{t}s)" for f, t in frames] or ["(none)"]
    if notes:
        r += ["", "## Notes", ""] + [f"- {n}" for n in notes]
    open(os.path.join(a.out, "report.md"), "w").write("\n".join(r) + "\n")
    print(os.path.join(a.out, "report.md"))
    for n in notes:
        print("!", n)


if __name__ == "__main__":
    main()
