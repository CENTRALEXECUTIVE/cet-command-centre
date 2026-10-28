# Google Ads change log

One row per change **actually applied in the account** (date = day the director did it).
"Planned" rows are waiting to be applied. Each week, fill in "Result" for anything 2+ weeks old.

| Date | Change | Where | Reason | Metric to check in 2 weeks | Status / result |
|---|---|---|---|---|---|
| 2026-10-28 | Budget cut £60 → £30/day | Account | Directors' decision; half of spend booked nothing | Bookings/week and cost per booking vs previous 30d | Applied by directors (confirm date) |
| 2026-10-28 | Cleanup blueprint written (`2026-10-28-cleanup-blueprint.md`) | — | No exports yet; structure + settings + tracking plan | — | Done |
| planned | Auto-apply recommendations OFF | Account → Recommendations → Auto-apply | Stops Google adding broad keywords and raising budgets | Change history shows no "Google" automated changes | Planned |
| planned | Search partners + Display OFF; location = Sheffield + 25 mi, Presence only | All campaigns → Settings | Classic money leaks | User-locations report = 100% presence; network segment = Google search only | Planned |
| planned | Pause General, Weddings, Leeds Bradford, Birmingham, Gatwick, Heathrow (Heathrow merged into Airports & Towns) | Campaigns | No bookings / consolidate £30 into 3 campaigns | Spend on these = £0; overall cost per booking falls | Planned |
| planned | Maximise clicks → Manual CPC | All live campaigns | Max clicks optimises to cheap junk clicks | Avg CPC, conv. rate, cost per booking | Planned |
| planned | GTM: `cetBooking` listener → Ads conversion "CET web booking" (Primary, value, ref as transaction ID); quote/phone/WhatsApp/calls Secondary | GTM-WHH77L45 + Goals → Conversions | Bidding needs one true booking signal | Ads "CET web booking" count ≈ Command Centre web bookings with gclid | Planned |
| planned | Shared list `CET Master Negatives` (269 terms) on all campaigns | Tools → Shared library | Block jobs/cheap/transport/parking/abroad/etc. | Share of spend on irrelevant search terms (target < 10%) | Planned |
| planned | Rebuild: 3 campaigns / 11 ad groups / 164 exact+phrase keywords / 11 RSAs / account assets | `keywords.csv`, `ads.csv`, `ads-assets.csv` | Tight intent → matching landing pages | CTR by ad group, conv. rate, Quality Score (target 6+ on MAN/EMA exact) | Planned |
| planned | Budgets: MAN £11 · Airports & Towns £11 · Chauffeur & Corporate £8 | Campaign budgets | Concentrate on routes that book | Lost IS (budget) per campaign; cost per booking | Planned |
