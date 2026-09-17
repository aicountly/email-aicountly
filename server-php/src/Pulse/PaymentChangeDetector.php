<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

/**
 * "The bank details in this message are not the ones you have seen before."
 *
 * Invoice fraud works by sending a real-looking mail with changed bank details,
 * from a domain one character off — or from the supplier's own account, after
 * it has been taken over. So:
 *
 *   NOTHING HERE EVER SAYS A MESSAGE IS SAFE. SPF, DKIM and DMARC passing means
 *   the message came from a server allowed to send for that domain. It does not
 *   mean the human who wrote it is who they claim, that the account was not
 *   compromised, or that the account number is right. The authentication result
 *   is REPORTED, with that caveat attached, and is never rendered as a tick.
 *
 * What the detector does is narrow and useful: pull the payment identifiers out
 * of this message, compare them with the ones seen before from the same sender,
 * and when they differ say exactly which field changed and where each version
 * came from. The remedy offered is always the same one that works: verify on a
 * phone number you already had, not one in this email.
 */
final class PaymentChangeDetector
{
    /** @var array<string, string> field => regex */
    private const PATTERNS = [
        'iban'           => '/\b([A-Z]{2}\d{2}[A-Z0-9]{11,30})\b/',
        'ifsc'           => '/\b([A-Z]{4}0[A-Z0-9]{6})\b/',
        'swift'          => '/\b([A-Z]{6}[A-Z0-9]{2}(?:[A-Z0-9]{3})?)\b/',
        'account_number' => '/\b(?:a\/c|acc(?:ount)?(?:\s*(?:no|number|#))?)\D{0,12}(\d[\d \-]{6,22}\d)\b/i',
        'upi_id'         => '/\b([a-z0-9._-]{2,64}@(?:ok[a-z]+|[a-z]{3,20}))\b/i',
    ];

    /**
     * @param array<string, mixed>       $message  the message being read
     * @param list<array<string, mixed>> $previous payment details seen before from this sender
     * @return array<string, mixed>
     */
    public static function inspect(array $message, array $previous, ?string $authResults): array
    {
        $current = self::extract((string) ($message['text'] ?? '') . "\n" . strip_tags((string) ($message['html'] ?? '')));

        if ($current === []) {
            return [
                'has_payment_details' => false,
                'changed'             => false,
                'changes'             => [],
                'authentication'      => self::authentication($authResults),
                'advice'              => null,
            ];
        }

        $baseline = [];
        foreach ($previous as $entry) {
            foreach ($entry as $field => $value) {
                $baseline[$field] ??= (string) $value;
            }
        }

        $changes = [];
        foreach ($current as $field => $value) {
            $before = $baseline[$field] ?? null;
            if ($before === null) {
                continue;
            }
            if (self::normalise($before) !== self::normalise($value)) {
                $changes[] = [
                    'field'    => $field,
                    'previous' => self::mask($before),
                    'current'  => self::mask($value),
                    'source'   => [
                        'previous_from' => 'Earlier messages from this sender in this mailbox.',
                        'current_from'  => 'This message (' . (string) ($message['message_id'] ?? 'no message id') . ').',
                    ],
                ];
            }
        }

        return [
            'has_payment_details' => true,
            'changed'             => $changes !== [],
            'changes'             => $changes,
            'fields_seen'         => array_keys($current),
            'authentication'      => self::authentication($authResults),
            'advice'              => $changes === []
                ? null
                : 'Bank details in this message differ from the ones seen before from this sender. '
                  . 'Verify them by calling a number you already have for this contact — not a number in this email.',
            'verification_action' => $changes === [] ? null : 'verify_with_known_contact',
        ];
    }

    /** @return array<string, string> */
    public static function extract(string $text): array
    {
        $found = [];
        foreach (self::PATTERNS as $field => $pattern) {
            if (preg_match($pattern, $text, $m) === 1) {
                $found[$field] = trim($m[1]);
            }
        }

        // A SWIFT pattern also matches ordinary shouty words; only keep it when
        // the message is plausibly about a bank transfer.
        if (isset($found['swift']) && !preg_match('/\b(swift|bic|wire|remit|beneficiary)\b/i', $text)) {
            unset($found['swift']);
        }

        return $found;
    }

    /**
     * What the receiving MTA concluded, reported as exactly that.
     *
     * @return array<string, mixed>
     */
    private static function authentication(?string $authResults): array
    {
        $header = trim((string) $authResults);
        $read = static fn (string $mechanism): ?string =>
            preg_match('/\b' . $mechanism . '=(\w+)/i', $header, $m) === 1 ? strtolower($m[1]) : null;

        return [
            'header_present' => $header !== '',
            'spf'   => $read('spf'),
            'dkim'  => $read('dkim'),
            'dmarc' => $read('dmarc'),
            // The sentence that stops a green tick being read as a guarantee.
            'caveat' => 'These checks say the message came from a server permitted to send for that domain. '
                      . 'They do not confirm who wrote it, that the account was not taken over, or that any payment detail is correct.',
        ];
    }

    private static function normalise(string $value): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9@.]/', '', $value) ?? $value);
    }

    /** Only the last four digits reach the browser; the rest is not needed to see that it changed. */
    private static function mask(string $value): string
    {
        $clean = trim($value);
        if (mb_strlen($clean) <= 4) {
            return str_repeat('•', mb_strlen($clean));
        }

        return str_repeat('•', max(4, mb_strlen($clean) - 4)) . mb_substr($clean, -4);
    }
}
