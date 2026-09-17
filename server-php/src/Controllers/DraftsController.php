<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Mail\AddressValidator;
use Aicountly\Api\Mail\MailAdapters;
use Aicountly\Api\Mail\Sender;
use Aicountly\Api\Mail\SendJob;
use Aicountly\Api\MailboxAccess;
use Aicountly\Api\RateLimit;

/**
 * Drafts, and turning one into a message on the wire.
 *
 * TWO THINGS WORTH READING THE CODE FOR.
 *
 * Draft conflict. Every draft carries a version. A save that presents the wrong
 * version is a 409 carrying the current server copy, so the UI can show the two
 * side by side. Last-write-wins silently loses whichever tab was slower, and
 * the user never finds out which half of their reply went missing.
 *
 * Send is idempotent by operation id. The id is minted by the browser (or here,
 * once) and the job row is written BEFORE the transport is touched. A double
 * click, a retried request or a reloaded tab finds the same job and its
 * existing outcome — it does not start a second submission.
 */
final class DraftsController extends Controller
{
    public function index(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);

        $rows = Db::all(
            'SELECT draft_id, subject, to_addresses, cc_addresses, thread_key, origin, version, updated_at
               FROM email_drafts WHERE mailbox_id = :id ORDER BY updated_at DESC LIMIT 100',
            ['id' => (int) $resolved['mailbox']['mailbox_id']],
        );

