<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Phone validation and normalisation (Google libphonenumber). Numbers are stored in E.164 (+923001234567).
 */
final class PhoneNumbers
{
    /**
     * E.164 form of $number, or null when it is not a valid number. With a country (ISO 3166 alpha-2) the
     * number may be written nationally ("0300 1234567") and must belong to that country; without one it
     * must be in international format ("+92 300 1234567").
     */
    public static function toE164(string $number, ?string $country): ?string
    {
        $util = PhoneNumberUtil::getInstance();

        try {
            $parsed = $util->parse($number, $country ? strtoupper($country) : null);
        } catch (NumberParseException) {
            return null;
        }

        if (! $util->isValidNumber($parsed)) {
            return null;
        }

        if ($country && ! $util->isValidNumberForRegion($parsed, strtoupper($country))) {
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }
}
