<?php
declare(strict_types=1);

namespace YealinkCallLog;

final class Phone
{
    /**
     * Normalize a phone number to E.164 format.
     *
     * Handles:
     *  - sip:/tel: URI prefixes and @host suffixes
     *  - Display names and angle brackets  <sip:xxx@host>
     *  - 00XX... → +XX...
     *  - Belgian local 0XXXXXXXXX → +32XXXXXXXXX
     *  - Strips spaces, dashes, dots, parentheses, slashes
     *
     * Returns null when normalization is not possible.
     */
    public static function toE164(string $raw, string $defaultCountry = 'BE'): ?string
    {
        // Strip display name and angle brackets: "Name <sip:xxx@host>" → "sip:xxx@host"
        if (preg_match('#<([^>]+)>#', $raw, $m)) {
            $raw = $m[1];
        }

        // Strip sip: / tel: scheme prefix
        $num = (string) preg_replace('#^(sip:|tel:)#i', '', $raw);

        // Strip @host suffix (SIP URIs)
        $num = (string) preg_replace('#@.*$#', '', $num);

        // Strip visual separators: spaces, dashes, dots, parentheses, slashes
        $num = (string) preg_replace('#[\s\-\.\(\)/]#', '', $num);

        if ($num === '') {
            return null;
        }

        // Already E.164: +XXXXXXX...
        if (preg_match('#^\+\d{7,15}$#', $num)) {
            return $num;
        }

        // International dialling prefix 00XXXXX → +XXXXX
        if (preg_match('#^00(\d{7,13})$#', $num, $m)) {
            return '+' . $m[1];
        }

        // Belgium local format: 0XXXXXXXXX (9 or 10 digits total)
        if ($defaultCountry === 'BE' && preg_match('#^0(\d{8,9})$#', $num, $m)) {
            return '+32' . $m[1];
        }

        // Plain digits only – prefix with default country code
        if (preg_match('#^\d{7,15}$#', $num)) {
            if ($defaultCountry === 'BE') {
                // Treat as national number without leading 0
                return '+32' . $num;
            }
            return '+' . $num;
        }

        return null;
    }
}
