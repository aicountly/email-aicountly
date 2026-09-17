<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

/**
 * Email vs business record.
 *
 * THIS FILE CONTAINS NO MODEL CALL AND NEVER WILL. A language model may be used
 * to READ a figure out of an email — that is extraction, and it is checked
 * against the message text before it is trusted. Everything after that, the
 * subtraction and the percentage, is arithmetic, and arithmetic belongs in
 * code where it is the same answer every time and a test can pin it.
 *
 * LIKE FOR LIKE, OR NOTHING. ₹540 against ₹500 is only an 8% increase if both
 * are per the same unit, in the same currency, on the same tax basis, after the
 * same discount treatment and with freight handled the same way. Where any of
 * those differ the answer is "Comparison requires review", naming what differs.
 * A product that quietly compares an inclusive price with an exclusive one has
 * told its user the supplier raised the price when they did not.
 */
final class Comparison
{
    /** Fields that must agree before any subtraction is meaningful. */
    private const BASIS_FIELDS = [
        'currency'       => 'currency',
        'uom'            => 'unit of measure',
        'quantity'       => 'quantity',
        'tax_basis'      => 'tax basis',
        'discount_basis' => 'discount basis',
        'freight_basis'  => 'freight treatment',
    ];

    /** Fields that are compared once the bases agree. */
    private const NUMERIC_FIELDS = [
        'unit_price'   => 'Unit price',
        'line_total'   => 'Line total',
        'tax_amount'   => 'Tax',
        'freight'      => 'Freight',
        'grand_total'  => 'Total',
    ];

    private const DATE_FIELDS = [
        'delivery_date' => 'Delivery',
        'due_date'      => 'Payment due',
    ];

    /**
     * @param array<string, mixed> $email   what the message says
     * @param array<string, mixed> $record  what the owning product says, read live
     * @param array<string, mixed> $source  service, id and the time it was read
     * @return array<string, mixed>
     */
    public static function compare(array $email, array $record, array $source): array
    {
        $blockers = self::basisMismatches($email, $record);

        $result = [
            'comparable'    => $blockers === [],
            'requires_review' => $blockers !== [],
            'review_reason' => $blockers === [] ? null : 'Comparison requires review: the two sides are not stated on the same basis.',
            'blockers'      => $blockers,
            'differences'   => [],
            'unchanged'     => [],
            'source'        => $source,
            'computed_by'   => 'deterministic',
        ];

        if ($blockers !== []) {
            // The figures are still shown side by side — the user can see them
            // and judge. What is withheld is the arithmetic, because on these
            // inputs it would be wrong.
            $result['side_by_side'] = self::sideBySide($email, $record);

            return $result;
        }

        foreach (self::NUMERIC_FIELDS as $field => $label) {
            $comparison = self::compareNumeric($field, $label, $email, $record);
            if ($comparison === null) {
                continue;
            }
            if ($comparison['changed']) {
                $result['differences'][] = $comparison;
            } else {
                $result['unchanged'][] = $comparison;
            }
        }

        foreach (self::DATE_FIELDS as $field => $label) {
            $comparison = self::compareDate($field, $label, $email, $record);
            if ($comparison === null) {
                continue;
            }
            if ($comparison['changed']) {
                $result['differences'][] = $comparison;
            } else {
                $result['unchanged'][] = $comparison;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $email
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    private static function basisMismatches(array $email, array $record): array
    {
        $blockers = [];

        foreach (self::BASIS_FIELDS as $field => $label) {
            $left = self::normaliseBasis($email[$field] ?? null);
            $right = self::normaliseBasis($record[$field] ?? null);

            // Where one side simply does not state a basis, the comparison is
            // still blocked — an unstated tax basis is not "the same as the
            // other one", it is unknown.
            if ($left === null && $right === null) {
                continue;
            }
            if ($left === null || $right === null) {
                $blockers[] = [
                    'field'  => $field,
                    'label'  => $label,
                    'reason' => 'The ' . $label . ' is stated on only one side, so the two figures may not be comparable.',
                    'email'  => $left,
                    'record' => $right,
                ];
                continue;
            }
            if ($left !== $right) {
                $blockers[] = [
                    'field'  => $field,
                    'label'  => $label,
                    'reason' => 'The ' . $label . ' differs (' . $right . ' on the record, ' . $left . ' in the email).',
                    'email'  => $left,
                    'record' => $right,
                ];
            }
        }

        return $blockers;
    }

    /**
     * @param array<string, mixed> $email
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private static function compareNumeric(string $field, string $label, array $email, array $record): ?array
    {
        $left = Decimal::parse($email[$field] ?? null);
        $right = Decimal::parse($record[$field] ?? null);

        if ($left === null || $right === null) {
            return null;
        }

        $delta = Decimal::sub($left, $right);
        $changed = !Decimal::isZero($delta);
        $percent = Decimal::percentChange($right, $left);

        return [
            'field'       => $field,
            'label'       => $label,
            'kind'        => 'amount',
            'record'      => Decimal::trim($right),
            'email'       => Decimal::trim($left),
            'delta'       => Decimal::trim($delta),
            'delta_percent' => $percent === null ? null : Decimal::trim($percent),
            'direction'   => $changed ? (Decimal::compare($left, $right) > 0 ? 'increase' : 'decrease') : 'same',
            'changed'     => $changed,
            'currency'    => self::normaliseBasis($record['currency'] ?? ($email['currency'] ?? null)),
        ];
    }

    /**
     * @param array<string, mixed> $email
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private static function compareDate(string $field, string $label, array $email, array $record): ?array
    {
        $left = self::parseDate($email[$field] ?? null);
        $right = self::parseDate($record[$field] ?? null);

        if ($left === null || $right === null) {
            return null;
        }

        // Whole days, computed on UTC midnights so a timezone does not turn a
        // three-day slip into two or four.
        $days = (int) round(($left->getTimestamp() - $right->getTimestamp()) / 86400);

        return [
            'field'     => $field,
            'label'     => $label,
            'kind'      => 'date',
            'record'    => $right->format('Y-m-d'),
            'email'     => $left->format('Y-m-d'),
            'delta_days' => $days,
            'direction' => $days === 0 ? 'same' : ($days > 0 ? 'later' : 'earlier'),
            'changed'   => $days !== 0,
        ];
    }

    /**
     * @param array<string, mixed> $email
     * @param array<string, mixed> $record
     * @return list<array<string, mixed>>
     */
    private static function sideBySide(array $email, array $record): array
    {
        $rows = [];
        foreach (array_merge(self::NUMERIC_FIELDS, self::DATE_FIELDS) as $field => $label) {
            $left = $email[$field] ?? null;
            $right = $record[$field] ?? null;
            if ($left === null && $right === null) {
                continue;
            }
            $rows[] = [
                'field'  => $field,
                'label'  => $label,
                'record' => is_scalar($right) ? (string) $right : null,
                'email'  => is_scalar($left) ? (string) $left : null,
            ];
        }

        return $rows;
    }

    /** Case and spacing do not make two bases different; `INR ` and `inr` are one. */
    private static function normaliseBasis(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'yes' : 'no';
        }
        if (!is_scalar($value)) {
            return null;
        }

        return strtoupper(trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value));
    }

    /**
     * A calendar date, normalised to UTC midnight.
     *
     * The time of day is dropped deliberately: "delivery on the 25th" is a date,
     * and keeping a time would make a three-day slip come out as 2.6 days.
     */
    private static function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable(trim($value), new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        return $parsed->setTime(0, 0, 0);
    }
}
