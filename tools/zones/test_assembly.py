#!/usr/bin/env python3
"""
Offline self-test for the zone KML builder. Proves the parse + assembly + KML
output logic using synthetic cached KML files — no network, no shapely needed.

Run:  python3 tools/zones/test_assembly.py
"""
import os
import sys
import tempfile

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import build_zones_kml as b  # noqa: E402

FAKE_KML = """<?xml version="1.0" encoding="UTF-8"?>
<kml xmlns="http://www.opengis.net/kml/2.2"><Document>
<Placemark><name>{code}</name><Polygon><outerBoundaryIs><LinearRing>
<coordinates>{coords}</coordinates>
</LinearRing></outerBoundaryIs></Polygon></Placemark>
</Document></kml>
"""


def write_fake(cache_dir, code, coords):
    with open(os.path.join(cache_dir, f"{code}.kml"), "w", encoding="utf-8") as fh:
        fh.write(FAKE_KML.format(code=code, coords=coords))


def main() -> int:
    failures = []
    with tempfile.TemporaryDirectory() as cache:
        # Two distinct square-ish rings.
        write_fake(cache, "S70", "-1.49,53.55,0 -1.45,53.55,0 -1.45,53.58,0 -1.49,53.58,0 -1.49,53.55,0")
        write_fake(cache, "S75 2", "-1.55,53.55,0 -1.50,53.55,0 -1.50,53.58,0 -1.55,53.58,0 -1.55,53.55,0")

        zones = {"Barnsley": {"districts": ["S70"], "sectors": ["S75 2"]}}
        doc, warnings = b.build_document(zones, cache, offline=True)

        checks = {
            "has kml root": doc.strip().startswith("<?xml") and "<kml" in doc,
            "zone named": "<name>Barnsley</name>" in doc,
            "one placemark": doc.count("<Placemark>") == 1,
            "multigeometry": "<MultiGeometry>" in doc,
            "two polygons merged in": doc.count("<Polygon>") == 2,  # district + sector (raw path)
            "coordinates present": "-1.49,53.55,0" in doc and "-1.55,53.55,0" in doc,
            "no missing-geometry warning": not any("missing geometry" in w for w in warnings),
        }
        for label, ok in checks.items():
            if not ok:
                failures.append(label)

        # Missing geometry is reported, not silently dropped.
        zones2 = {"Empty": {"districts": ["DOES_NOT_EXIST"]}}
        _, warnings2 = b.build_document(zones2, cache, offline=True)
        if not any("missing geometry" in w for w in warnings2):
            failures.append("missing geometry not warned")

    if failures:
        print("FAIL:", ", ".join(failures))
        return 1
    print(f"OK — assembly self-test passed (shapely={'yes' if b.HAVE_SHAPELY else 'no'})")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
