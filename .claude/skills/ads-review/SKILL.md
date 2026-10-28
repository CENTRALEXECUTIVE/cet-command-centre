---
name: ads-review
description: Weekly Google Ads review for Central Executive Transfers — reads the latest exports, checks last week's changes worked, and gives the ranked change list plus Ads Editor CSVs. Use when the user says /ads-review or asks how the ads are doing.
---

1. Check `docs/growth/data/` for new Google Ads exports (campaign, keyword, search
   terms, ads). If none, tell the user exactly which reports to download
   (Google Ads → Campaigns / Keywords / Search terms → date range last 7 and 30
   days → Download → CSV) and stop.
2. Hand the exports to the `google-ads-strategist` agent: review against
   `docs/growth/ads/CHANGELOG.md`, then produce the weekly report, the change
   list (≤10, ranked by money), and Ads Editor CSVs for negatives/keywords/ads.
3. Reply with spend, bookings, cost per booking, and "Do these 3 first", with
   the click path for each.
