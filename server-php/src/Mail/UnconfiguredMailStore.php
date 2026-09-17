<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * What Email uses when no mail store has been set up.
 *
 * Every call returns `ok: false` with the same honest reason, and the API turns
 * that into a 503 `not_configured` that the UI renders as "Mailbox unavailable
 * — no mail store is configured for this deployment", with the setting an
 * administrator needs.
 *
 * It returns NO messages. Not an empty inbox, which would read as "you have no
 * mail" — a wrong statement, and the exact kind of pretend backend this file
 * exists to avoid.
 */
final class UnconfiguredMailStore implements MailStore
{
    public function __construct(private readonly string $reason) {}

    public function isConfigured(): bool
    {
        return false;
    }

    public function describe(): array
    {
        return [
            'configured' => false,
            'driver'     => 'none',
            'reason'     => $this->reason,
            'admin_hint' => 'Set MAIL_IMAP_HOST (and either MAIL_IMAP_MASTER_USER/MAIL_IMAP_MASTER_PASSWORD or per-mailbox credentials) in api/.env.',
        ];
    }

    /** @return array{ok:false, error:string} */
    private function refuse(): array
    {
        return ['ok' => false, 'error' => $this->reason];
    }

    public function folders(MailboxIdentity $identity): array
    {
        return $this->refuse() + ['folders' => []];
    }

    public function threads(MailboxIdentity $identity, string $folder, ?string $cursor, int $limit, array $filters = []): array
    {
        return $this->refuse() + ['threads' => [], 'next_cursor' => null];
    }

    public function thread(MailboxIdentity $identity, string $folder, string $threadKey): array
    {
        return $this->refuse() + ['messages' => []];
    }

    public function message(MailboxIdentity $identity, string $folder, string $uid): array
    {
        return $this->refuse() + ['message' => null];
    }

    public function attachment(MailboxIdentity $identity, string $folder, string $uid, string $partId): array
    {
        return $this->refuse() + ['filename' => null, 'mime_type' => null, 'bytes' => null, 'size' => null];
    }

    public function setFlags(MailboxIdentity $identity, string $folder, array $uids, array $add, array $remove): array
    {
        return $this->refuse() + ['updated' => 0];
    }

    public function move(MailboxIdentity $identity, string $folder, array $uids, string $targetFolder): array
    {
        return $this->refuse() + ['moved' => 0];
    }

    public function search(MailboxIdentity $identity, string $folder, string $query, int $limit): array
    {
        return $this->refuse() + ['threads' => []];
    }

    public function append(MailboxIdentity $identity, string $folder, string $rawMessage, array $flags = []): array
    {
        return $this->refuse() + ['uid' => null];
    }

    public function quota(MailboxIdentity $identity): array
    {
        return $this->refuse() + ['used_bytes' => null, 'quota_bytes' => null];
    }
}
