<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

/**
 * Promises found in a thread — as CANDIDATES.
 *
 * THE RULE THIS FILE EXISTS FOR: an incoming proposal never becomes an accepted
 * commitment. A supplier writing "we will deliver on the 25th" has proposed the
 * 25th. Nothing here, and no model, can turn that into an agreement — only a
 * person pressing Confirm can, and the state machine in the database makes that
 * the only path (`supplier_proposal` → `user_confirmed` happens in
 * CommitmentService::confirm and nowhere else).
 *
 * Six states, and the difference between the first three is the whole feature:
 *
 *   supplier_proposal  they said they would. Nobody has agreed.
 *   inferred           the wording is suggestive but not a promise. Weakest.
 *   user_confirmed     a person in this mailbox accepted it.
 *   completed / disputed / cancelled  what happened afterwards.
 */
final class CommitmentExtractor
{
    public const STATE_PROPOSAL  = 'supplier_proposal';
    public const STATE_INFERRED  = 'inferred';
    public const STATE_CONFIRMED = 'user_confirmed';

    /** Phrases that are a promise, and the state each one produces. */
    private const PROMISE_PATTERNS = [
        self::STATE_PROPOSAL => [
            'we will deliver', 'we will ship', 'we will dispatch', 'we will send', 'we will pay',
            'we shall deliver', 'we shall pay', 'delivery on', 'will be delivered on',
            'payment will be made', 'we commit to', 'we guarantee delivery',
        ],
        self::STATE_INFERRED => [
            'we should be able to', 'we aim to', 'we hope to', 'we expect to',
            'likely by', 'probably by', 'targeting',
        ],
    ];

    /**
     * @param array<string, mixed> $message  one message from the thread
     * @param string               $mailboxAddress the mailbox this thread belongs to
     * @return list<array<string, mixed>>
     */
    public static function fromMessage(array $message, string $mailboxAddress, string $timezone = 'Asia/Kolkata'): array
    {
        $text = (string) ($message['text'] ?? '');
        if (trim($text) === '') {
            return [];
        }

        $from = strtolower((string) ($message['from'][0]['address'] ?? ''));
        // Who is promising decides which column of the radar this belongs in.
        $direction = $from === strtolower($mailboxAddress) ? 'outgoing' : 'incoming';

        $found = [];
        foreach (self::PROMISE_PATTERNS as $state => $patterns) {
            foreach ($patterns as $pattern) {
                $sentence = self::sentenceContaining($text, $pattern);
                if ($sentence === null) {
                    continue;
                }

                // A promise states its date with "on" as often as with "by",
                // and this sentence has already been established as a promise.
                $deadline = DeadlineFinder::find($sentence, (string) ($message['date'] ?? ''), true);
                $found[] = [
                    'promised_by'   => $direction === 'incoming'
                        ? ($message['from'][0]['name'] ?? $from)
                        : $mailboxAddress,
                    'promised_to'   => $direction === 'incoming' ? $mailboxAddress : ($message['to'][0]['address'] ?? ''),
                    'what'          => self::summarise($sentence),
                    // The exact words, so the radar can show what was actually
                    // written rather than a paraphrase of it.
                    'source_quote'  => mb_substr(trim($sentence), 0, 300),
                    'source_uid'    => $message['uid'] ?? null,
                    'source_message_id' => $message['message_id'] ?? null,
                    'proposed_date' => $deadline['date'],
                    'timezone'      => $timezone,
                    'direction'     => $direction,
                    // Never STATE_CONFIRMED. There is no extraction path to it.
                    'state'         => $state,
                    'user_confirmed' => false,
                ];

                // One promise per pattern family per message: the same sentence
                // matching two phrasings is one promise, not two.
                break 1;
            }
        }

        return self::dedupe($found);
    }

    /** The sentence a phrase appears in, so the quotation has context. */
    private static function sentenceContaining(string $text, string $pattern): ?string
    {
        $haystack = mb_strtolower($text);
        $position = mb_strpos($haystack, $pattern);
        if ($position === false) {
            return null;
        }

        $sentences = preg_split('/(?<=[.!?\n])\s+/u', $text) ?: [];
        foreach ($sentences as $sentence) {
            if (str_contains(mb_strtolower($sentence), $pattern)) {
                return $sentence;
            }
        }

        return mb_substr($text, max(0, $position - 80), 240);
    }

    private static function summarise(string $sentence): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', $sentence) ?? $sentence), 0, 140);
    }

    /**
     * @param list<array<string, mixed>> $found
     * @return list<array<string, mixed>>
     */
    private static function dedupe(array $found): array
    {
        $seen = [];
        $out = [];
        foreach ($found as $commitment) {
            $key = md5(($commitment['source_quote'] ?? '') . '|' . ($commitment['proposed_date'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $commitment;
        }

        return $out;
    }
}
