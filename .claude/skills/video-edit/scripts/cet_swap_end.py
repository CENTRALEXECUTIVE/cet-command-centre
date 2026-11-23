#!/usr/bin/env python3
"""Replace (or add) a Reel's end card with the animated name-board ending.
Usage: cet_swap_end.py <in.mp4> <out.mp4> [end_start_seconds|none]
If end_start is given, the reel is cut there (old end card dropped); 'none' appends.
Final encode uses the house settings (CRF 17, high profile, 14M cap, AAC 48k stereo)."""
import subprocess, sys
from pathlib import Path

END = Path(__file__).resolve().parent.parent / 'templates' / 'end-nameboard.mp4'
src, out = sys.argv[1], sys.argv[2]
cut = sys.argv[3] if len(sys.argv) > 3 else 'none'
trim = '' if cut == 'none' else f'trim=0:{cut},setpts=PTS-STARTPTS,'
atrim = '' if cut == 'none' else f'atrim=0:{cut},asetpts=PTS-STARTPTS,'
has_audio = bool(subprocess.run(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', src],
                                capture_output=True, text=True).stdout.strip())
a0 = f'[0:a]{atrim}aresample=48000,aformat=channel_layouts=stereo[a0];' if has_audio else \
     f'anullsrc=r=48000:cl=stereo,atrim=0:{cut if cut != "none" else 0}[a0];'
fc = (f'[0:v]{trim}scale=1080:1920,fps=30,format=yuv420p,setsar=1[v0];{a0}'
      f'[1:v]scale=1080:1920,fps=30,format=yuv420p,setsar=1[v1];[1:a]aresample=48000,aformat=channel_layouts=stereo[a1];'
      f'[v0][a0][v1][a1]concat=n=2:v=1:a=1[v][a]')
cmd = ['ffmpeg', '-v', 'error', '-y', '-i', src, '-i', str(END)]
if not has_audio:
    cmd = ['ffmpeg', '-v', 'error', '-y', '-i', src, '-i', str(END)]
cmd += ['-filter_complex', fc, '-map', '[v]', '-map', '[a]', '-c:v', 'libx264', '-profile:v', 'high', '-pix_fmt', 'yuv420p',
        '-preset', 'slow', '-crf', '17', '-maxrate', '14M', '-bufsize', '28M', '-r', '30', '-c:a', 'aac', '-b:a', '192k',
        '-ar', '48000', '-ac', '2', '-movflags', '+faststart', out]
r = subprocess.run(cmd, capture_output=True, text=True)
if r.returncode:
    sys.exit(r.stderr[-1500:])
print('Done', out)
