# ETO Payment Methods → CET

ETO ships ~19 payment "methods" (mostly card gateways). Only **3 are actually
active** for CET: **Account**, **Cash**, and **Square** (Square is the default).
Everything else is off — they're just gateway options ETO bundles for other firms.

Each method in ETO has: logo image, name, description, gateway, **payment charge
(surcharge %)**, which **services** + **vehicle types** it applies to, **default**,
**ordering**, **active**, and **display** (Frontend / Backend / both).

## The methods that matter (ETO active) → CET

| ETO method | CET | Rec | Notes |
|---|---|---|---|
| **Account** (reserve now, bill the company) | ✅ | — | Corporate accounts + invoices exist. Add it as a form option for account customers (see widget "account payment" Need). |
| **Cash** | ✅ | — | Cash jobs + the cash-collection / payroll flow exist. |
| **Square** (card, default) | ✅ | — | `SquareBookingPaymentService` (pay-in-full on web) + `SquareTipService`. |
| BACS / bank transfer | 🟡 | Nice | `TideService` gives bank-transfer links; could surface as a "bank transfer" method. |
| Stripe, PayPal, Worldpay, Redsys, SumUp, Barclaycard, Cardsave, ChipCard, GP webpay, PayTabs, Payzone, Svea, Stripe iDEAL, Offline, Wpop | ❌ | **Skip** | Other-market gateways. CET standardises on Square for card. No reason to carry 15 dead gateways. |

## Per-method config ETO exposes → CET

| ETO control | CET | Rec | Notes |
|---|---|---|---|
| Active on/off per method | 🟡 | Nice | CET gates by whether the gateway is configured; a simple on/off admin is nice-to-have. |
| Default method | 🟡 | Nice | CET defaults sensibly per job; explicit "default" is minor. |
| Ordering | ❌ | Skip | Only 3 methods — ordering barely matters. |
| **Payment charge (surcharge %)** | ❌ | **Ask** | Do we ever add a card-processing surcharge? (Surcharging consumer cards is restricted in the UK — likely Skip.) |
| Restrict by service / vehicle type | ❌ | Nice | e.g. cash only on certain jobs. |
| Display: Frontend / Backend | 🟡 | Nice | Some methods office-only (Account, Cash) vs shown to web customers (Card). |
| **Disable cash for airport / seaport pickups** (from the widget screen) | ❌ | Nice | Payment-rule per pickup type — belongs with this. |

## Verdict
CET already covers the three methods CET actually uses (Account, Cash, Card via
Square) — so this is **not a go-live blocker**. The only worthwhile additions are
a small **payment-methods admin** (on/off, frontend vs office-only, and the
"no cash on airport jobs" rule) — all **Nice**. The 15 unused gateways: **Skip**.

**Decision needed:** card surcharge % — do we ever charge it? (Default: no.)
