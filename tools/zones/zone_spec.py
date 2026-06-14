"""
CET pricing-zone specification — the single source of truth for the ETO zone
KML build. Encodes the exact postcode coverage and the council-boundary rules
agreed in the build brief. NO coordinates live here — only postcode membership.
Real boundary geometry is fetched from Doogal (ONS-derived) by the builder.

Member types
------------
- "districts":            whole postcode districts (e.g. "S70") — fetched whole.
- "sectors":              individual postcode sectors (e.g. "S75 2") — fetched
                          individually; used where a district straddles a council
                          boundary and only some sectors belong to the zone.
- "district_minus_sectors": fetch the whole district, then geometrically subtract
                          the listed sectors (needs shapely). Used for
                          "S72 excluding S72 9" style rules.
- "clip_exclude_note":    a within-sector council sliver that cannot be removed by
                          sector selection alone (e.g. Woolley Grange inside
                          S75 5). Reported as a residual unless a clip polygon is
                          supplied.

Council facts baked in (do NOT violate):
  * Hemsworth = WF9 = Wakefield MBC — never in any Barnsley zone (own zone).
  * Havercroft / South Hiendley / Felkirk = S72 9 = Wakefield — exclude.
  * Woolley Grange = inside S75 5 = Wakefield — exclude (residual clip).
  * Thurnscoe / Goldthorpe / Bolton-upon-Dearne = S63 0/8/9 = Barnsley MBC
    (Rotherham post town but Barnsley council) — belong to Barnsley Deep.
  * Wath-upon-Dearne / West Melton / Brampton Bierlow = S63 6/7 = Rotherham MBC
    — belong to Rotherham, NOT Barnsley.
  * Stocksbridge / Deepcar / Bolsterstone = S36 1/2/3 = Sheffield MBC — exclude
    from Penistone.
  * Barnsley MBC northern boundary ~53.615N — nothing above belongs to Barnsley.
"""

# Each zone maps to a member spec. Order of keys does not matter.
ZONES: dict[str, dict] = {
    "Barnsley": {
        # Normal Barnsley — closer to Sheffield.
        "districts": ["S70", "S73", "S74"],
        "sectors": ["S75 2", "S75 3"],  # Dodworth, Tankersley, Gawber, Pogmoor
    },
    "Barnsley Deep": {
        # Further from Sheffield.
        "districts": ["S71"],
        "district_minus_sectors": {"S72": ["S72 9"]},  # exclude Wakefield sliver
        "sectors": [
            "S75 1", "S75 5", "S75 6",          # exclude Woolley Grange (below)
            "S63 0", "S63 8", "S63 9",          # Thurnscoe, Goldthorpe, Bolton-upon-Dearne (Barnsley MBC)
        ],
        "clip_exclude_note": ["Woolley Grange (Wakefield MBC) inside S75 5"],
    },
    "Penistone": {
        # Barnsley MBC portion of S36 only.
        "district_minus_sectors": {"S36": ["S36 1", "S36 2", "S36 3"]},  # exclude Stocksbridge/Deepcar/Bolsterstone (Sheffield MBC)
    },
    "Chesterfield": {
        "districts": ["S40", "S41"],
    },
    "Chesterfield Deep": {
        # S43 deliberately excluded (Staveley/Brimington/Clowne feel like Sheffield outskirts).
        "districts": ["S42", "S44", "S45"],
    },
    "Doncaster": {
        "districts": ["DN1", "DN2", "DN3", "DN4", "DN5"],
    },
    "Doncaster Deep": {
        # DN10 (65% Notts) and DN11 (crosses to Notts at Harworth/Bircotes) excluded.
        "districts": ["DN6", "DN7", "DN8", "DN9", "DN12"],
    },
    "Rotherham": {
        "districts": ["S60", "S61", "S62", "S65", "S66"],
        "sectors": ["S63 6", "S63 7"],  # Wath-upon-Dearne, West Melton, Brampton Bierlow, Manvers (Rotherham MBC)
    },
    "Sheffield": {
        "districts": [
            "S1", "S2", "S3", "S4", "S5", "S6", "S7", "S8", "S9", "S10",
            "S11", "S12", "S13", "S14", "S17", "S18", "S19", "S35",
        ],
    },
    "Sheffield S20": {
        # Kept separate — near M1 J31, different fare dynamics.
        "districts": ["S20"],
    },
    "Worksop": {
        "districts": ["S80", "S81"],
    },
    "Matlock": {
        "districts": ["DE4"],
    },
    "Hemsworth": {
        # WF9 — Wakefield MBC. Its own zone, never part of any Barnsley zone.
        "districts": ["WF9"],
    },
    # Mansfield postcodes were not specified in the brief; confirm before enabling.
    # Typical coverage is NG18–NG21. Left disabled to avoid guessing.
    # "Mansfield": {"districts": ["NG18", "NG19", "NG20", "NG21"]},
}

# Airports and Central London are NOT postcode-polygon zones. Airports are tight
# terminal-level boxes and Central London is a destination region — both are
# handled separately in ETO, not by this Doogal postcode pipeline.
NON_POSTCODE_ZONES = [
    "Manchester Airport", "Leeds Bradford Airport", "East Midlands Airport",
    "Humberside Airport", "Birmingham Airport", "Heathrow Airport",
    "Gatwick Airport", "Luton Airport", "Stansted Airport", "Central London",
]
