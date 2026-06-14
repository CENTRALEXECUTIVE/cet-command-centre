#!/usr/bin/env python3
"""
Build a single ETO-ready KML of all CET pricing zones from REAL postcode
boundary data (Doogal / ONS-derived). No hand-drawn coordinates.

Pipeline per zone (see zone_spec.py):
  1. Fetch each district / sector KML from Doogal (cached locally).
  2. Parse polygon geometry from each.
  3. Apply council-boundary rules:
       - whole districts and whole sectors are unioned in,
       - "district_minus_sectors" subtracts Wakefield/Sheffield slivers
         (requires shapely),
  4. Emit one named <Placemark> per zone into one <Document>.

Usage:
    python3 build_zones_kml.py --out cet_zones.kml
    python3 build_zones_kml.py --offline --cache-dir cache   # use cached files only

Requirements:
    - Network access to www.doogal.co.uk (add to egress allowlist if blocked), OR
      pre-downloaded KML files dropped into the cache dir named "<CODE>.kml".
    - shapely (optional but recommended) for clean unions and sector subtraction:
          pip install shapely
      Without shapely the script still runs (raw multi-polygon passthrough) but
      cannot subtract sectors and will report the residual slivers.
"""
from __future__ import annotations

import argparse
import os
import sys
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from zone_spec import ZONES  # noqa: E402

DOOGAL_BASE = "https://www.doogal.co.uk/kml/"
KML_NS = "http://www.opengis.net/kml/2.2"

try:
    from shapely.geometry import Polygon, MultiPolygon
    from shapely.ops import unary_union
    HAVE_SHAPELY = True
except Exception:  # pragma: no cover - depends on environment
    HAVE_SHAPELY = False


# --------------------------------------------------------------------------
# Fetch + cache
# --------------------------------------------------------------------------
def fetch_kml(code: str, cache_dir: str, offline: bool) -> str | None:
    """Return the KML text for a postcode code, from cache or Doogal."""
    os.makedirs(cache_dir, exist_ok=True)
    cache_path = os.path.join(cache_dir, f"{code}.kml")
    if os.path.exists(cache_path):
        with open(cache_path, encoding="utf-8") as fh:
            return fh.read()
    if offline:
        print(f"  ! offline and not cached: {code}", file=sys.stderr)
        return None

    url = DOOGAL_BASE + urllib.parse.quote(code) + ".kml"
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0 (CET zone builder)"})
        with urllib.request.urlopen(req, timeout=30) as resp:
            text = resp.read().decode("utf-8", "replace")
        if "<kml" not in text.lower():
            print(f"  ! {code}: response was not KML ({url})", file=sys.stderr)
            return None
        with open(cache_path, "w", encoding="utf-8") as fh:
            fh.write(text)
        return text
    except Exception as exc:  # pragma: no cover - network dependent
        print(f"  ! fetch failed for {code}: {exc}", file=sys.stderr)
        return None


# --------------------------------------------------------------------------
# KML polygon parsing
# --------------------------------------------------------------------------
def _strip_ns(tag: str) -> str:
    return tag.split("}", 1)[-1]


def parse_rings(kml_text: str) -> list[tuple[list, list]]:
    """Return a list of (outer_ring, [inner_rings]) coordinate tuples.

    Each ring is a list of (lon, lat) pairs.
    """
    rings: list[tuple[list, list]] = []
    try:
        root = ET.fromstring(kml_text)
    except ET.ParseError as exc:
        print(f"  ! KML parse error: {exc}", file=sys.stderr)
        return rings

    for poly in root.iter():
        if _strip_ns(poly.tag) != "Polygon":
            continue
        outer: list = []
        inners: list = []
        for child in poly.iter():
            tag = _strip_ns(child.tag)
            if tag in ("outerBoundaryIs", "innerBoundaryIs"):
                coords_el = child.find(f".//{{{KML_NS}}}coordinates")
                if coords_el is None:
                    # namespace-agnostic fallback
                    for el in child.iter():
                        if _strip_ns(el.tag) == "coordinates":
                            coords_el = el
                            break
                if coords_el is None or not (coords_el.text or "").strip():
                    continue
                ring = _parse_coords(coords_el.text)
                if tag == "outerBoundaryIs":
                    outer = ring
                else:
                    inners.append(ring)
        if outer:
            rings.append((outer, inners))
    return rings


def _parse_coords(text: str) -> list:
    pts = []
    for token in text.replace("\n", " ").split():
        parts = token.split(",")
        if len(parts) >= 2:
            try:
                pts.append((float(parts[0]), float(parts[1])))
            except ValueError:
                continue
    return pts


# --------------------------------------------------------------------------
# Geometry assembly
# --------------------------------------------------------------------------
def rings_to_shapely(rings):
    polys = []
    for outer, inners in rings:
        if len(outer) >= 4:
            try:
                polys.append(Polygon(outer, inners))
            except Exception:
                continue
    return polys


def shapely_to_kml_polygons(geom) -> str:
    """Render a shapely (Multi)Polygon as KML <Polygon> elements."""
    polys = list(geom.geoms) if isinstance(geom, MultiPolygon) else [geom]
    out = []
    for poly in polys:
        if poly.is_empty:
            continue
        outer = " ".join(f"{x},{y},0" for x, y in poly.exterior.coords)
        inner_xml = ""
        for ring in poly.interiors:
            coords = " ".join(f"{x},{y},0" for x, y in ring.coords)
            inner_xml += (
                "<innerBoundaryIs><LinearRing><coordinates>"
                f"{coords}</coordinates></LinearRing></innerBoundaryIs>"
            )
        out.append(
            "<Polygon><outerBoundaryIs><LinearRing><coordinates>"
            f"{outer}</coordinates></LinearRing></outerBoundaryIs>{inner_xml}</Polygon>"
        )
    return "".join(out)


