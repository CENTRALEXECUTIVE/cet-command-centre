---
name: social-week
description: Produce next week's complete social media pack for Central Executive Transfers in one go — calendar, per-platform captions, hashtags, posting times, shot briefs, Reel scripts and a scheduler bulk-upload CSV. Use when the user says /social-week or asks what to post this week.
---

1. Hand the work to the `social-media-manager` agent. Pass on any theme, event or
   offer the user mentioned, and the week's start date (next Monday unless told).
2. The agent must read `docs/growth/BUSINESS_FACTS.md` and the latest pack in
   `docs/growth/social/` so it doesn't repeat itself.
3. Output: `docs/growth/social/<monday-date>-week.md` (readable calendar) and
   `docs/growth/social/<monday-date>-week.csv` (bulk upload: date, time, platform,
   format, caption, hashtags, link, media_brief).
4. Reply to the user with a short phone-friendly summary: the 7 posts (day,
   platform, one-line idea), "Shoot these this week", and where the files are.
   Nothing is posted — the user schedules it.
