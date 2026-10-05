<?php

namespace App\Support;

/**
 * One place that works out which airport an address refers to — used for the title
 * WHERE word, the Arrival/Departure/Transfer classification, and driver rotation.
 *
 * It matches, in order: a bracketed IATA code "(MAN)", an airport name/postcode
 * alias ("Manchester Airport", "M90"), and — the case that used to slip through —
 * a TERMINAL reference next to a city ("Terminal 2, Manchester", "T2 Manchester"),
 * which is unmistakably that city's airport even though the word "airport" is absent.
 */
class AirportMatcher
{
    /** IATA code → name / postcode-area aliases. */
    private const ALIASES = [
        'MAN' => ['manchester airport', 'm90'],
        'LHR' => ['heathrow', 'tw6'],
        'LGW' => ['gatwick', 'rh6'],
        'STN' => ['stansted', 'cm24'],
        'EMA' => ['east midlands airport', 'nottingham east midlands', 'de74'],
        'LBA' => ['leeds bradford', 'ls19'],
        'BHX' => ['birmingham airport', 'b26'],
        'LPL' => ['liverpool john lennon', 'liverpool airport', 'l24'],
        'HUY' => ['humberside airport', 'dn39'],
        'LTN' => ['luton airport', 'london luton', 'lu2'],
        'DSA' => ['doncaster sheffield', 'robin hood airport'],
        'NCL' => ['newcastle airport'],
        'EDI' => ['edinburgh airport'],
        'GLA' => ['glasgow airport'],
        'BRS' => ['bristol airport'],
    ];

    /**
     * City keyword → code, used ONLY when a terminal reference is present (so a
     * plain city-centre address is never misread as its airport). London is left
     * out on purpose — a "Terminal, London" is ambiguous between LHR/LGW/STN/LTN,
     * so the named aliases above must carry it.
     */
    private const TERMINAL_CITIES = [
        'manchester' => 'MAN',
        'birmingham' => 'BHX',
        'liverpool' => 'LPL',
        'leeds' => 'LBA',
        'bradford' => 'LBA',
        'east midlands' => 'EMA',
        'humberside' => 'HUY',
        'doncaster' => 'DSA',
        'newcastle' => 'NCL',
        'edinburgh' => 'EDI',
        'glasgow' => 'GLA',
        'bristol' => 'BRS',
        // London airports are identified by their LOCALITY, never the generic
        // word "london" (ambiguous across LHR/LGW/STN/LTN). A terminal address in
        // these places is unmistakably that airport — e.g. an ETO Heathrow job
        // comes through as "Terminal 5, Wallis Road, Longford, Hounslow" with NO
        // "(LHR)" and no "Heathrow", which used to fall through to FREE ROAM.
        'heathrow' => 'LHR', 'longford' => 'LHR', 'hounslow' => 'LHR',
        'gatwick' => 'LGW', 'crawley' => 'LGW', 'horley' => 'LGW',
        'stansted' => 'STN',
        'luton' => 'LTN',
    ];

    /** The IATA code for one or more address parts, or null if none looks like an airport. */
    public static function codeFor(?string ...$parts): ?string
    {
        $haystack = trim(implode(' ', array_filter($parts)));
        if ($haystack === '') {
            return null;
        }

        // 1. A bracketed IATA code — the most explicit.
        if (preg_match('/\(([A-Z]{3})\)/', $haystack, $m) && isset(self::ALIASES[$m[1]])) {
            return $m[1];
        }

        $needle = strtolower($haystack);

        // 2. A name or postcode-area alias.
        foreach (self::ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (str_contains($needle, $alias)) {
                    return $code;
                }
            }
        }

        // 3. A terminal reference next to a city → that city's airport.
        if (self::hasTerminal($needle)) {
            foreach (self::TERMINAL_CITIES as $city => $code) {
                if (str_contains($needle, $city)) {
                    return $code;
                }
            }
        }

        return null;
    }

    /** Does this address look like an airport at all? */
    public static function isAirport(?string ...$parts): bool
    {
        $needle = strtolower(trim(implode(' ', array_filter($parts))));

        return str_contains($needle, 'airport')
            || self::hasTerminal($needle)
            || self::codeFor(...$parts) !== null;
    }

    private static function hasTerminal(string $needle): bool
    {
        return (bool) preg_match('/\bterminal\b|\bt[1-5]\b/', $needle);
    }
}
