<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * Recipient validation, and the header-injection guard.
 *
 * A CR or LF inside an address or a display name lets the sender add their own
 * headers to the outgoing message — a Bcc to themselves, a forged From. Every
 * address and every name that reaches a header goes through here first.
 */
final class AddressValidator
{
    public const MAX_RECIPIENTS = 100;

    /** @return array{valid:list<array{name:string,address:string}>, invalid:list<string>} */
    public static function parseList(mixed $input): array
    {
        $entries = [];
        if (is_string($input)) {
            $entries = preg_split('/[,;]/', $input) ?: [];
        } elseif (is_array($input)) {
            $entries = $input;
        }

        $valid = [];
        $invalid = [];

        foreach ($entries as $entry) {
            $name = '';
            $address = '';

            if (is_array($entry)) {
                $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
                $address = is_string($entry['address'] ?? null) ? $entry['address'] : '';
            } elseif (is_string($entry)) {
                $trimmed = trim($entry);
                if ($trimmed === '') {
                    continue;
                }
                if (preg_match('/^(.*)<([^>]+)>$/', $trimmed, $m) === 1) {
                    $name = trim($m[1], " \t\"'");
                    $address = trim($m[2]);
                } else {
                    $address = $trimmed;
                }
            }

            $address = strtolower(trim($address));
            if (!self::isValidAddress($address)) {
                if ($address !== '' || $name !== '') {
                    $invalid[] = self::forDisplay($address !== '' ? $address : $name);
                }
                continue;
            }

            $valid[] = ['name' => self::safeHeaderText($name), 'address' => $address];
        }

        return ['valid' => self::dedupe($valid), 'invalid' => $invalid];
    }

    public static function isValidAddress(string $address): bool
    {
        if ($address === '' || strlen($address) > 254) {
            return false;
        }
        if (self::hasInjection($address)) {
            return false;
        }

        return filter_var($address, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** CR, LF and NUL are the header-injection characters; nothing else needs to be here. */
    public static function hasInjection(string $value): bool
    {
        return preg_match('/[\r\n\x00]/', $value) === 1;
    }

    /**
     * A display name or subject, safe to put in a header.
     *
     * Folding whitespace is collapsed rather than escaped: an encoded-word is
     * built from this afterwards, and a multi-line input has no legitimate
     * reason to be here.
     */
    public static function safeHeaderText(string $value): string
    {
        $clean = preg_replace('/[\r\n\x00]+/', ' ', $value) ?? $value;

        return trim(mb_substr($clean, 0, 500));
    }

    /** RFC 2047 encoded-word, so a non-ASCII name or subject survives the wire. */
    public static function encodeHeader(string $value): string
    {
        $clean = self::safeHeaderText($value);
        if ($clean === '') {
            return '';
        }
        if (preg_match('/^[\x20-\x7E]*$/', $clean) === 1) {
            return $clean;
        }

        return '=?UTF-8?B?' . base64_encode($clean) . '?=';
    }

    /** `"Meera Shah" <meera@example.com>` */
    public static function format(array $address): string
    {
        $name = self::encodeHeader((string) ($address['name'] ?? ''));
        $mail = (string) ($address['address'] ?? '');

        if ($name === '') {
            return $mail;
        }

        // A quoted string cannot contain a bare quote or backslash.
        $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '"';

        return $quoted . ' <' . $mail . '>';
    }

    /** @param list<array{name:string,address:string}> $addresses */
    public static function formatList(array $addresses): string
    {
        return implode(', ', array_map([self::class, 'format'], $addresses));
    }

    /**
     * One address, once.
     *
     * The same person in To and Cc is one envelope recipient; sending twice is
     * two copies in their inbox.
     *
     * @param list<array{name:string,address:string}> $addresses
     * @return list<array{name:string,address:string}>
     */
    public static function dedupe(array $addresses): array
    {
        $seen = [];
        $out = [];
        foreach ($addresses as $address) {
            $key = strtolower($address['address']);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $address;
        }

        return $out;
    }

    /** Truncated and escaped, for an error message the user will read. */
    private static function forDisplay(string $value): string
    {
        return mb_substr(preg_replace('/[\r\n\x00]/', '', $value) ?? $value, 0, 120);
    }
}
