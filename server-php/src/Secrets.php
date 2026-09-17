<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Envelope encryption for the few secrets Email must store.
 *
 * Exactly one kind of secret lands in this database: the IMAP/SMTP credential
 * for a mailbox, when the deployment authenticates per mailbox rather than
 * through a master user. Storing that in plaintext would mean a read-only SQL
 * injection anywhere in the product hands over everyone's mailbox.
 *
 * AES-256-GCM, key from `MAIL_CREDENTIAL_KEY` (base64, 32 bytes) in the server
 * .env — never in the repository, never in a VITE_* variable, never returned by
 * an endpoint. With no key configured, encryption REFUSES rather than falling
 * back to plaintext: a deployment that has not set it simply cannot store
 * per-mailbox credentials, which is the safe failure.
 */
final class Secrets
{
    private const PREFIX = 'v1.gcm.';

    public static function isConfigured(): bool
    {
        return self::key() !== null;
    }

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        if ($key === null) {
            throw new \RuntimeException('MAIL_CREDENTIAL_KEY is not configured; refusing to store a credential in plaintext.');
        }

        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new \RuntimeException('Could not encrypt the credential.');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /** Null when the value cannot be decrypted — a rotated key, or a corrupt row. */
    public static function decrypt(string $stored): ?string
    {
        $key = self::key();
        if ($key === null || !str_starts_with($stored, self::PREFIX)) {
            return null;
        }

        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    private static function key(): ?string
    {
        $configured = Env::get('MAIL_CREDENTIAL_KEY');
        if ($configured === '' || str_starts_with($configured, 'CHANGE_ME')) {
            return null;
        }

        $decoded = base64_decode($configured, true);

        return ($decoded !== false && strlen($decoded) === 32) ? $decoded : null;
    }
}
