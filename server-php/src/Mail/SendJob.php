<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Db;

/**
 * One attempt to put one message on the wire, and what became of it.
 *
 * FOUR THINGS ARE DIFFERENT AND THE PRODUCT SAYS WHICH:
 *
 *   draft saved   the text is in Email's database. Nothing has been sent.
 *   queued        a send job exists. Still nothing on the wire — this covers
 *                 the undo window and a scheduled send.
 *   accepted      the outbound mail server took the message and, usually, gave
 *                 a queue id. The RECIPIENT'S server has not been heard from,
 *                 so this is never rendered as "delivered".
 *   failed        a permanent refusal, with the reason.
 *
 * and the one that matters most:
 *
 *   uncertain     the connection died after the body was written. It may be in
 *                 the mail server's queue and it may not. Email STOPS here and
 *                 asks, because the automatic choice is either a lost message
 *                 or a duplicate one and neither is ours to make silently.
 *
 * THE OPERATION ID IS MINTED AND STORED BEFORE THE TRANSPORT IS CALLED. That
 * ordering is the whole defence against a double send: a second press, a
 * retried HTTP request or a reloaded tab finds the existing row and its
 * existing outcome instead of starting a second submission.
 */
final class SendJob
{
    public const TABLE = 'email_send_jobs';

    public const QUEUED     = 'queued';
    public const SUBMITTING = 'submitting';
    public const ACCEPTED   = 'accepted';
    public const FAILED     = 'failed';
    public const DEFERRED   = 'deferred';
    public const UNCERTAIN  = 'uncertain';
    public const CANCELLED  = 'cancelled';

    /**
     * Find the job for this operation id, or create it.
     *
     * @param array<string, mixed> $summary recipient COUNTS and ids — never addresses or body
     * @return array<string, mixed>
     */
    public static function open(
        int $mailboxId,
        int $accountId,
        string $operationId,
        ?int $draftId,
        array $summary,
        ?string $scheduledFor,
        string $timezone,
        int $undoSeconds,
    ): array {
        $existing = self::findByOperation($mailboxId, $operationId);
        if ($existing !== null) {
            return $existing;
        }

        $releaseAfter = $scheduledFor !== null
            ? $scheduledFor
            : gmdate('Y-m-d H:i:s', time() + max(0, $undoSeconds));

        $jobId = Db::insert(self::TABLE, [
            'mailbox_id'      => $mailboxId,
            'account_id'      => $accountId,
            'draft_id'        => $draftId,
            'operation_id'    => $operationId,
            'status'          => self::QUEUED,
            'attempts'        => 0,
            'recipient_count' => (int) ($summary['recipient_count'] ?? 0),
            'scheduled_for'   => $scheduledFor,
            'schedule_timezone' => $timezone,
            'release_after'   => $releaseAfter,
            'summary'         => $summary,
            'created_at'      => self::now(),
            'updated_at'      => self::now(),
        ], 'job_id');

        return self::find((int) $jobId) ?? [];
    }

    /** @return array<string, mixed>|null */
    public static function find(int $jobId): ?array
    {
        return Db::first('SELECT * FROM ' . self::TABLE . ' WHERE job_id = :id', ['id' => $jobId]);
    }

