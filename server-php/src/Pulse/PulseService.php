<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

use Aicountly\Api\Account;
use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Auth;
use Aicountly\Api\Db;
use Aicountly\Api\Mail\HtmlSanitizer;
use Aicountly\Api\Mail\MailAdapters;
use Aicountly\Api\Mail\MailboxIdentity;

/**
 * Pulse: the briefing, and the analysis of one thread.
 *
 * TWO THINGS THIS DELIBERATELY DOES NOT DO.
 *
 * It does not invent a metric. Every number the briefing shows is a count of
 * rows Email can point at — "6 decisions" is six threads in the decision inbox,
 * and the endpoint returns their ids so the UI can link to each one. There is
 * no "hours saved", because nobody measured any hours.
 *
 * It does not total money. A briefing that says "₹2.4L awaiting replies" is
 * adding up figures scraped out of emails, and an email is not a ledger. Where
 * a monetary total is wanted it comes from Books, on that request, scoped to
 * that company — and Email does not have one to show until it does.
 */
final class PulseService
{
    /**
     * The morning briefing for one mailbox.
     *
     * @return array<string, mixed>
     */
    public static function briefing(Auth $auth, Account $account, int $mailboxId, string $displayName): array
    {
        $decisions = Db::all(
            'SELECT thread_key, classification, reason, summary, deadline_at, sources, generator, corrected_to
               FROM email_thread_insights
              WHERE mailbox_id = :mailbox
                AND dismissed_at IS NULL
                AND COALESCE(corrected_to, classification) <> :none
              ORDER BY deadline_at NULLS LAST, updated_at DESC
              LIMIT 25',
            ['mailbox' => $mailboxId, 'none' => Classifier::NONE],
        );

        $commitments = Db::all(
            'SELECT commitment_id, thread_key, what, promised_by, promised_to, proposed_date, timezone,
                    direction, state, source_quote, source_uid
               FROM email_commitments
              WHERE mailbox_id = :mailbox
                AND state IN (:proposal, :inferred, :confirmed)
              ORDER BY proposed_date NULLS LAST
              LIMIT 25',
            [
                'mailbox'   => $mailboxId,
                'proposal'  => CommitmentExtractor::STATE_PROPOSAL,
                'inferred'  => CommitmentExtractor::STATE_INFERRED,
                'confirmed' => CommitmentExtractor::STATE_CONFIRMED,
            ],
        );

        $today = gmdate('Y-m-d');
        $dueToday = array_values(array_filter(
            $commitments,
            static fn (array $c) => $c['proposed_date'] !== null && (string) $c['proposed_date'] <= $today,
        ));

        $waiting = Db::all(
            'SELECT thread_key, subject, from_address, internal_date
               FROM email_message_refs
              WHERE mailbox_id = :mailbox
                AND folder = :sent
                AND internal_date < :cutoff
              ORDER BY internal_date DESC
              LIMIT 25',
            [
                'mailbox' => $mailboxId,
                'sent'    => 'Sent',
                'cutoff'  => gmdate('Y-m-d H:i:s', time() - 3 * 86400),
            ],
        );

        return [
            'greeting'    => self::greeting($displayName),
            // Every count is a list, and every list is linkable. A figure whose
            // rows cannot be opened is a figure nobody can check.
            'counts'      => [
                'decisions'   => ['value' => count($decisions),   'source' => 'Threads classified as needing you, in this mailbox.'],
                'commitments_due' => ['value' => count($dueToday), 'source' => 'Commitments on or before today, from this mailbox\'s radar.'],
                'awaiting_replies' => ['value' => count($waiting), 'source' => 'Messages you sent more than three days ago with no reply in the thread.'],
            ],
            'decisions'   => array_map(static fn (array $row) => [
                'thread_key'     => $row['thread_key'],
                'classification' => $row['corrected_to'] ?? $row['classification'],
                'label'          => Classifier::CLASSES[$row['corrected_to'] ?? $row['classification']] ?? 'Needs attention',
                'reason'         => $row['reason'],
                'summary'        => $row['summary'],
                'deadline'       => $row['deadline_at'],
                'deadline_note'  => $row['deadline_at'] === null ? 'No deadline found.' : null,
                'sources'        => Db::jsonColumn($row['sources']),
                'ai_generated'   => $row['generator'] === 'model',
            ], $decisions),
            'commitments' => array_map([self::class, 'presentCommitment'], $commitments),
            'awaiting_replies' => $waiting,
            'ai'          => AiClient::status() + ['opted_out' => $account->aiOptOut],
            'generated_at' => gmdate('c'),
            // Said on the object itself so no screen has to remember it.
            'disclaimer'  => 'Counts are of items in this mailbox and link to them. Pulse does not estimate time saved and does not total money from email text.',
        ];
    }

