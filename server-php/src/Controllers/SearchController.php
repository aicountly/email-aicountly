<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\RateLimit;

/**
 * Search, over the mail store and over Email's reference index.
 *
 * The store is authoritative and slow; the index is fast and only holds
 * subjects, senders and snippets. Both are queried, and the response says which
 * results came from where, so "not found" is never ambiguous between "no such
 * message" and "the store did not answer".
 */
final class SearchController extends Controller
{
    public function search(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        RateLimit::consume('search', $this->account());

        $query = trim((string) (Http::param('q') ?? ''));
        if (mb_strlen($query) < 2) {
            Http::validationFailed('Enter at least two characters to search.', ['field' => 'q']);
        }

        $folder = (string) (Http::param('folder') ?? 'INBOX');
        $limit = Http::limit(40);

        $indexed = Db::all(
            'SELECT remote_uid, folder, thread_key, subject, from_address, from_name, internal_date, has_attachments
               FROM email_message_refs
              WHERE mailbox_id = :mailbox
                AND (subject ILIKE :like_subject OR from_address ILIKE :like_address OR from_name ILIKE :like_name)
              ORDER BY internal_date DESC NULLS LAST
              LIMIT :limit',
            // PDO with native prepares cannot reuse one named placeholder, so
            // the same pattern is bound three times under three names.
            [
                'mailbox'      => (int) $resolved['mailbox']['mailbox_id'],
                'like_subject' => '%' . $query . '%',
                'like_address' => '%' . $query . '%',
                'like_name'    => '%' . $query . '%',
                'limit'        => $limit,
            ],
        );

        $identity = $this->identity($resolved['mailbox']);
        $store = $this->store()->search($identity, $folder, $query, $limit);

        // A store failure does not empty the page: the indexed hits still show,
        // with a note saying the full-text side is unavailable.
        Http::json(200, [
            'data' => [
                'indexed' => $indexed,
                'store'   => $store['ok'] ? $store['threads'] : [],
            ],
            'meta' => [
                'query'           => $query,
                'folder'          => $folder,
                'store_available' => $store['ok'],
                'store_note'      => $store['ok'] ? null : $store['error'],
                'index_note'      => 'Indexed results cover subjects and senders only. Full-text search reads the mail store.',
                'fetched_at'      => gmdate('c'),
            ],
        ]);
    }
}
