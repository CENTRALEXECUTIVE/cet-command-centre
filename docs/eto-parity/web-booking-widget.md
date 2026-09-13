# ETO Web Booking Widget → CET

The customer-facing online booking form (ETO: Settings → Web Booking Widget).
CET equivalents: public form `/book` (`PublicBookingController`) and the
embeddable `/widget/book` (`BookingWidgetController`), configured on the admin
**Web widgets** page.

## General

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Set web booking form offline | ❌ | Nice | Add an on/off so the form can be paused. |
| "Book by phone" option in steps 2–3 | ❌ | Nice | Show the office number as a fallback. |
| "Powered by EasyTaxiOffice" footer | — | Skip | ETO branding; N/A. |
| Web booking WITH / WITHOUT price | ✅ | — | CET shows prices; can add a "hide price" mode. |
| Fixed + Distance / Fixed only / Distance only | 🟡 | Nice | CET does fixed **and** distance; add a mode toggle. |
| Allow booking without price when address not found | 🟡 | — | CET routes "price on request" to an office enquiry. |
| Outside contact form when price = 0 | 🟡 | — | Same enquiry path covers this. |
| Allow fixed price outside operating area | 🟡 | Skip | Executive = wide area; low value. |
| Price same-zone stopovers as one fixed journey | 🟡 | Nice | Pricing engine choice. |
| **Minimum booking notice (hours)** | ❌ | **Need** | Reject/flag web bookings inside X h of pickup (ETO = 8h). |
| **New bookings confirmed / unconfirmed + auto-confirm threshold** | 🟡 | **Need** | Make the confirm-if-far-enough / reject-if-too-soon policy configurable. |
| Thank-you page: new booking / custom URL | ❌ | Nice | Redirect back to the CET marketing site after booking. |

## Step 1 (journey)

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Mini header (QUOTE & BOOK) | — | Skip | Cosmetic. |
| Vehicle capacity automatic selection | 🟡 | Nice | Pre-pick a vehicle from pax/luggage. |
| **Display return journey button** | ❌ | **Need** | Returns are common; add one-tap return on the form. |
| Display swap address button | ❌ | Nice | Small UX win. |
| Display "get current location" button | ❌ | Nice | Handy on mobile. |
| Hide service dropdown if one option | 🟡 | Skip | Cosmetic. |
| Stopovers count | ✅ | — | CET supports via stops (cap configurable). |
| Service display mode (Tabs/…) | — | Skip | Cosmetic. |

## Step 2 (vehicle + quote)

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Enable meet & greet | ✅ | — | Collected on `/book`. |
| Meet & greet compulsory for airport / seaport | 🟡 | Nice | Force it on for airport pickups. |
| Show pax / luggage / hand / child / booster / infant / wheelchair icons | ✅ | — | All collected; icons cosmetic. |
| Vehicle display mode / order / summary / map zoom | — | Skip | Cosmetic. |
| Route map (draggable / zoom / scroll / directions) | 🟡 | Nice | CET shows a quote, not necessarily a live route map. |
| Show "Make an offer" | ❌ | Skip | Haggling — off-brand for executive. |
| Show "Chat" | ❌ | Skip | Use WhatsApp instead. |
| "Email this quote" | 🟡 | Nice | Let a customer email themselves the quote. |

## Step 3 (details) + field requirements

| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Full address enable/require — pickup / dropoff / via | 🟡 | Nice | CET collects addresses; per-field require toggles missing. |
| Require passengers / luggage / hand luggage / wheelchair | 🟡 | Nice | Collected; "require" toggles missing. |
| Require / allow child, booster, infant seats | 🟡 | Nice | Collected as counts. |
| **Flight number — enable / require** | ✅ | — | Captured. |
| **Flight time — enable / require** | ❌ | **Need** | Feeds set-off/lead-time on airport jobs. |
| Flight from (city) | ❌ | Nice | |
| **Waiting time after landing** | ❌ | **Need** | The ~30-min "customer comes out" buffer Abdi wants. |
| Flight dropoff: number / time / to (city) | 🟡 | Nice | |
| Adjust pickup time for airport drop-offs to X min before departure | ❌ | Nice | Auto-set kerb time from flight departure. |
| Ferry details (name / time / terminal) pickup + dropoff | ❌ | **Ask** | Do we do ferry/seaport jobs? If not → Skip. |
| Require contact mobile | ✅ | — | |
| Allow "book for someone else" | ✅ | — | Booker vs lead passenger already modelled. |
| Allow comments (customer requirements) | ✅ | — | `special_requests`. |
| Discount code field | ✅ | — | Vouchers. |
| **Account payment for company users** | 🟡 | **Need** | Let corporate-account customers book on account (no card). |
| Disable cash for airport / seaport pickups | 🟡 | Nice | Payment-method rule per pickup type. |
| Member benefits list | ❌ | Skip | Marketing fluff. |
| Guest bookings | ✅ | — | No login required. |

## Verdict for this screen
CET already does the core of ETO's widget (quote → pick vehicle → details → pay).
The **Need** items are: minimum booking notice, configurable auto-confirm policy,
return journey on the form, flight time + waiting-after-landing capture, and
account payment for company users. Everything else is Nice or Skip.