        Http::data(array_map(static fn (array $row) => [
            'draft_id'   => (int) $row['draft_id'],
            'subject'    => $row['subject'],
            'to'         => Db::jsonColumn($row['to_addresses']),
            'cc'         => Db::jsonColumn($row['cc_addresses']),
            'thread_key' => $row['thread_key'],
            'origin'     => $row['origin'],
            'version'    => (int) $row['version'],
            'updated_at' => $row['updated_at'],
        ], $rows));
    }

    public function create(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $parsed = $this->parseRecipients();

        $draftId = Db::insert('email_drafts', [
            'mailbox_id'    => (int) $resolved['mailbox']['mailbox_id'],
            'account_id'    => $this->account()->accountId,
            'author_uuid'   => $this->auth()->uuid,
            'in_reply_to'   => AddressValidator::safeHeaderText((string) (Http::param('in_reply_to') ?? '')),
            'thread_key'    => AddressValidator::safeHeaderText((string) (Http::param('thread_key') ?? '')),
            'to_addresses'  => $parsed['to'],
            'cc_addresses'  => $parsed['cc'],
            'bcc_addresses' => $parsed['bcc'],
            'subject'       => AddressValidator::safeHeaderText((string) (Http::param('subject') ?? '')),
            'body_text'     => (string) (Http::param('body_text') ?? ''),
            'body_html'     => (string) (Http::param('body_html') ?? ''),
            // An AI-written draft is marked as one and stays a draft until a
            // person sends it.
            'origin'        => Http::param('origin') === 'ai_suggested' ? 'ai_suggested' : 'user',
            'version'       => 1,
            'created_at'    => gmdate('Y-m-d H:i:s'),
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], 'draft_id');

        Audit::record($this->auth(), $this->account(), 'draft.created', 'draft', $draftId, 'ok', [
            'mailbox_id' => (int) $resolved['mailbox']['mailbox_id'],
            'recipients' => count($parsed['to']) + count($parsed['cc']) + count($parsed['bcc']),
        ]);

        Http::data($this->present((int) $draftId) + ['invalid_recipients' => $parsed['invalid']], 201);
    }

    public function update(string $mailboxId, string $draftId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $draft = $this->requireDraft((int) $resolved['mailbox']['mailbox_id'], $draftId);

        $expected = Http::intParam('version');
        if ($expected !== null && $expected !== (int) $draft['version']) {
            // The whole server copy comes back, so the UI can show both.
            Http::conflict('This draft was changed somewhere else after you opened it.', [
                'code'       => 'draft_conflict',
                'your_version' => $expected,
                'current_version' => (int) $draft['version'],
                'current'    => $this->present((int) $draft['draft_id']),
                'retryable'  => false,
            ]);
        }

        $parsed = $this->parseRecipients();

        Db::update('email_drafts', [
            'to_addresses'  => $parsed['to'],
            'cc_addresses'  => $parsed['cc'],
            'bcc_addresses' => $parsed['bcc'],
            'subject'       => AddressValidator::safeHeaderText((string) (Http::param('subject') ?? $draft['subject'])),
            'body_text'     => (string) (Http::param('body_text') ?? $draft['body_text']),
            'body_html'     => (string) (Http::param('body_html') ?? $draft['body_html']),
            'version'       => (int) $draft['version'] + 1,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], ['draft_id' => (int) $draft['draft_id']]);

        Http::data($this->present((int) $draft['draft_id']) + ['invalid_recipients' => $parsed['invalid']]);
    }

    public function destroy(string $mailboxId, string $draftId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $draft = $this->requireDraft((int) $resolved['mailbox']['mailbox_id'], $draftId);

        Db::run('DELETE FROM email_drafts WHERE draft_id = :id', ['id' => (int) $draft['draft_id']]);
        Audit::record($this->auth(), $this->account(), 'draft.deleted', 'draft', (int) $draft['draft_id'], 'ok', []);

        Http::data(['deleted' => true, 'draft_id' => (int) $draft['draft_id']]);
    }

    /** Send now — after the account's undo window, which is a real server-side hold. */
    public function send(string $mailboxId, string $draftId): void
    {
        $this->queue($mailboxId, $draftId, null, null);
    }

    /** Send later. The timezone is required, because "09:00" alone is three different moments. */
    public function schedule(string $mailboxId, string $draftId): void
    {
        $at = trim((string) (Http::param('scheduled_for') ?? ''));
        $timezone = trim((string) (Http::param('timezone') ?? ''));

        if ($at === '' || $timezone === '') {
            Http::validationFailed('A scheduled send needs both a time and the timezone it is in.', [
                'fields' => ['scheduled_for', 'timezone'],
            ]);
        }

        try {
            $zone = new \DateTimeZone($timezone);
            $when = new \DateTimeImmutable($at, $zone);
        } catch (\Throwable) {
            Http::validationFailed('That time or timezone could not be understood.', ['fields' => ['scheduled_for', 'timezone']]);
        }

        $utc = $when->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->getTimestamp() <= time()) {
            Http::validationFailed('That time is in the past in ' . $timezone . '.', ['field' => 'scheduled_for']);
        }

        $this->queue($mailboxId, $draftId, $utc->format('Y-m-d H:i:s'), $timezone);
    }

    /** Undo-send, and unscheduling. Both are the same thing: cancel a job that has not gone out. */
    public function cancel(string $mailboxId, string $jobId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);

        $job = SendJob::find((int) $jobId);
        if ($job === null || (int) $job['mailbox_id'] !== (int) $resolved['mailbox']['mailbox_id']) {
            Http::notFound('That scheduled send does not exist in this mailbox.');
        }

        if (!SendJob::cancel((int) $jobId)) {
            Http::conflict('That message has already left — it can no longer be cancelled.', [
                'status' => $job['status'],
                'retryable' => false,
            ]);
        }

        Audit::record($this->auth(), $this->account(), 'send.cancelled', 'send_job', (int) $jobId, 'ok', []);
        Http::data(SendJob::present(SendJob::find((int) $jobId) ?? $job));
    }

    public function sendStatus(string $mailboxId, string $jobId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $job = SendJob::find((int) $jobId);

        if ($job === null || (int) $job['mailbox_id'] !== (int) $resolved['mailbox']['mailbox_id']) {
            Http::notFound('That send does not exist in this mailbox.');
        }

        Http::data(SendJob::present($job));
    }

    // -----------------------------------------------------------------------

    private function queue(string $mailboxId, string $draftId, ?string $scheduledFor, ?string $timezone): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $account = $this->account();
        $draft = $this->requireDraft((int) $resolved['mailbox']['mailbox_id'], $draftId);

        if (!MailAdapters::transport()->isConfigured()) {
            $describe = MailAdapters::transport()->describe();
            Http::notConfigured((string) ($describe['reason'] ?? 'No outbound mail transport is configured.'), [
                'admin_hint' => $describe['admin_hint'] ?? null,
                'subsystem'  => 'mail_transport',
            ]);
        }

        $to  = AddressValidator::parseList(Db::jsonColumn($draft['to_addresses']));
        $cc  = AddressValidator::parseList(Db::jsonColumn($draft['cc_addresses']));
        $bcc = AddressValidator::parseList(Db::jsonColumn($draft['bcc_addresses']));
        $recipientCount = count($to['valid']) + count($cc['valid']) + count($bcc['valid']);

        if ($recipientCount === 0) {
            Http::validationFailed('This draft has no valid recipients.', ['field' => 'to']);
        }
        if ($recipientCount > AddressValidator::MAX_RECIPIENTS) {
            Http::validationFailed('Too many recipients (the limit is ' . AddressValidator::MAX_RECIPIENTS . ').', ['field' => 'to']);
        }

        RateLimit::consume('send', $account);

        // The operation id is the idempotency boundary. The client is expected
        // to mint one per compose and present the SAME one on every retry.
        $operationId = Http::idempotencyKey();
        if ($operationId === '') {
            $operationId = 'srv-' . bin2hex(random_bytes(12));
        }

        $undoSeconds = $scheduledFor !== null ? 0 : $this->undoSeconds();

        $job = SendJob::open(
            (int) $resolved['mailbox']['mailbox_id'],
            $account->accountId,
            $operationId,
            (int) $draft['draft_id'],
            ['recipient_count' => $recipientCount, 'has_attachments' => $this->hasAttachments((int) $draft['draft_id'])],
            $scheduledFor,
            $timezone ?? $this->accountTimezone(),
            $undoSeconds,
        );

        Audit::record($this->auth(), $account, $scheduledFor === null ? 'send.queued' : 'send.scheduled', 'send_job', (int) $job['job_id'], 'ok', [
            'mailbox_id'      => (int) $resolved['mailbox']['mailbox_id'],
            'recipient_count' => $recipientCount,
            'scheduled_for'   => $scheduledFor,
            'timezone'        => $timezone,
        ]);

        // With no undo window and no schedule, go now — the user pressed Send
        // and expects it gone. With one, the job waits and the dispatcher picks
        // it up, which is what makes Undo a real hold rather than a UI delay.
        if ($scheduledFor === null && $undoSeconds === 0) {
            Sender::dispatch((int) $job['job_id']);
            $job = SendJob::find((int) $job['job_id']) ?? $job;
        }

        Http::data(SendJob::present($job) + [
            'invalid_recipients' => array_merge($to['invalid'], $cc['invalid'], $bcc['invalid']),
            'undo_seconds'       => $undoSeconds,
        ], 202);
    }

    /** @return array{to:list<array<string,string>>, cc:list<array<string,string>>, bcc:list<array<string,string>>, invalid:list<string>} */
    private function parseRecipients(): array
    {
        $to  = AddressValidator::parseList(Http::body()['to'] ?? Http::param('to', ''));
        $cc  = AddressValidator::parseList(Http::body()['cc'] ?? Http::param('cc', ''));
        $bcc = AddressValidator::parseList(Http::body()['bcc'] ?? Http::param('bcc', ''));

        return [
            'to'      => $to['valid'],
            'cc'      => $cc['valid'],
            'bcc'     => $bcc['valid'],
            // Reported, not silently dropped: a typo'd address the user never
            // hears about is a message they think they sent.
            'invalid' => array_merge($to['invalid'], $cc['invalid'], $bcc['invalid']),
        ];
    }

    /** @return array<string, mixed> */
    private function requireDraft(int $mailboxId, string $draftId): array
    {
        if (!ctype_digit($draftId)) {
            Http::notFound('That draft does not exist in this mailbox.');
        }

        $draft = Db::first(
            'SELECT * FROM email_drafts WHERE draft_id = :id AND mailbox_id = :mailbox',
            ['id' => (int) $draftId, 'mailbox' => $mailboxId],
        );

        if ($draft === null) {
            Http::notFound('That draft does not exist in this mailbox.');
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    private function present(int $draftId): array
    {
        $draft = Db::first('SELECT * FROM email_drafts WHERE draft_id = :id', ['id' => $draftId]);
        if ($draft === null) {
            Http::notFound('That draft no longer exists.');
        }

        return [
            'draft_id'   => (int) $draft['draft_id'],
            'to'         => Db::jsonColumn($draft['to_addresses']),
            'cc'         => Db::jsonColumn($draft['cc_addresses']),
            'bcc'        => Db::jsonColumn($draft['bcc_addresses']),
            'subject'    => $draft['subject'],
            'body_text'  => $draft['body_text'],
            'body_html'  => $draft['body_html'],
            'in_reply_to' => $draft['in_reply_to'],
            'thread_key' => $draft['thread_key'],
            'origin'     => $draft['origin'],
            'version'    => (int) $draft['version'],
            'updated_at' => $draft['updated_at'],
            'status'     => 'draft_saved',
        ];
    }

    private function hasAttachments(int $draftId): bool
    {
        return (int) (Db::scalar('SELECT COUNT(*) FROM email_draft_attachments WHERE draft_id = :id', ['id' => $draftId]) ?? 0) > 0;
    }

    private function undoSeconds(): int
    {
        $row = Db::first('SELECT undo_seconds FROM email_preferences WHERE account_id = :id', ['id' => $this->account()->accountId]);
        $configured = $row === null ? (int) Env::get('MAIL_UNDO_SECONDS', '10') : (int) $row['undo_seconds'];

        return max(0, min(60, $configured));
    }

    private function accountTimezone(): string
    {
        $row = Db::first('SELECT timezone FROM email_preferences WHERE account_id = :id', ['id' => $this->account()->accountId]);

        return (string) ($row['timezone'] ?? Env::get('EMAIL_DEFAULT_TIMEZONE', 'Asia/Kolkata'));
    }
}
