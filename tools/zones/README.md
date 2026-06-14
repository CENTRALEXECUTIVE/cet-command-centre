# CET Pricing Zones — ETO KML builder

Builds a single ETO-importable KML of all CET pricing zones from **real ONS-derived
postcode boundary data** (Doogal). No hand-drawn coordinates — boundaries come
straight from postcode polygons, so zones stop bleeding into the wrong
councils/postcodes.

- `zone_spec.py` — the exact zone → postcode coverage and council-boundary rules
  (the agreed brief, encoded as data). **Edit this** to change coverage.
- `build_zones_kml.py` — fetches the boundary KML, merges per zone, writes one
  combined `cet_zones.kml`.
- `test_assembly.py` — offline self-test of the parse/merge/output logic.

## ⚠️ Why it can't run in the Claude Code sandbox

This environment's network egress allowlist **blocks `www.doogal.co.uk`** (and
`shapely` isn't installed). The builder is therefore written to run **where Doogal
is reachable**:

1. **On your own machine** (recommended): `pip install shapely` then run it; or
2. **In this environment** if you add `www.doogal.co.uk` to the session's network
   egress allowlist — then I can run it and hand back the finished `cet_zones.kml`; or
3. **Offline**: download the needed `<CODE>.kml` files from Doogal manually, drop
   them in `cache/`, and run with `--offline`.

## Run

```bash
pip install shapely          # recommended — enables clean unions + sector clipping
python3 build_zones_kml.py --out cet_zones.kml
# then import cet_zones.kml via ETO: Settings > Zones > Import
```

Use `--offline` to build from `cache/` only (no fetching).

## How council boundaries are kept correct

- **Whole-sector selection** handles most splits with no clipping: e.g. Barnsley
  Deep takes `S63 0/8/9` (Barnsley MBC) while Rotherham takes `S63 6/7`
  (Rotherham MBC); Barnsley takes only `S75 2/3`.
- **Sector subtraction** (needs shapely) handles "district minus a Wakefield/
  Sheffield sliver": `S72` minus `S72 9` (Havercroft/South Hiendley/Felkirk),
  and `S36` minus `S36 1/2/3` (Stocksbridge/Deepcar/Bolsterstone).
- Hemsworth (`WF9`, Wakefield) is its **own** zone, never inside a Barnsley zone.

### Known residual to confirm

- **Woolley Grange** is a Wakefield MBC pocket *inside* sector `S75 5`. Sector
  selection can't remove it. The builder reports it as a residual; to clip it
  out precisely, supply a small exclusion polygon (the only place a manual
  boundary is needed, and only because no smaller postcode unit isolates it).

### One thing to verify against Doogal

The brief confirms the **district** URL pattern (`/kml/S70.kml`). The builder
assumes **sectors** are served at `/kml/S75 2.kml` (URL-encoded). If Doogal does
not serve sector-level KML, the sector entries will fail to fetch and the script
will say so per zone — in which case those zones fall back to district + shapely
clipping (already supported via `district_minus_sectors`). Confirm this on the
first real run and I'll adjust the source pattern if needed.

## Not handled here

Airports (tight terminal boxes) and Central London are **destinations**, not
postcode-polygon pickup zones — they're configured separately in ETO and are out
of scope for this Doogal pipeline. `Mansfield` is left disabled in `zone_spec.py`
because the brief didn't specify its postcodes (likely NG18–NG21 — confirm).
