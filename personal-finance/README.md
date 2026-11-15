# Money HQ: personal finance app

A private personal-finance web app that replaces the **AutoPilot Money** spreadsheet
and the **Money Manager** phone app. It is completely separate from the CET Command
Centre Laravel app: it shares nothing with it, has no server and is never deployed with it.

## What it does

| Area | What you get |
| --- | --- |
| **Home** | This month's income, spending, what's left in your plan, savings rate, one-tap "quick add" chips for your most common entries (Uber, Bolt, fuel…), reminders for missed entries, due bills and backups, plus the AutoPilot dashboard (savings in 12 months, net worth, freedom date, debt-free date, surplus). |
| **Trans.** | Money Manager-style Daily / Calendar / Monthly views, search, filters, add/edit/delete, "Repeat" on any entry. |
| **Stats** | Donut + % list by category, Weekly / Monthly / Annually / UK tax year / custom period, tap a category for its breakdown and 12-month trend. |
| **Accounts** | Cash, Accounts, Card, Savings, Investments, Loans with balances; assets, liabilities and net worth; "set today's balance" to reconcile; monthly net-worth history. |
| **Budget plan** | The AutoPilot Money sheet: income, fixed / variable / optional sections, surplus, % of income, "give your surplus a job", and this month's plan vs actual. |
| **Goals** | Sinking funds (target, date, saved, needed per month, on-track) and savings/investment pots with 12-month and 10-year projections. |
| **Investments** | Holdings by ISA / LISA / SIPP / GIA, gain/loss, ISA allowance used this tax year (6 April reset). |
| **Debts** | Snowball vs avalanche, debt-free date, total interest, payment schedule. |
| **Freedom** | Freedom number (monthly need ÷ 4%), date, progress, "+£100 a month" what-if. |
| **Repeating** | Bills and regular income that log themselves (auto) or wait for one tap, so nothing gets forgotten. |
| **Import & bank** | Money Manager Excel export (all history, safe to re-import), bank CSV/OFX statements (Monzo, Starling, Revolut, Lloyds/Halifax, NatWest, Barclays, Nationwide, Santander, Chase, Amex, or any bank by picking columns), auto-categorising from your own history and rules, duplicate detection against what you typed in. |
| **Settings** | Passcode lock (AES-256 encryption at rest), backup/restore, categories (rename/merge), rules. |

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
npm test                      # unit tests for the maths and importers (Node 18+)
```

## Code map

- `js/calc.js` money maths: budget, sinking funds, compound growth, freedom date, debt snowball/avalanche, recurring dates, UK tax year
- `js/importers.js` CSV/OFX parsing, bank presets, Money Manager rows, categorising, duplicates
- `js/store.js` IndexedDB storage, encryption, defaults, example data
- `js/views-*.js` screens; `js/app.js` shell, routing, editor, import flows
- `tests/` `node --test` suites for `calc.js` and `importers.js`
