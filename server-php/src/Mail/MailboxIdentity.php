<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Secrets;

/**
 * How Email authenticates to the mail store for one mailbox.
 *
 * Two shapes, because real deployments come in both:
 *
 *   MASTER USER   `MAIL_IMAP_MASTER_USER` / `MAIL_IMAP_MASTER_PASSWORD`. Dovecot
 *                 and most hosted providers accept `user*master` as the login,
 *                 so Email holds one credential instead of one per mailbox.
 *                 This is the preferred shape: fewer secrets, and rotating one
 *                 rotates everything.
 *
 *   PER MAILBOX   an encrypted credential on the mailbox row, for a provider
 *                 with no master concept. Encrypted with MAIL_CREDENTIAL_KEY;
 *                 see Secrets.
 *
 * The credential never leaves the backend, is never logged, and is never part
 * of any API response — including the administration screens, which show only
 * whether a credential is present.
 */
final class MailboxIdentity
{
    private function __construct(
        public readonly int $mailboxId,
        public readonly string $address,
        public readonly string $login,
        private readonly string $password,
        public readonly string $mode,
    ) {
    }

    /** @param array<string, mixed> $mailbox a row from email_mailboxes */
    public static function forMailbox(array $mailbox): ?self
    {
        $address = (string) ($mailbox['address'] ?? '');
        if ($address === '') {
            return null;
        }
        $mailboxId = (int) ($mailbox['mailbox_id'] ?? 0);

        $masterUser = Env::get('MAIL_IMAP_MASTER_USER');
        $masterPass = Env::get('MAIL_IMAP_MASTER_PASSWORD');
        if ($masterUser !== '' && $masterPass !== '') {
            $separator = Env::get('MAIL_IMAP_MASTER_SEPARATOR', '*');

            return new self($mailboxId, $address, $address . $separator . $masterUser, $masterPass, 'master');
        }

        $stored = (string) ($mailbox['credential_ciphertext'] ?? '');
        if ($stored === '') {
            return null;
        }
        $password = Secrets::decrypt($stored);
        if ($password === null) {
            return null;
        }

        return new self($mailboxId, $address, (string) ($mailbox['credential_login'] ?? $address), $password, 'per-mailbox');
    }

    public static function forMailboxId(int $mailboxId): ?self
    {
        $row = Db::first('SELECT * FROM email_mailboxes WHERE mailbox_id = :id', ['id' => $mailboxId]);

        return $row === null ? null : self::forMailbox($row);
    }

    /** Only the transport layer calls this. Nothing else in the codebase may. */
    public function password(): string
    {
        return $this->password;
    }

    /** Safe to log and to return: says how the mailbox authenticates, never with what. */
    public function describe(): array
    {
        return ['mailbox_id' => $this->mailboxId, 'address' => $this->address, 'auth_mode' => $this->mode];
    }
}
