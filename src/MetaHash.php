<?php

declare(strict_types=1);

namespace SilverShop\ConversionTracking\Meta;

/**
 * Meta Advanced Matching normalisation + hashing. Every matchable field is normalised (trim + lowercase, phone to
 * digits) then SHA-256 hashed before it goes into the Conversions API `user_data`, per Meta's spec. IP, user-agent,
 * `fbp` and `fbc` are the exception — they are forwarded UNHASHED and so do not pass through here.
 */
class MetaHash
{
    /**
     * Normalise + SHA-256 a generic text field (email, name, city, state, country, zip): trim, lowercase, hash.
     * Returns null for an empty value so the key is omitted.
     */
    public static function hash(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        return $value !== '' ? hash('sha256', $value) : null;
    }

    /**
     * Normalise + SHA-256 a phone number: strip everything but digits (keeping the country code), then hash.
     */
    public static function hashPhone(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value);

        return $digits !== '' && $digits !== null ? hash('sha256', $digits) : null;
    }
}
