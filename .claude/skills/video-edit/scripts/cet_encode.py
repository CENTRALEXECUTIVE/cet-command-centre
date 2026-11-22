"""Shared x264/AAC settings for every CET video script (Nov 2026).

Why: the Reels were looking soft on Instagram/TikTok. They were encoded at only
~1.3-2.7 Mbps after several lossy passes (final concat at -crf 24). The apps re-compress
whatever we upload, so the upload must be high-bitrate (roughly 8-14 Mbps H.264 for
1080x1920) for their copy to stay sharp.

- INTER_*: intermediate segments (cut, overlay, end card). Near-transparent quality so
  nothing is lost between passes.
- FINAL:   the one delivery encode: H.264 High, yuv420p, CRF 17 capped at 14 Mbps, 30 fps,
  AAC 192k 48 kHz stereo, faststart. Always give the final file an audio stream (a silent
  stereo track when there is no sound) because the apps expect one.
"""

INTER_VIDEO = ["-c:v", "libx264", "-preset", "medium", "-crf", "14", "-pix_fmt", "yuv420p"]
AUDIO = ["-c:a", "aac", "-b:a", "192k", "-ar", "48000", "-ac", "2"]
INTER = INTER_VIDEO + AUDIO

FINAL_VIDEO = ["-c:v", "libx264", "-profile:v", "high", "-pix_fmt", "yuv420p", "-preset", "slow",
               "-crf", "17", "-maxrate", "14M", "-bufsize", "28M", "-r", "30"]
FINAL = FINAL_VIDEO + AUDIO + ["-movflags", "+faststart"]

# lavfi input for a silent stereo track: ["-f", "lavfi", "-i", SILENCE]
SILENCE = "anullsrc=r=48000:cl=stereo"
