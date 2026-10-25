<?php

namespace App\Support;

/**
 * UK phone normalisation for wa.me / WhatsApp deep links.
 *
 * WhatsApp only opens a chat if the number is the account's real international
 * number with NO leading zero and NO plus. The classic "this number is not on
 * WhatsApp" error is almost always a formatting slip in the STORED number, not a
 * missing account — most often the very common "+44 (0)7700 900123" style, which
 * a naive strip turns into 4407700900123 (a stray 0 after the 44). This handles
 * those cases centrally.
 */
class Phone
{
    /**
     * Return the number as international digits for wa.me (e.g. 447700900123),
     * or null if there aren't enough digits to be a real number.
     */
    public static function wa(?string $number): ?string
    {
        $d = preg_replace('/\D/', '', (string) $number); // digits only — drops +, (), spaces, dashes
        if ($d === '' || strlen($d) < 7) {
            return null;
        }

        // 00 international access prefix → drop it (0044… → 44…).
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        } elseif (str_starts_with($d, '0')) {
            // National trunk 0 → UK country code (07700… → 447700…).
            $d = '44'.substr($d, 1);
        }

        // "+44 (0)7…" leaves 44 0 7… — strip the stray trunk 0 after the 44.
        if (str_starts_with($d, '440')) {
            $d = '44'.substr($d, 3);
        }

        // A bare UK mobile with no country code or trunk 0 (7700900123).
        if (strlen($d) === 10 && str_starts_with($d, '7')) {
            $d = '44'.$d;
        }

        return $d;
    }

    /**
     * A non-UK number — the driver's masked line can't bridge it and WhatsApp
     * won't match a foreign number, so these customers must liaise through the
     * office. True only when we have a usable number that normalises to a country
     * code other than 44.
     */
    public static function isForeign(?string $number): bool
    {
        $wa = self::wa($number);

        return $wa !== null && ! str_starts_with($wa, '44');
    }

    /**
     * Common country dialling codes → ISO region, longest-first so 353 matches
     * before 35, 1 last. Enough to recognise the numbers CET actually sees
     * (UK + Ireland + the usual European/holiday destinations); unknown codes
     * still format as a clean "+<code> …", just without a region label.
     */
    private const CODES = [
        '353' => 'IE', '351' => 'PT', '352' => 'LU', '356' => 'MT', '357' => 'CY',
        '358' => 'FI', '359' => 'BG', '350' => 'GI', '355' => 'AL', '354' => 'IS',
        '971' => 'AE', '974' => 'QA', '966' => 'SA', '968' => 'OM', '961' => 'LB',
        '44' => 'GB', '33' => 'FR', '34' => 'ES', '49' => 'DE', '39' => 'IT',
        '31' => 'NL', '32' => 'BE', '41' => 'CH', '43' => 'AT', '45' => 'DK',
        '46' => 'SE', '47' => 'NO', '48' => 'PL', '30' => 'GR', '36' => 'HU',
        '40' => 'RO', '90' => 'TR', '91' => 'IN', '92' => 'PK', '61' => 'AU',
        '64' => 'NZ', '27' => 'ZA', '20' => 'EG', '1' => 'US',
    ];

    /**
     * The dialling code and the rest of the number, from international digits
     * (e.g. 353860889895 → ['353', '860889895']). Null when not usable.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function split(?string $number): ?array
    {
        $d = self::wa($number);
        if ($d === null) {
            return null;
        }
        foreach (self::CODES as $code => $_region) {
            $code = (string) $code; // PHP casts numeric array keys to int — keep it a string
            if (str_starts_with($d, $code)) {
                return [$code, substr($d, strlen($code))];
            }
        }

        return null;
    }

    /**
     * The ISO region the number belongs to (GB, IE, …), recognised from its
     * dialling code, or null if we can't tell. UK numbers in national form
     * (07…) are treated as GB, since that's this system's home region.
     */
    public static function region(?string $number): ?string
    {
        $split = self::split($number);

        return $split ? (self::CODES[$split[0]] ?? null) : null;
    }

    /**
     * The number in E.164 form for dialling (tel: links): "+447700900123",
     * "+353860889895". Null when there aren't enough digits. Works for any
     * country, so an international customer's number dials correctly.
     */
    public static function e164(?string $number): ?string
    {
        $d = self::wa($number);

        return $d === null ? null : '+'.$d;
    }

    /**
     * A human-readable number for showing on screen / invoices — recognised to its
     * country and grouped so it reads cleanly, e.g. "07700 900123" for UK,
     * "+353 860 889 895" for Ireland. Falls back to the trimmed input when we can't
     * parse it, so nothing is ever blanked.
     */
    public static function display(?string $number): ?string
    {
        $split = self::split($number);
        if ($split === null) {
            $raw = trim((string) $number);

            return $raw === '' ? null : $raw;
        }

        [$code, $rest] = $split;

        // UK: show the familiar national form (07700 900123 / 0114 399 8242).
        if ($code === '44') {
            $national = '0'.$rest;
            if (strlen($national) === 11 && str_starts_with($national, '07')) {
                return substr($national, 0, 5).' '.substr($national, 5); // 07700 900123
            }

            return $national;
        }

        // Everyone else: "+<code> " then the rest in groups of three.
        $grouped = trim(chunk_split($rest, 3, ' '));

        return '+'.$code.' '.$grouped;
    }
}