    /**
     * Analyse one thread: classify it, find its commitments, look for a changed
     * payment detail, and summarise it.
     *
     * The summary is stored. What is stored with it is the message ids it came
     * from — never a business record fetched from another product, because a
     * stored purchase order goes stale and a summary quoting one is then wrong
     * in a way nobody can see.
     *
     * @return array<string, mixed>
     */
    public static function analyseThread(Auth $auth, Account $account, array $mailbox, string $folder, string $threadKey): array
    {
        $identity = MailboxIdentity::forMailbox($mailbox);
        if ($identity === null) {
            return ['ok' => false, 'error' => 'This mailbox has no usable mail-store credentials on this deployment.'];
        }

        $thread = MailAdapters::store()->thread($identity, $folder, $threadKey);
        if (!$thread['ok']) {
            return ['ok' => false, 'error' => $thread['error']];
        }
        if ($thread['messages'] === []) {
            return ['ok' => false, 'error' => 'That conversation is no longer in the mailbox.'];
        }

        $messages = $thread['messages'];
        $latest = $messages[count($messages) - 1];
        $useModel = AiClient::isConfigured() && !$account->aiOptOut;

        $classification = Classifier::classify($latest, $useModel);

        $commitments = [];
        foreach ($messages as $message) {
            foreach (CommitmentExtractor::fromMessage($message, (string) $mailbox['address']) as $commitment) {
                $commitments[] = $commitment;
            }
        }

        $payment = PaymentChangeDetector::inspect(
            $latest,
            self::previousPaymentDetails((int) $mailbox['mailbox_id'], (string) ($latest['from'][0]['address'] ?? '')),
            $latest['auth_results'] ?? null,
        );

        $summary = self::summarise($messages, $useModel);

        self::storeInsight((int) $mailbox['mailbox_id'], $threadKey, $classification, $summary, $messages);
        self::storeCommitments((int) $mailbox['mailbox_id'], $threadKey, $commitments);

        return [
            'ok'             => true,
            'thread_key'     => $threadKey,
            'classification' => $classification,
            'summary'        => $summary,
            'commitments'    => array_map(static fn (array $c) => $c + ['user_confirmed' => false], $commitments),
            'payment_check'  => $payment,
            'message_count'  => count($messages),
            'ai'             => AiClient::status() + ['opted_out' => $account->aiOptOut],
        ];
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @return array<string, mixed>
     */
    private static function summarise(array $messages, bool $useModel): array
    {
        $sources = [];
        $grounding = [];
        foreach (array_slice($messages, -6) as $message) {
            $sources[] = [
                'uid'        => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'from'       => $message['from'][0]['address'] ?? null,
                'date'       => $message['date'] ?? null,
            ];
            $grounding[] = [
                'from'    => $message['from'][0]['address'] ?? '',
                'date'    => $message['date'] ?? '',
                'subject' => $message['subject'] ?? '',
                'body'    => mb_substr(HtmlSanitizer::snippet((string) ($message['html'] ?? ''), (string) ($message['text'] ?? ''), 4000), 0, 4000),
            ];
        }

        if (!$useModel) {
            return [
                'text'          => '',
                'generator'     => 'rules',
                'available'     => false,
                'reason'        => AiClient::status()['reason'] ?? 'AI summaries are switched off for this account.',
                'sources'       => $sources,
                'uncertainty'   => null,
            ];
        }

        $result = AiClient::narrate(
            'Summarise what changed in this thread and what it asks of the reader.',
            ['messages' => $grounding],
            "If the thread mentions a figure, quote it exactly as written and say which message it is from.\n"
            . "If something the reader would need is missing from the thread, say which thing is missing.",
        );

        if (!$result['ok']) {
            return [
                'text'        => '',
                'generator'   => 'model',
                'available'   => false,
                'reason'      => $result['error'],
                'sources'     => $sources,
                'uncertainty' => null,
            ];
        }

        return [
            'text'        => (string) $result['text'],
            'generator'   => 'model',
            'available'   => true,
            'reason'      => null,
            // Always shown next to the text, never as a footnote somewhere else.
            'sources'     => $sources,
            'uncertainty' => 'AI-assisted summary of ' . count($sources) . ' message(s) in this thread. Check the sources before acting on it.',
        ];
    }

    /**
     * Payment identifiers seen before from this sender, in this mailbox.
     *
     * Read out of Email's own stored insights — not out of a contact record and
     * not out of an accounting system, both of which belong to other products.
     *
     * @return list<array<string, string>>
     */
    private static function previousPaymentDetails(int $mailboxId, string $fromAddress): array
    {
        if ($fromAddress === '') {
            return [];
        }

        $rows = Db::all(
            'SELECT sources FROM email_thread_insights
              WHERE mailbox_id = :mailbox AND sources::text LIKE :needle
              ORDER BY updated_at DESC LIMIT 20',
            ['mailbox' => $mailboxId, 'needle' => '%' . $fromAddress . '%'],
        );

        $out = [];
        foreach ($rows as $row) {
            $stored = Db::jsonColumn($row['sources']);
            if (isset($stored['payment_details']) && is_array($stored['payment_details'])) {
                $out[] = array_map('strval', $stored['payment_details']);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>       $classification
     * @param array<string, mixed>       $summary
     * @param list<array<string, mixed>> $messages
     */
    private static function storeInsight(int $mailboxId, string $threadKey, array $classification, array $summary, array $messages): void
    {
        try {
            Db::run(
                'INSERT INTO email_thread_insights
                    (mailbox_id, thread_key, classification, reason, summary, sources, deadline_at,
                     deadline_source, generator, model_name, created_at, updated_at)
                 VALUES (:mailbox, :thread, :class, :reason, :summary, :sources, :deadline, :dsource, :generator, :model, :created_at, :updated_at)
                 ON CONFLICT (mailbox_id, thread_key) DO UPDATE
                    SET classification = EXCLUDED.classification,
                        reason         = EXCLUDED.reason,
                        summary        = EXCLUDED.summary,
                        sources        = EXCLUDED.sources,
                        deadline_at    = EXCLUDED.deadline_at,
                        generator      = EXCLUDED.generator,
                        updated_at     = EXCLUDED.updated_at',
                [
                    'mailbox'  => $mailboxId,
                    'thread'   => $threadKey,
                    'class'    => $classification['classification'],
                    'reason'   => mb_substr((string) $classification['reason'], 0, 500),
                    'summary'  => mb_substr((string) ($summary['text'] ?? ''), 0, 2000),
                    // Message references only. No fetched business payload.
                    'sources'  => json_encode([
                        'messages'        => $summary['sources'] ?? [],
                        'payment_details' => PaymentChangeDetector::extract(
                            (string) ($messages[count($messages) - 1]['text'] ?? ''),
                        ),
                    ], JSON_UNESCAPED_UNICODE),
                    'deadline' => $classification['deadline'],
                    'dsource'  => mb_substr((string) ($classification['deadline_note'] ?? ''), 0, 240),
                    'generator' => $classification['generator'] === 'model' ? 'model' : 'rules',
                    'model'    => null,
                    // Two names for one value: native prepares cannot reuse a
                    // placeholder.
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[email-pulse] could not store insight for thread: ' . $e->getMessage());
        }
    }

    /** @param list<array<string, mixed>> $commitments */
    private static function storeCommitments(int $mailboxId, string $threadKey, array $commitments): void
    {
        foreach ($commitments as $commitment) {
            try {
                $existing = Db::first(
                    'SELECT commitment_id, state FROM email_commitments
                      WHERE mailbox_id = :mailbox AND thread_key = :thread AND source_quote = :quote',
                    ['mailbox' => $mailboxId, 'thread' => $threadKey, 'quote' => $commitment['source_quote']],
                );

                // A commitment a person has already acted on is never rewritten
                // by a re-analysis. Re-extraction must not undo a confirmation.
                if ($existing !== null) {
                    continue;
                }

                Db::insert('email_commitments', [
                    'mailbox_id'    => $mailboxId,
                    'thread_key'    => $threadKey,
                    'source_uid'    => (string) ($commitment['source_uid'] ?? ''),
                    'source_quote'  => $commitment['source_quote'],
                    'promised_by'   => (string) $commitment['promised_by'],
                    'promised_to'   => (string) $commitment['promised_to'],
                    'what'          => $commitment['what'],
                    'proposed_date' => $commitment['proposed_date'],
                    'timezone'      => $commitment['timezone'],
                    'direction'     => $commitment['direction'],
                    'state'         => $commitment['state'],
                    'created_at'    => gmdate('Y-m-d H:i:s'),
                    'updated_at'    => gmdate('Y-m-d H:i:s'),
                ], 'commitment_id');
            } catch (\Throwable $e) {
                error_log('[email-pulse] could not store commitment: ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    public static function presentCommitment(array $row): array
    {
        $state = (string) $row['state'];

        return [
            'commitment_id' => (int) $row['commitment_id'],
            'thread_key'    => $row['thread_key'],
            'what'          => $row['what'],
            'promised_by'   => $row['promised_by'],
            'promised_to'   => $row['promised_to'],
            'proposed_date' => $row['proposed_date'],
            'timezone'      => $row['timezone'],
            'direction'     => $row['direction'],
            'state'         => $state,
            'state_label'   => match ($state) {
                CommitmentExtractor::STATE_PROPOSAL  => 'Supplier proposal',
                CommitmentExtractor::STATE_INFERRED  => 'Inferred, not a firm promise',
                CommitmentExtractor::STATE_CONFIRMED => 'Confirmed by you',
                'completed'                          => 'Completed',
                'disputed'                           => 'Disputed',
                'cancelled'                          => 'Cancelled',
                default                              => 'Unknown',
            },
            // The two flags the radar renders on. Not agreed is the default, and
            // nothing flips it except a person.
            'user_confirmed' => $state === CommitmentExtractor::STATE_CONFIRMED,
            'agreed'         => $state === CommitmentExtractor::STATE_CONFIRMED,
            'source_quote'   => $row['source_quote'],
            'source_uid'     => $row['source_uid'] ?? null,
        ];
    }

    private static function greeting(string $displayName): string
    {
        $firstName = trim(explode(' ', trim($displayName))[0] ?? '');
        $hour = (int) gmdate('G');
        $part = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

        return $firstName === '' ? $part . '.' : $part . ', ' . $firstName . '.';
    }
}
