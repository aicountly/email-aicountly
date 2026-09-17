<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * Turning a queued send job into a message on the wire.
 *
 * The order of operations is the safety property, and it is:
 *
 *   1. the job row already exists, with its operation id      (caller did this)
 *   2. claim the job — an UPDATE that only one worker can win
 *   3. build the message
 *   4. submit it
 *   5. record the outcome, whatever it was
 *   6. only on ACCEPTED: file a copy in Sent and drop the draft
 *
 * Step 2 before step 4 is what stops two workers submitting the same job. Step
 * 5 before step 6 is what stops a failure to file the Sent copy being mistaken
 * for a failure to send.
 */
final class Sender
{
    /** @return 'accepted'|'failed'|'deferred'|'uncertain'|'skipped' */
    public static function dispatch(int $jobId): string
    {
        $job = SendJob::find($jobId);
        if ($job === null) {
            return 'skipped';
        }

        // An uncertain job is never picked up again automatically. Somebody has
        // to look at the recipient's inbox and decide.
        if ($job['status'] === SendJob::UNCERTAIN) {
            return 'skipped';
        }

        if (!SendJob::claim($jobId)) {
            // Another worker has it, or it was cancelled between due() and here.
            return 'skipped';
        }

        $draft = $job['draft_id'] === null
            ? null
            : Db::first('SELECT * FROM email_drafts WHERE draft_id = :id', ['id' => (int) $job['draft_id']]);

        if ($draft === null) {
            SendJob::recordOutcome($jobId, [
                'outcome'  => MailTransport::REJECTED,
                'queue_id' => null,
                'code'     => null,
                'detail'   => 'The draft behind this send no longer exists.',
            ]);

            return 'failed';
        }

        $mailbox = Db::first('SELECT * FROM email_mailboxes WHERE mailbox_id = :id', ['id' => (int) $job['mailbox_id']]);
        $identity = $mailbox === null ? null : MailboxIdentity::forMailbox($mailbox);

        if ($mailbox === null || $identity === null) {
            SendJob::recordOutcome($jobId, [
                'outcome'  => MailTransport::REJECTED,
                'queue_id' => null,
                'code'     => null,
                'detail'   => 'This mailbox has no usable mail-store credentials on this deployment.',
            ]);

            return 'failed';
        }

        $built = self::compose($draft, $mailbox);
        if ($built === null) {
            SendJob::recordOutcome($jobId, [
                'outcome'  => MailTransport::REJECTED,
                'queue_id' => null,
                'code'     => null,
                'detail'   => 'The draft has no valid recipients.',
            ]);

            return 'failed';
        }

        $result = MailAdapters::transport()->send($built['envelope_from'], $built['recipients'], $built['raw']);
        $status = SendJob::recordOutcome($jobId, $result);

        if ($status === SendJob::ACCEPTED) {
            self::fileSentCopy($identity, $built['raw']);
            self::retireDraft((int) $draft['draft_id']);
        }

        return match ($status) {
            SendJob::ACCEPTED  => 'accepted',
            SendJob::DEFERRED  => 'deferred',
            SendJob::UNCERTAIN => 'uncertain',
            default            => 'failed',
        };
    }

    /**
     * @param array<string, mixed> $draft
     * @param array<string, mixed> $mailbox
     * @return array{raw:string, recipients:list<string>, envelope_from:string}|null
     */
    public static function compose(array $draft, array $mailbox): ?array
    {
        $to  = AddressValidator::parseList(Db::jsonColumn($draft['to_addresses']))['valid'];
        $cc  = AddressValidator::parseList(Db::jsonColumn($draft['cc_addresses']))['valid'];
        $bcc = AddressValidator::parseList(Db::jsonColumn($draft['bcc_addresses']))['valid'];

        if ($to === [] && $cc === [] && $bcc === []) {
            return null;
        }

        $from = [
            'name'    => (string) ($mailbox['display_name'] ?? ''),
            'address' => (string) $mailbox['address'],
        ];

        $attachments = [];
        foreach (Db::all('SELECT filename, mime_type, content FROM email_draft_attachments WHERE draft_id = :id ORDER BY attachment_id', ['id' => (int) $draft['draft_id']]) as $row) {
            $attachments[] = [
                'filename'  => (string) $row['filename'],
                'mime_type' => (string) $row['mime_type'],
                'bytes'     => is_resource($row['content']) ? (string) stream_get_contents($row['content']) : (string) $row['content'],
            ];
        }

        $extraHeaders = [];
        $inReplyTo = trim((string) ($draft['in_reply_to'] ?? ''));
        if ($inReplyTo !== '') {
            $extraHeaders['In-Reply-To'] = $inReplyTo;
            $extraHeaders['References'] = trim((string) ($draft['thread_key'] ?? '') . ' ' . $inReplyTo);
        }

        // Envelope recipients are To + Cc + Bcc, de-duplicated. Bcc is here and
        // NOT in the headers: that is the whole meaning of Bcc.
        $recipients = [];
        foreach (AddressValidator::dedupe(array_merge($to, $cc, $bcc)) as $recipient) {
            $recipients[] = $recipient['address'];
        }

        $raw = MessageBuilder::build(
            $from,
            $to,
            $cc,
            (string) $draft['subject'],
            (string) $draft['body_text'],
            (string) $draft['body_html'],
            $attachments,
            $extraHeaders,
        );

        return [
            'raw'           => $raw,
            'recipients'    => $recipients,
            'envelope_from' => (string) $mailbox['address'],
        ];
    }

    /**
     * File the Sent copy.
     *
     * Best effort on purpose: the message is already on the wire, and failing
     * the send because the Sent folder would not take a copy would be reporting
     * the wrong thing. The failure is logged.
     */
    private static function fileSentCopy(MailboxIdentity $identity, string $raw): void
    {
        $folder = Env::get('MAIL_SENT_FOLDER', 'INBOX.Sent');
        $result = MailAdapters::store()->append($identity, $folder, $raw, ['\\Seen']);

        if (!$result['ok']) {
            error_log('[email-send] sent copy not filed for mailbox ' . $identity->mailboxId . ': ' . (string) $result['error']);
        }
    }

    /**
     * The draft is gone once the message exists in the mail store.
     *
     * Keeping it would be a second copy of the same content in a second place,
     * which is exactly what the storage decision in MailStore rules out.
     */
    private static function retireDraft(int $draftId): void
    {
        try {
            Db::run('DELETE FROM email_drafts WHERE draft_id = :id', ['id' => $draftId]);
        } catch (\Throwable $e) {
            error_log('[email-send] could not retire draft ' . $draftId . ': ' . $e->getMessage());
        }
    }
}
