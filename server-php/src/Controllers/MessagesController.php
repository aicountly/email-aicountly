<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Env;
use Aicountly\Api\Http;

/**
 * Changing a message: read, starred, archived, moved, spam, trash, restore.
 *
 * All of it goes through the mail store, because the mail store is where the
 * message is. Email's reference index is updated afterwards and is allowed to
 * be briefly behind; what it is never allowed to do is be the answer. A product
 * that marks a message read in its own table and calls it done shows a
 * different inbox from every other client the user has.
 */
final class MessagesController extends Controller
{
    private const ACTIONS = ['read', 'unread', 'star', 'unstar', 'archive', 'spam', 'not_spam', 'trash', 'restore', 'move'];

    public function update(string $mailboxId, string $uid): void
    {
        $this->apply($mailboxId, [$uid]);
    }

    /**
     * The same operation over many messages.
     *
     * Partial success is reported as partial. An IMAP server that accepts nine
     * of ten flag changes has done nine, and telling the user "done" is a lie
     * they will discover later.
     */
    public function bulk(string $mailboxId): void
    {
        $uids = array_map('strval', Http::arrayParam('uids'));
        if ($uids === []) {
            Http::validationFailed('No messages were selected.', ['field' => 'uids']);
        }
        if (count($uids) > 500) {
            Http::validationFailed('Too many messages in one operation (the limit is 500).', ['field' => 'uids', 'limit' => 500]);
        }

        $this->apply($mailboxId, $uids);
    }

    /** @param list<string> $uids */
    private function apply(string $mailboxId, array $uids): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);

        $action = (string) (Http::param('action') ?? '');
        if (!in_array($action, self::ACTIONS, true)) {
            Http::validationFailed('Unknown action.', ['field' => 'action', 'allowed' => self::ACTIONS]);
        }

        $folder = (string) (Http::param('folder') ?? 'INBOX');
        $store = $this->store();

        $result = match ($action) {
            'read'     => $store->setFlags($identity, $folder, $uids, ['\\Seen'], []),
            'unread'   => $store->setFlags($identity, $folder, $uids, [], ['\\Seen']),
            'star'     => $store->setFlags($identity, $folder, $uids, ['\\Flagged'], []),
            'unstar'   => $store->setFlags($identity, $folder, $uids, [], ['\\Flagged']),
            'archive'  => $store->move($identity, $folder, $uids, Env::get('MAIL_ARCHIVE_FOLDER', 'INBOX.Archive')),
            'spam'     => $store->move($identity, $folder, $uids, Env::get('MAIL_SPAM_FOLDER', 'INBOX.spam')),
            'not_spam' => $store->move($identity, $folder, $uids, 'INBOX'),
            'trash'    => $store->move($identity, $folder, $uids, Env::get('MAIL_TRASH_FOLDER', 'INBOX.Trash')),
            // Restore is a move back, to a folder the caller names. Nothing is
            // deleted permanently by this endpoint at all.
            'restore'  => $store->move($identity, $folder, $uids, (string) (Http::param('target_folder') ?? 'INBOX')),
            'move'     => $store->move($identity, $folder, $uids, $this->requireTarget()),
            default    => ['ok' => false, 'error' => 'Unknown action.'],
        };

        $changed = (int) ($result['updated'] ?? $result['moved'] ?? 0);
        $requested = count($uids);

        Audit::record($this->auth(), $this->account(), 'message.' . $action, 'message', null,
            $result['ok'] ? ($changed === $requested ? 'ok' : 'partial') : 'failed',
            ['mailbox_id' => (int) $resolved['mailbox']['mailbox_id'], 'requested' => $requested, 'changed' => $changed],
        );

        if (!$result['ok']) {
            Http::error(503, 'mailbox_unavailable', (string) ($result['error'] ?? 'The mailbox refused the change.'), ['retryable' => true]);
        }

        $this->syncIndex((int) $resolved['mailbox']['mailbox_id'], $folder, $uids, $action);

        Http::data([
            'action'    => $action,
            'requested' => $requested,
            'changed'   => $changed,
            'complete'  => $changed === $requested,
            'note'      => $changed === $requested ? null : 'Some messages were not changed — reload the folder to see the current state.',
        ]);
    }

    private function requireTarget(): string
    {
        $target = trim((string) (Http::param('target_folder') ?? ''));
        if ($target === '') {
            Http::validationFailed('A destination folder is required to move messages.', ['field' => 'target_folder']);
        }

        return $target;
    }

    /**
     * Keep the reference index roughly in step.
     *
     * Best effort, deliberately: the store has already accepted the change, and
     * a failure here means a stale row in a list that the next fetch corrects.
     * It must never turn a successful move into an error.
     *
     * @param list<string> $uids
     */
    private function syncIndex(int $mailboxId, string $folder, array $uids, string $action): void
    {
        if (!in_array($action, ['archive', 'spam', 'not_spam', 'trash', 'restore', 'move'], true)) {
            return;
        }

        try {
            \Aicountly\Api\Db::run(
                'DELETE FROM email_message_refs WHERE mailbox_id = :mailbox AND folder = :folder AND remote_uid = ANY(:uids)',
                ['mailbox' => $mailboxId, 'folder' => $folder, 'uids' => '{' . implode(',', array_map('intval', $uids)) . '}'],
            );
        } catch (\Throwable $e) {
            error_log('[email-index] could not prune moved messages: ' . $e->getMessage());
        }
    }
}
