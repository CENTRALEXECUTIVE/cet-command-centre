# Config — viral-shortform-editor

Edit these paths and thresholds before running the skill.

## Paths

```
FOOTAGE_DIR   = ~/AI Video Editor/examples
BROLL_DIR     = ~/AI Video Editor/BROLL
MUSIC_DIR     = ~/AI Video Editor/MUSIC
OUTPUT_DIR    = ~/AI Video Editor/output
```

## Thresholds

```
CONFIDENCE_THRESHOLD = 0.85   # Cuts below this pause the pipeline for your review
SILENCE_DB           = -35    # dB floor for silence detection (louder = more sensitive)
SILENCE_MIN_DUR      = 0.40   # Minimum silence duration (seconds) to trim
SCENE_THRESHOLD      = 27.0   # SceneDetect sensitivity (lower = more cuts detected)
```

## Whisper model

```
WHISPER_MODEL = medium   # Options: tiny, base, small, medium, large
                         # medium = best speed/accuracy tradeoff for production
                         # base   = fast for quick tests (less accurate on names/jargon)
```

## Renderer — HyperFrames

```
RENDERER   = hyperframes          # replaces Remotion
FPS        = 30
WIDTH      = 1080
HEIGHT     = 1920
NODE_MIN   = 22                   # Node.js minimum version required

# Install once in your project directory:
#   npx skills add heygen-com/hyperframes

# Preview:  npx hyperframes preview
# Render:   npx hyperframes render index.html output.mp4
```
