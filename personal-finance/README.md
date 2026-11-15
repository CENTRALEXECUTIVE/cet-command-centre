# Money HQ: personal finance app

A private personal-finance web app that replaces the **AutoPilot Money** spreadsheet
and the **Money Manager** phone app. It is completely separate from the CET Command
Centre Laravel app: it shares nothing with it, has no server and is never deployed with it.

## What it does

| Area | What you get |
| --- | --- |
| **Home** | This month's income, spending, what's left in your plan, savings rate, one-tap "quick add" chips for your most common entries (Uber, Bolt, fuel…), reminders for missed entries, due bills and backups, plus the AutoPilot dashboard (savings in 12 months, net worth, freedom date, debt-free date, surplus). |
| **Insights** | Ask a question in plain English (answered on the device), "Left before payday" safe-to-spend with the bills still to come, "Does anything look off?" (double charges, fees, unusual amounts, price rises, regular payments missing from Repeating), regular payments found in your history, a 0–100 money score, and a self-employed tax-pot estimate for the UK tax year. |
| **Trans.** | Money Manager-style Daily / Calendar / Monthly views, search, filters (incl. personal / business), add/edit/delete, split one payment across categories, "Business" tag, "Repeat" on any entry. |
| **Stats** | Donut + % list by category, Weekly / Monthly / Annually / UK tax year / custom period, tap a category for its breakdown and 12-month trend. |
| **Accounts** | Cash, Accounts, Card, Savings, Investments, Loans with balances; assets, liabilities and net worth; "set today's balance" to reconcile; monthly net-worth history. |
| **Budget plan** | The AutoPilot Money sheet: income, fixed / variable / optional sections, surplus, % of income, "give your surplus a job", and this month's plan vs actual. |
| **Goals** | Sinking funds (target, date, saved, needed per month, on-track) and savings/investment pots with 12-month and 10-year projections. |
| **Investments** | Holdings by ISA / LISA / SIPP / GIA, gain/loss, ISA allowance used this tax year (6 April reset). |
| **Debts** | Snowball vs avalanche, debt-free date, total interest, payment schedule. |
| **Freedom** | Freedom number (monthly need ÷ 4%), date, progress, "+£100 a month" what-if. |
| **Repeating** | Bills and regular income that log themselves (auto) or wait for one tap, so nothing gets forgotten. |
| **Import & bank** | Money Manager Excel export (all history, safe to re-import), PDF statements (NatWest-style layout; several at once; checked against the bank's own totals; own transfers and anything already in your log left out; sets the account balance), bank CSV/OFX statements (Monzo, Starling, Revolut, Lloyds/Halifax, NatWest, Barclays, Nationwide, Santander, Chase, Amex, or any bank by picking columns), auto-categorising from your own history and rules, duplicate detection against what you typed in. |
| **Halal investing** | Shariah status per holding, share of the portfolio checked compliant, zakat calculator (gold or silver nisab, 2.5%), AAOIFI company screen, dividend purification, a halal roadmap and a researched shortlist (iShares Islamic ETFs, HSBC Islamic Global Equity, Wahed). |
| **Sync** | Phone ⇄ computer through the claude.ai link: compressed and AES-256 encrypted on the device with a sync passcode before it is stored in your private area. Merges both devices' entries. |
| **Settings** | Passcode lock (AES-256 encryption at rest), backup/restore, categories (rename/merge), rules. |

## Asking Claude (optional)

Questions are answered on the device by `js/insights.js`. When the app runs as a
claude.ai artifact, an extra "Ask Claude for a deeper answer" button appears. Pressing it
sends a summary (monthly totals, category totals, bills, goals, debts and the 60 latest
entries) to Claude for that one question. Nothing is sent unless that button is pressed.
The self-hosted version has no such button.

## Privacy and safety

- All data is stored **only in the browser on your device** (IndexedDB). There is no server and no account, and nothing is uploaded.
- An optional passcode encrypts the stored data with AES-GCM (key from PBKDF2, 250k iterations).
- Statement and Money Manager files are read in the browser and never stored as files.
- The spreadsheet reader (SheetJS 0.18.5) is loaded from jsDelivr only when you import an `.xlsx`, pinned with a subresource-integrity hash.
- **Back up regularly** (Settings → Download backup). Clearing the browser's site data erases it.
- Your real figures are never committed to this repo. The app opens on clearly labelled example data.

## Open Banking (live bank connection)

Not connected yet, by design. A live feed needs a regulated provider (TrueLayer, Yapily,
Enable Banking, Plaid) whose secret keys must sit on a small private server, not in a web
page. Most providers now charge per connection, and GoCardless/Nordigen's free tier has
closed to new sign-ups. Statement import is the free option until a provider is chosen.
Access is read-only and approved in your own bank app.

## Run it

It's static files with no build step. Any static host works (Cloudflare Pages, Netlify, GitHub Pages
on a private repo, or `python3 -m http.server` locally). Open it on your phone and use
"Add to Home Screen" to install it like an app (works offline).

```bash
cd personal-finance
python3 -m http.server 8080   # then open http://localhost:8080
npm test                      # unit tests for the maths, importers and insights (Node 18+)
node tests/e2e.cjs            # browser test of every screen and feature (needs Playwright; server on :8765)
```

## Code map

- `js/calc.js` money maths: budget, sinking funds, compound growth, freedom date, debt snowball/avalanche, recurring dates, UK tax year
- `js/importers.js` CSV/OFX parsing, bank presets, Money Manager rows, categorising, duplicates
- `js/insights.js` checks, payday, subscriptions, money score, tax estimate, question engine
- `js/store.js` IndexedDB storage, encryption, defaults, example data
- `js/views-*.js` screens; `js/app.js` shell, routing, editor, import flows
- `tests/` `node --test` suites for `calc.js` and `importers.js`

## Claude Code skills in this folder

- `.claude/skills/watch-video` — give Claude a TikTok / Instagram / YouTube link and it downloads
  the video, transcribes it, saves stills and reviews it (yt-dlp, ffmpeg, faster-whisper; free).
  Works fully on your own computer; cloud sessions usually only get the caption and stats.
- `.claude/skills/market-brief` — a dated, sourced halal market and investing brief using the
  built-in web search (no API keys).
