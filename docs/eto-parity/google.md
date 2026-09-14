# ETO Google → CET

Two parts: **Google Ads conversion tracking** and **General route/geocoding options**.

## Google Ads / Analytics
ETO fires conversion events through the booking funnel (Conversion ID
`AW-16892093599` + per-event labels: booking location / vehicles / details /
payment / completed; customer register / activation / login / logout).

| ETO | CET | Rec | Notes |
|---|---|---|---|
| Ads conversion tracking on the funnel | 🟡 | Nice | CET has Ads *reporting* (`AdsSyncService`, Ads dashboard) but doesn't fire gtag conversion events step-by-step on `/book`. Worth adding the key ones (booking completed / payment) if Abdi runs Ads to the site. |
| Google Analytics | 🟡 | Nice | Add a GA tag to the public pages if wanted. |

## General (routing + geocoding)
| ETO setting | CET | Rec | Notes |
|---|---|---|---|
| Route type (suggested/fastest/shortest) | 🟡 | Nice | CET uses Google Directions/Distance (`DistanceService`); route-type is a param. |
| Avoid highways / tolls / **ferries** / straight-line | 🟡 | Nice | ETO has "avoid ferries" on for road pricing — fine; seaport *pickups* are separate. |
| Use address for directions | ✅ | — | CET geocodes addresses. |
| **Calculate return journey price same as outbound** | 🟡 | **Need-ish** | Confirm CET prices a return leg = outbound (returns are common). |
| Better use of postcode-based fixed price | 🟡 | Wait | Only relevant if we price by postcode zone — decide at the Pricing screens. |
| Allow / **Force** Google address suggestions | ✅ | — | `PlacesController` autocomplete; "force selection" avoids un-geocodable free text (which caused the Waze mismatch — worth forcing). |
| Min chars for suggestions | ✅ | — | Trivial config. |
| **Country restriction (UK + Ireland)** | 🟡 | Nice | Restrict autocomplete to GB/IE — fewer wrong matches. |
| Region restriction / preferred language | 🟡 | Skip | Auto/GB is fine. |

## Verdict
Routing/geocoding largely covered by CET's Google integration. Worth doing:
**force address-suggestion selection** + **UK/IE restriction** (both reduce
wrong-address bugs), and confirm **return = outbound pricing**. Ads conversion
events on the funnel are Nice if Abdi advertises to the site.
