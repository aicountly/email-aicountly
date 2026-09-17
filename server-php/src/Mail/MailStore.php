<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * The authoritative home of message bodies and attachments.
 *
 * THIS IS THE DECISION, WRITTEN DOWN: the mail store — IMAP today, JMAP or a
 * provider API behind the same interface tomorrow — holds every message body
 * and every original attachment. Email's own PostgreSQL database holds
 * REFERENCES to them (`email_message_refs`) plus the small amount of derived
 * text the list, the search index and the AI summaries need. There is no second
 * copy of a body and no second copy of an attachment, because two copies is two
 * answers to "what did they actually write".
 *
 * Every method takes a MailboxIdentity, and every caller has already been
 * through MailboxAccess. This interface performs NO authorisation of its own —
 * it is the wrong layer for it, and pretending otherwise is how a check gets
 * skipped on the one route that forgot.
 */
interface MailStore
{
    public function isConfigured(): bool;

    /** @return array<string, mixed> configured / driver / host — never a credential */
    public function describe(): array;

    /** @return array{ok:bool, folders:list<array<string,mixed>>, error:?string} */
    public function folders(MailboxIdentity $identity): array;

    /**
     * One page of threads, newest first.
     *
     * Cursor, not offset: a mailbox changes under the reader, and offset paging
     * shows some messages twice and skips others as new mail arrives.
     *
     * @param array<string, mixed> $filters
     * @return array{ok:bool, threads:list<array<string,mixed>>, next_cursor:?string, error:?string}
     */
    public function threads(MailboxIdentity $identity, string $folder, ?string $cursor, int $limit, array $filters = []): array;

    /** @return array{ok:bool, messages:list<array<string,mixed>>, error:?string} */
    public function thread(MailboxIdentity $identity, string $folder, string $threadKey): array;

    /** @return array{ok:bool, message:?array<string,mixed>, error:?string} */
    public function message(MailboxIdentity $identity, string $folder, string $uid): array;

    /** @return array{ok:bool, filename:?string, mime_type:?string, bytes:?string, size:?int, error:?string} */
    public function attachment(MailboxIdentity $identity, string $folder, string $uid, string $partId): array;

    /**
     * @param list<string> $uids
     * @param list<string> $add    \Seen, \Flagged, …
     * @param list<string> $remove
     * @return array{ok:bool, updated:int, error:?string}
     */
    public function setFlags(MailboxIdentity $identity, string $folder, array $uids, array $add, array $remove): array;

    /**
     * @param list<string> $uids
     * @return array{ok:bool, moved:int, error:?string}
     */
    public function move(MailboxIdentity $identity, string $folder, array $uids, string $targetFolder): array;

    /** @return array{ok:bool, threads:list<array<string,mixed>>, error:?string} */
    public function search(MailboxIdentity $identity, string $folder, string $query, int $limit): array;

    /** Write a copy into a folder — the Sent copy after a successful submission. @return array{ok:bool, uid:?string, error:?string} */
    public function append(MailboxIdentity $identity, string $folder, string $rawMessage, array $flags = []): array;

    /** @return array{ok:bool, used_bytes:?int, quota_bytes:?int, error:?string} */
    public function quota(MailboxIdentity $identity): array;
}
