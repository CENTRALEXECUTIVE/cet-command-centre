# Third-party skills & agents (vendored, reviewed before adding — 31 Oct 2026)

Each copied folder keeps its upstream LICENSE. Text-only skills except video-edit (Python scripts,
reviewed: local ffmpeg/whisper only, no network calls of their own).

| Folder | Source (commit) | Licence |
|---|---|---|
| skills/social, video, content-strategy, copywriting, ad-creative, referrals, seo-audit, schema, marketing-psychology | coreyhaines31/marketingskills (88c79f6) | MIT |
| skills/frontend-design | anthropics/skills (ca45fc4) | Apache-2.0 |
| skills/visual-design-foundations, accessibility-compliance, responsive-design, interaction-design; agents/ui-designer, accessibility-expert, design-system-architect | wshobson/agents, plugins/ui-design (7e2fb55) | MIT |
| skills/video-edit | FeezRM/AI-ShortForm-Editor, viral-shortform-editor (7832134) — adapted: CET gold captions, ffmpeg render, privacy rules | Apache-2.0 |

Local changes: evals removed from marketing skills; "Use PROACTIVELY" removed from the
ui-design agents so they only run when asked. All CLAUDE.md safety rules still apply
(never touch public_html, nothing auto-sends).
