# ETO Locations → CET

ETO's saved-locations library (exported CSV kept at
[`data/eto-locations.csv`](data/eto-locations.csv)). Four categories:

| Category | Count | What it is |
|---|---|---|
| Airports | 41 | Named UK airports + terminals, most with postcode, lat/long and **search keywords** (e.g. "LHR, London Heathrow"). |
| Cruise Ports | 4 | Dover, Harwich, Portsmouth, Southampton. |
| Address | 1 | Sheffield (S5 7TB) — the base. |
| Post code | 201 | London / Home-Counties postcode **zones** (CR, E, EC, HA, HP, KT, N, NW, SE, SL, SW, TW, UB, W, WC) — ETO fixed-price zones for London-airport work. |

## CET today
- **Airports:** CET has an `Airport` model (codes/detection) used for rotation and
  airport handling. The 41-row list here is a richer pick-list + keyword set.
- **Seaports / cruise ports:** ❌ none yet — needed now ferries are in scope.
- **Address autocomplete:** Google Places (`PlacesController`) covers arbitrary
  addresses, so CET doesn't *need* a giant saved-address list to function.
- **Postcode zones:** CET prices by fixed matrix + distance, not postcode zones.

| Item | CET | Rec | Notes |
|---|---|---|---|
| Airport pick-list + keywords + lat/long | 🟡 | **Nice→Need** | Import to enrich airport detection/quick-pick; low risk, high value. |
| **Cruise ports (Dover, Harwich, Portsmouth, Southampton)** | ❌ | **Need** | Seed a seaport library — pairs with the ferry fields already on the Need list. |
| Base address (Sheffield) | ✅ | — | Already configured (`cet.base`). |
| 201 London postcode zones | ❌ | **Wait** | Only relevant if we price by postcode zone — decide when the **Pricing** screens arrive. Likely legacy London-airport zones, not CET's Sheffield pricing. |

## Plan
- Import the **airports** + **cruise ports** at build time (seeder from this CSV)
  — quick-pick, detection, and seaport support for ferries.
- Hold the **postcode zones** until the Pricing screens tell us whether CET prices
  by zone at all (probably not — Sheffield executive is distance/fixed-matrix).
- We do **not** need to import the raw address list — Google Places covers it.
