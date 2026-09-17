<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

/**
 * A date the email actually states, or nothing.
 *
 * Deterministic, and deliberately conservative. Every match keeps the phrase it
 * came from, so a screen can show "by 25 September" next to the date and the
 * reader can see it was not invented. Where no phrase matches, the answer is
 * null and the caller prints "No deadline found." — which is a better answer
 * than a plausible guess.
 */
final class DeadlineFinder
{
    /** The date shapes a business email actually uses. */
    private const DATE = '((?:\d{1,2}[\/\-.]\d{1,2}[\/\-.]\d{2,4})'
        . '|(?:\d{4}-\d{2}-\d{2})'
        . '|(?:\d{1,2}(?:st|nd|rd|th)?\s+[A-Za-z]{3,9}(?:\s+\d{4})?)'
        . '|(?:[A-Za-z]{3,9}\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?))';

    /**
     * @param bool $includePlainOn also read "on 25 September" as a date.
     *
     *   For a DEADLINE this is off, because "we met on 3 September" is not a
     *   deadline and reading it as one puts a due date on a thread that has
     *   none — the exact invention this class exists to avoid.
     *
     *   For a COMMITMENT it is on, because "we will deliver on 25 September" is
     *   how a promise is written, and the caller has already established that
     *   the sentence is a promise before asking for its date.
     *
     * @return array{date: ?string, reason: ?string, phrase: ?string}
     */
    public static function find(string $text, string $receivedAt = '', bool $includePlainOn = false): array
    {
        $body = mb_substr($text, 0, 8000);
        $reference = self::reference($receivedAt);

        // Longest alternatives first: "on or before" must not be consumed by a
        // bare "on" that then fails to find a date.
        $lead = 'on or before|no later than|latest by|due on|due by|scheduled for|expected on|by|before';
        if ($includePlainOn) {
            $lead .= '|on';
        }

        $patterns = [
            '/\b(?:' . $lead . ')\s+' . self::DATE . '/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $body, $m) === 1) {
                $parsed = self::toDate($m[1], $reference);
                if ($parsed !== null) {
                    return [
                        'date'   => $parsed,
                        'reason' => 'The message says "' . trim($m[0]) . '".',
                        'phrase' => trim($m[0]),
                    ];
                }
            }
        }

        foreach (['today' => 0, 'tomorrow' => 1, 'end of day' => 0, 'eod' => 0, 'end of week' => 5] as $phrase => $offset) {
            if (stripos($body, $phrase) !== false) {
                return [
                    'date'   => $reference->modify('+' . $offset . ' day')->format('Y-m-d'),
                    'reason' => 'The message says "' . $phrase . '", relative to when it arrived.',
                    'phrase' => $phrase,
                ];
            }
        }

        return ['date' => null, 'reason' => null, 'phrase' => null];
    }

    private static function reference(string $receivedAt): \DateTimeImmutable
    {
        try {
            return $receivedAt === ''
                ? new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
                : new \DateTimeImmutable($receivedAt);
        } catch (\Throwable) {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    }

    /**
     * A bare "25 September" takes its year from when the message arrived, and
     * rolls forward if that would put the deadline in the past — a supplier
     * writing in December about "5 January" means next year.
     */
    private static function toDate(string $raw, \DateTimeImmutable $reference): ?string
    {
        $clean = trim(preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $raw) ?? $raw);

        try {
            $parsed = new \DateTimeImmutable($clean, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        $hasYear = preg_match('/\b\d{4}\b/', $clean) === 1;
        if (!$hasYear) {
            $parsed = $parsed->setDate((int) $reference->format('Y'), (int) $parsed->format('n'), (int) $parsed->format('j'));
            if ($parsed < $reference->modify('-7 days')) {
                $parsed = $parsed->modify('+1 year');
            }
        }

        return $parsed->format('Y-m-d');
    }
}
