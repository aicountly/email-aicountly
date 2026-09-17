<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\MailboxAccess;

/**
 * Mailboxes, their folders and their threads.
 *
 * Every route here resolves the mailbox through MailboxAccess first. Changing
 * the id in the URL gets a 404 for a mailbox you cannot see and a 403 for one
 * you can see but may not use that way — never a quietly empty list.
 */
final class MailboxesController extends Controller
{
    public function index(): void
    {
        $mailboxes = MailboxAccess::listFor($this->auth(), $this->account());

        Http::data(array_map(static fn (array $m) => [
            'mailbox_id'   => (int) $m['mailbox_id'],
            'address'      => $m['address'],
            'display_name' => $m['display_name'],
            'kind'         => $m['kind'],
            'cmp_id'       => $m['cmp_id'] === null ? null : (int) $m['cmp_id'],
            'role'         => $m['member_role'] ?? 'owner',
            'permissions'  => $m['member_permissions'] === null
                ? [MailboxAccess::READ, MailboxAccess::SEND_AS, MailboxAccess::MANAGE]
                : Db::jsonColumn($m['member_permissions']),
            'status'       => $m['status'],
        ], $mailboxes));
    }

    public function folders(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);

        $result = $this->store()->folders($identity);
        $this->assertStoreOk($result);

        // Labels are Email's own, and they sit alongside the store's folders
        // rather than pretending to be them.
        $labels = Db::all(
            'SELECT label_id, name, colour, folder_path, system_role FROM email_labels WHERE mailbox_id = :id ORDER BY name',
            ['id' => (int) $resolved['mailbox']['mailbox_id']],
        );

        Http::json(200, [
            'data' => ['folders' => $result['folders'], 'labels' => $labels],
            'meta' => ['source' => 'mail_store', 'fetched_at' => gmdate('c')],
        ]);
    }

    /**
     * One cursor page of threads.
     *
     * Cursor, not offset: a mailbox changes while it is being read, and offset
     * paging shows some messages twice and skips others as new mail arrives.
     */
    public function threads(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);

        $folder = (string) (Http::param('folder') ?? 'INBOX');
        $limit = Http::limit(40);
        $cursor = Http::param('cursor');

        $filters = [
            'unread'  => Http::boolParam('unread'),
            'flagged' => Http::boolParam('flagged'),
            'from'    => Http::param('from'),
            'since'   => Http::param('since'),
        ];

        $result = $this->store()->threads($identity, $folder, $cursor === '' ? null : $cursor, $limit, array_filter($filters, static fn ($v) => $v !== null && $v !== ''));
        $this->assertStoreOk($result);

        $this->indexRefs((int) $resolved['mailbox']['mailbox_id'], $folder, $result['threads']);

        Http::page(
            array_map([$this, 'presentSummary'], $result['threads']),
            $limit,
            $result['next_cursor'],
            null,
            ['folder' => $folder, 'source' => 'mail_store', 'fetched_at' => gmdate('c')],
        );
    }

    public function quota(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);

        $result = $this->store()->quota($identity);
        $this->assertStoreOk($result);

        Http::data([
            'used_bytes'  => $result['used_bytes'],
            'quota_bytes' => $result['quota_bytes'] ?? ($resolved['mailbox']['quota_bytes'] === null ? null : (int) $resolved['mailbox']['quota_bytes']),
            // Null is an answer. Plenty of IMAP servers publish no quota, and a
            // zero here would be read on screen as "full".
            'known'       => $result['used_bytes'] !== null,
            'note'        => $result['used_bytes'] === null
                ? 'This mail server does not publish a quota for this mailbox.'
                : null,
        ]);
    }

    /**
     * Keep Email's reference index in step with what the store just returned.
     *
     * This is an INDEX, not a copy: a reference, a subject, a sender and a short
     * snippet, so search and the decision inbox have something to work on
     * without a round trip per thread. There is no body column and no
     * attachment column in the table it writes to.
     *
     * @param list<array<string, mixed>> $threads
     */
    private function indexRefs(int $mailboxId, string $folder, array $threads): void
    {
        foreach ($threads as $thread) {
            try {
                Db::run(
                    'INSERT INTO email_message_refs
                        (mailbox_id, folder, remote_uid, message_id, thread_key, subject,
                         from_address, from_name, snippet, internal_date, size_bytes, has_attachments, indexed_at)
                     VALUES (:mailbox, :folder, :uid, :mid, :thread, :subject, :from_addr, :from_name, :snippet, :date, :size, :att, :now)
                     ON CONFLICT (mailbox_id, folder, remote_uid) DO UPDATE
                        SET subject = EXCLUDED.subject, thread_key = EXCLUDED.thread_key,
                            from_address = EXCLUDED.from_address, from_name = EXCLUDED.from_name,
                            has_attachments = EXCLUDED.has_attachments, indexed_at = EXCLUDED.indexed_at',
                    [
                        'mailbox'   => $mailboxId,
                        'folder'    => $folder,
                        'uid'       => (string) $thread['uid'],
                        'mid'       => (string) ($thread['message_id'] ?? ''),
                        'thread'    => (string) ($thread['thread_key'] ?? ''),
                        'subject'   => mb_substr((string) ($thread['subject'] ?? ''), 0, 500),
                        'from_addr' => mb_substr((string) ($thread['from'][0]['address'] ?? ''), 0, 320),
                        'from_name' => mb_substr((string) ($thread['from'][0]['name'] ?? ''), 0, 240),
                        'snippet'   => '',
                        'date'      => isset($thread['date']) ? gmdate('Y-m-d H:i:s', strtotime((string) $thread['date']) ?: time()) : null,
                        'size'      => (int) ($thread['size'] ?? 0),
                        'att'       => (bool) ($thread['has_attachments'] ?? false),
                        'now'       => gmdate('Y-m-d H:i:s'),
                    ],
                );
            } catch (\Throwable $e) {
                // The index is an optimisation. A failure to write it must not
                // stop somebody reading their inbox.
                error_log('[email-index] could not index message: ' . $e->getMessage());
            }
        }
    }

    /** @param array<string, mixed> $thread @return array<string, mixed> */
    private function presentSummary(array $thread): array
    {
        return [
            'uid'         => $thread['uid'],
            'thread_key'  => $thread['thread_key'] ?? '',
            'message_id'  => $thread['message_id'] ?? '',
            'subject'     => $thread['subject'] ?? '(no subject)',
            'from'        => $thread['from'] ?? [],
            'to'          => $thread['to'] ?? [],
            'date'        => $thread['date'] ?? null,
            'seen'        => (bool) ($thread['seen'] ?? false),
            'flagged'     => (bool) ($thread['flagged'] ?? false),
            'answered'    => (bool) ($thread['answered'] ?? false),
            'size'        => $thread['size'] ?? null,
            'has_attachments' => (bool) ($thread['has_attachments'] ?? false),
        ];
    }
}