def rings_to_kml_polygons(rings) -> str:
    """Render raw rings as KML <Polygon> elements (no geometric processing)."""
    out = []
    for outer, inners in rings:
        outer_xml = " ".join(f"{x},{y},0" for x, y in outer)
        inner_xml = ""
        for ring in inners:
            coords = " ".join(f"{x},{y},0" for x, y in ring)
            inner_xml += (
                "<innerBoundaryIs><LinearRing><coordinates>"
                f"{coords}</coordinates></LinearRing></innerBoundaryIs>"
            )
        out.append(
            "<Polygon><outerBoundaryIs><LinearRing><coordinates>"
            f"{outer_xml}</coordinates></LinearRing></outerBoundaryIs>{inner_xml}</Polygon>"
        )
    return "".join(out)


def build_zone_geometry(name: str, spec: dict, cache_dir: str, offline: bool, warnings: list) -> str:
    """Return the inner KML geometry (<MultiGeometry>…) for one zone."""
    include_rings: list = []   # raw rings from whole districts/sectors
    subtract_jobs = []         # (district_code, [sector_codes]) for shapely difference

    for code in spec.get("districts", []):
        rings = _rings_for(code, cache_dir, offline, warnings)
        include_rings.extend(rings)

    for code in spec.get("sectors", []):
        rings = _rings_for(code, cache_dir, offline, warnings)
        if not rings:
            warnings.append(f"{name}: sector '{code}' unavailable (Doogal may not serve sector KML).")
        include_rings.extend(rings)

    for district, excl in spec.get("district_minus_sectors", {}).items():
        subtract_jobs.append((district, excl))

    for note in spec.get("clip_exclude_note", []):
        warnings.append(f"{name}: residual sliver not removed by sector selection — {note}.")

    # Subtraction needs shapely.
    geoms = []
    if HAVE_SHAPELY:
        geoms.extend(rings_to_shapely(include_rings))
        for district, excl in subtract_jobs:
            base = rings_to_shapely(_rings_for(district, cache_dir, offline, warnings))
            cut = []
            for sector in excl:
                cut.extend(rings_to_shapely(_rings_for(sector, cache_dir, offline, warnings)))
            if base:
                geom = unary_union(base)
                if cut:
                    geom = geom.difference(unary_union(cut))
                geoms.append(geom)
        if not geoms:
            return ""
        merged = unary_union(geoms)
        return "<MultiGeometry>" + shapely_to_kml_polygons(merged) + "</MultiGeometry>"

    # No shapely: raw passthrough; subtractions become whole districts + warning.
    for district, excl in subtract_jobs:
        include_rings.extend(_rings_for(district, cache_dir, offline, warnings))
        warnings.append(
            f"{name}: cannot subtract {excl} from {district} without shapely — "
            f"whole {district} included (install shapely to clip)."
        )
    if not include_rings:
        return ""
    return "<MultiGeometry>" + rings_to_kml_polygons(include_rings) + "</MultiGeometry>"


def _rings_for(code: str, cache_dir: str, offline: bool, warnings: list):
    text = fetch_kml(code, cache_dir, offline)
    if not text:
        warnings.append(f"missing geometry: {code}")
        return []
    return parse_rings(text)


# --------------------------------------------------------------------------
# Document assembly
# --------------------------------------------------------------------------
def build_document(zones: dict, cache_dir: str, offline: bool) -> tuple[str, list]:
    warnings: list = []
    placemarks = []
    for name, spec in zones.items():
        print(f"- {name}")
        geometry = build_zone_geometry(name, spec, cache_dir, offline, warnings)
        if not geometry:
            warnings.append(f"{name}: no geometry produced — skipped.")
            continue
        placemarks.append(
            f"  <Placemark>\n    <name>{_xml_escape(name)}</name>\n"
            f"    {geometry}\n  </Placemark>"
        )

    doc = (
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        f'<kml xmlns="{KML_NS}">\n<Document>\n'
        "  <name>CET Pricing Zones</name>\n"
        + "\n".join(placemarks)
        + "\n</Document>\n</kml>\n"
    )
    return doc, warnings


def _xml_escape(text: str) -> str:
    return (text.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def main() -> int:
    ap = argparse.ArgumentParser(description="Build CET pricing-zone KML from Doogal data.")
    ap.add_argument("--out", default="cet_zones.kml", help="Output KML path")
    ap.add_argument("--cache-dir", default=os.path.join(os.path.dirname(__file__), "cache"))
    ap.add_argument("--offline", action="store_true", help="Use cached KML only; never fetch")
    args = ap.parse_args()

    if not HAVE_SHAPELY:
        print("! shapely not installed — running raw passthrough (no sector clipping).", file=sys.stderr)

    doc, warnings = build_document(ZONES, args.cache_dir, args.offline)
    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(doc)

    print(f"\nWrote {args.out}")
    if warnings:
        print(f"\n{len(warnings)} warning(s):")
        for w in warnings:
            print(f"  - {w}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