    /** @return array<string, mixed>|null */
    public static function findByOperation(int $mailboxId, string $operationId): ?array
    {
        return Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE mailbox_id = :mailbox AND operation_id = :op',
            ['mailbox' => $mailboxId, 'op' => $operationId],
        );
    }

    /**
     * Claim the job for submission, or return false because somebody else has it.
     *
     * The UPDATE ... WHERE status IN (queued, deferred) is the lock: two workers
     * racing on the same job produce one winner, and the loser does not submit.
     */
    public static function claim(int $jobId): bool
    {
        $claimed = Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = :submitting, attempts = attempts + 1, last_attempt_at = :attempted_at, updated_at = :updated_at
              WHERE job_id = :id AND status IN ('queued', 'deferred')",
            // Native prepares do not allow one placeholder to appear twice.
            [
                'submitting'   => self::SUBMITTING,
                'attempted_at' => self::now(),
                'updated_at'   => self::now(),
                'id'           => $jobId,
            ],
        )->rowCount();

        return $claimed === 1;
    }

    /** @param array{outcome:string, queue_id:?string, code:?int, detail:?string} $result */
    public static function recordOutcome(int $jobId, array $result): string
    {
        $status = match ($result['outcome']) {
            MailTransport::ACCEPTED  => self::ACCEPTED,
            MailTransport::DEFERRED  => self::DEFERRED,
            MailTransport::UNCERTAIN => self::UNCERTAIN,
            default                  => self::FAILED,
        };

        Db::update(self::TABLE, [
            'status'        => $status,
            'transport_code' => $result['code'],
            'queue_id'      => $result['queue_id'],
            'last_detail'   => self::trim((string) ($result['detail'] ?? '')),
            'completed_at'  => in_array($status, [self::ACCEPTED, self::FAILED], true) ? self::now() : null,
            'updated_at'    => self::now(),
        ], ['job_id' => $jobId]);

        return $status;
    }

    /** Undo-send, and unscheduling. Only possible while nothing has been submitted. */
    public static function cancel(int $jobId): bool
    {
        $cancelled = Db::run(
            'UPDATE ' . self::TABLE . "
                SET status = :cancelled, updated_at = :now
              WHERE job_id = :id AND status = 'queued'",
            ['cancelled' => self::CANCELLED, 'now' => self::now(), 'id' => $jobId],
        )->rowCount();

        return $cancelled === 1;
    }

    /**
     * Jobs whose hold has expired and which are ready to go on the wire.
     *
     * Email's OWN jobs, and nothing else. This is the one scheduled thing in the
     * product, and it exists because a scheduled send has to happen at a time
     * rather than when somebody opens a page. It never reads or writes another
     * product's data, which is what separates it from the cross-product
     * synchronisation this architecture forbids.
     *
     * @return list<array<string, mixed>>
     */
    public static function due(int $limit = 25): array
    {
        return Db::all(
            'SELECT * FROM ' . self::TABLE . "
              WHERE status IN ('queued', 'deferred')
                AND release_after <= :now
                AND attempts < 5
              ORDER BY release_after
              LIMIT " . (int) $limit,
            ['now' => self::now()],
        );
    }

    /**
     * What the UI is allowed to say about a job.
     *
     * `delivered` is not one of the answers. Nothing in this deployment observes
     * delivery, and a product that shows a tick it cannot justify has taught its
     * users to trust a guess.
     *
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    public static function present(array $job): array
    {
        $status = (string) $job['status'];

        return [
            'job_id'        => (int) $job['job_id'],
            'operation_id'  => (string) $job['operation_id'],
            'status'        => $status,
            'label'         => match ($status) {
                self::QUEUED     => $job['scheduled_for'] !== null ? 'Scheduled' : 'Queued',
                self::SUBMITTING => 'Sending',
                self::ACCEPTED   => 'Accepted by the mail server',
                self::DEFERRED   => 'Waiting to retry',
                self::FAILED     => 'Send failed',
                self::UNCERTAIN  => 'Outcome uncertain',
                self::CANCELLED  => 'Cancelled',
                default          => 'Unknown',
            },
            'explanation'   => match ($status) {
                self::ACCEPTED  => 'The outbound mail server accepted this message. Delivery to the recipient is not confirmed.',
                self::UNCERTAIN => 'The connection dropped after the message was written. It may already have been sent — check the recipient before sending it again.',
                self::DEFERRED  => 'The mail server asked us to try again later. Email will retry this same message.',
                self::FAILED    => (string) ($job['last_detail'] ?? 'The mail server refused the message.'),
                default         => null,
            },
            'queue_id'      => $job['queue_id'] ?? null,
            'attempts'      => (int) $job['attempts'],
            'scheduled_for' => $job['scheduled_for'] ?? null,
            'timezone'      => $job['schedule_timezone'] ?? null,
            'release_after' => $job['release_after'] ?? null,
            'can_cancel'    => $status === self::QUEUED,
            'can_retry'     => in_array($status, [self::DEFERRED, self::FAILED], true),
            // Never offered automatically, and never as one click: an uncertain
            // outcome needs the person to decide.
            'needs_decision' => $status === self::UNCERTAIN,
        ];
    }

    private static function trim(string $value): string
    {
        return mb_substr(trim(preg_replace('/\s+/', ' ', $value) ?? $value), 0, 480);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
