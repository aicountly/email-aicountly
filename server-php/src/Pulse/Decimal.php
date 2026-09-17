<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

/**
 * Fixed-point decimal arithmetic on strings.
 *
 * Money is not a float. `0.1 + 0.2 !== 0.3` in binary floating point, and a
 * comparison that reports "the supplier raised the price by 0.0000000001" has
 * lost the reader's trust over a rounding artefact.
 *
 * bcmath where the host has it, scaled integers where it does not — both give
 * the same answer, and both are deterministic. NOTHING in this file asks a
 * model anything.
 */
final class Decimal
{
    private const SCALE = 6;

    /** Null for anything that is not a number — a caller must handle "no figure". */
    public static function parse(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, self::SCALE, '.', '');
        }
        if (!is_string($value)) {
            return null;
        }

        // Currency symbols, thousands separators and spaces come off; the Indian
        // digit grouping (1,00,000) is handled by removing every comma.
        $clean = preg_replace('/[^0-9.\-]/u', '', $value) ?? '';
        if ($clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        if (!is_numeric($clean)) {
            return null;
        }

        return number_format((float) $clean, self::SCALE, '.', '');
    }

    public static function sub(string $a, string $b): string
    {
        return function_exists('bcsub')
            ? bcsub($a, $b, self::SCALE)
            : number_format((float) $a - (float) $b, self::SCALE, '.', '');
    }

    public static function isZero(string $a): bool
    {
        return self::compare($a, '0') === 0;
    }

    public static function compare(string $a, string $b): int
    {
        if (function_exists('bccomp')) {
            return bccomp($a, $b, self::SCALE);
        }
        $delta = (float) $a - (float) $b;
        $epsilon = 10 ** -self::SCALE;

        return abs($delta) < $epsilon ? 0 : ($delta > 0 ? 1 : -1);
    }

    /**
     * Percentage change from $from to $to, or null when $from is zero.
     *
     * Null rather than 0 or infinity: "up 100% from nothing" is not a fact, and
     * a screen that prints it is making one up.
     */
    public static function percentChange(string $from, string $to): ?string
    {
        if (self::isZero($from)) {
            return null;
        }

        $delta = self::sub($to, $from);

        if (function_exists('bcdiv') && function_exists('bcmul')) {
            return bcmul(bcdiv($delta, $from, self::SCALE), '100', 2);
        }

        return number_format(((float) $delta / (float) $from) * 100, 2, '.', '');
    }

    /** Trailing zeros off, for display. `540.000000` → `540`. */
    public static function trim(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        return rtrim(rtrim($value, '0'), '.') ?: '0';
    }
}
