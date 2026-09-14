# ETO Localization → CET

ETO: default language, a multi-language switcher (English, Spanish, Portuguese,
French, Italian, Dutch, German, Polish, Czech, Hungarian, Russian, Arabic…),
phone prefix, timezone, date/time format, week start, unit format.

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Default language English | ✅ | — | CET is English. |
| Multi-language switcher (12+ languages) | ❌ | **Skip** | CET is a single UK firm — no need to translate the UI/booking form into 12 languages. Clutter. |
| Phone prefix (auto / +44) | ✅ | — | `Phone::wa()` normalises to +44. |
| **Timezone Europe/London** | ✅ | — | CET is pinned to `Europe/London` (a core, hard-won rule). |
| Date format DD/MM/YYYY | ✅ | — | CET uses UK dates throughout. |
| Time format 24h | ✅ | — | |
| Week starts Monday | ✅ | — | |
| Unit format Imperial (miles) | ✅ | — | CET prices/among distances in miles. |

## Verdict
**Nothing to build.** CET already matches every setting that matters (UK English,
Europe/London, DD/MM, 24h, Monday, miles). The one thing ETO has that CET
doesn't — a 12-language switcher — is deliberately **skipped**: it's a white-label
feature, not something a Sheffield executive firm needs.
