<?php

namespace App\Support;

/**
 * One place that works out which airport an address refers to — used for the title
 * WHERE word, the Arrival/Departure/Transfer classification, driver rotation and,
 * critically, whether MEET & GREET may be charged (only on a leg that collects from
 * an airport). A false positive here charges a customer for meet & greet on a
 * home pickup, so matching is deliberately strict:
 *
 * It matches, in order: a bracketed IATA code "(MAN)", an airport NAME alias
 * ("Manchester Airport", "Heathrow"), an airport-only POSTCODE ("M90", "DE74 2SA"),
 * and a TERMINAL reference next to a city ("Terminal 2, Manchester", "T2
 * Manchester"), which is unmistakably that city's airport even though the word
 * "airport" is absent.
 *
 * It never matches: a bare city/town ("Doncaster", "Luton", "Castle Donington"),
 * a road or retail/business park NAMED after an airport ("Gatwick Road, Crawley",
 * "Airport Retail Park"), an office building ("Terminal House"), a bus/coach/ferry
 * terminal, or a residential postcode district the airport merely shares (LU2,
 * DE74, B26, RH6, LS19, …).
 */
class AirportMatcher
{
    /** IATA code → airport NAME aliases (matched as whole words, never as a street name). */
    private const ALIASES = [
        'MAN' => ['manchester airport'],
        'LHR' => ['heathrow'],
        'LGW' => ['gatwick'],
        'STN' => ['stansted airport', 'london stansted'],
        'EMA' => ['east midlands airport', 'nottingham east midlands'],
        'LBA' => ['leeds bradford'],
        'BHX' => ['birmingham airport'],
        'LPL' => ['liverpool john lennon', 'liverpool airport'],
        'HUY' => ['humberside airport'],
        'LTN' => ['luton airport', 'london luton'],
        'DSA' => ['doncaster sheffield', 'robin hood airport'],
        'NCL' => ['newcastle airport'],
        'EDI' => ['edinburgh airport'],
        'GLA' => ['glasgow airport'],
        'BRS' => ['bristol airport'],
    ];

    /**
     * IATA code → postcodes that are the airport itself. Only districts that are
     * ENTIRELY airport (M90, TW6) are matched by district; everywhere else the
     * district is shared with homes (Luton LU2, Kegworth DE74, Sheldon B26, Horley
     * RH6, Yeadon LS19, Speke L24, Stansted Mountfitchet CM24), so only the
     * airport's own full postcode counts.
     */
    private const POSTCODES = [
        'MAN' => ['m90'],
        'LHR' => ['tw6'],
        'LGW' => ['rh6 0np', 'rh6 0pj'],
        'STN' => ['cm24 1qw'],
        'EMA' => ['de74 2sa'],
        'LBA' => ['ls19 7tu'],
        'BHX' => ['b26 3qj'],
        'LPL' => ['l24 1yd'],
        'HUY' => ['dn39 6yh'],
        'LTN' => ['lu2 9ly'],
        'DSA' => ['dn9 3rh'],
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

    /**
     * Words that, straight after an airport word, make it a STREET / estate named
     * after the airport rather than the airport ("Gatwick Road, Crawley", "Airport
     * Retail Park", "Leeds Bradford Road"). "Way" is deliberately NOT here — the
     * real airports' own addresses use it (Airport Way, Luton / Gatwick).
     */
    private const STREET = '(?:road|rd|street|lane|avenue|drive|close|crescent|grove|terrace|retail\s+park|business\s+park|shopping\s+park|industrial\s+estate|trading\s+estate)\b';

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

        // 2. An airport name alias — as whole words, and not a road named after it.
        foreach (self::ALIASES as $code => $aliases) {
            foreach ($aliases as $alias) {
                if (self::hasWord($needle, $alias)) {
                    return $code;
                }
            }
        }

        // 3. The airport's own postcode (space optional).
        foreach (self::POSTCODES as $code => $postcodes) {
            foreach ($postcodes as $pc) {
                $rx = str_replace(' ', '\s*', preg_quote($pc, '/'));
                if (preg_match('/\b'.$rx.'\b/', $needle)) {
                    return $code;
                }
            }
        }

        // 4. A terminal reference next to a city → that city's airport.
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
        if ($needle === '') {
            return false;
        }

        return self::hasWord($needle, 'airport')
            || self::hasTerminal($needle)
            || self::codeFor(...$parts) !== null;
    }

    /** $phrase as whole word(s) in $needle, NOT immediately followed by a street word. */
    private static function hasWord(string $needle, string $phrase): bool
    {
        $rx = str_replace(' ', '\s+', preg_quote($phrase, '/'));

        return (bool) preg_match('/(?<![a-z0-9])'.$rx.'(?![a-z0-9])(?!\s+'.self::STREET.')/', $needle);
    }

    /**
     * An airport terminal reference: "Terminal 2", "North Terminal", "T5" — but not
     * an office building or street ("Terminal House", "Terminal Road") and not a
     * bus / coach / ferry / freight terminal.
     */
    private static function hasTerminal(string $needle): bool
    {
        if (preg_match('/\bt[1-5]\b/', $needle)) {
            return true;
        }

        return (bool) preg_match(
            '/(?<!bus )(?<!coach )(?<!ferry )(?<!freight )(?<!cargo )(?<!container )(?<!cruise )(?<!rail )(?<!oil )(?<!lorry )(?<!truck )\bterminal\b'
            .'(?!\s+(?:house|building|buildings|chambers|court|works|road|rd|street|lane|avenue|drive|close|place|business\s+park|industrial\s+estate|trading\s+estate)\b)/',
            $needle
        );
    }
}
