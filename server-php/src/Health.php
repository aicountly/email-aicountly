<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Mail\MailAdapters;

/**
 * Readiness, not just liveness.
 *
 * `status: ok` stays true whenever PHP is serving, so an uptime monitor pointed
 * at /health keeps behaving as it always has. `usable` is the field that says
 * whether the product can actually be used — a deploy that goes green on an app
 * whose every real endpoint answers 503 is the most confusing possible outcome.
 *
 * Nothing here reveals a credential: each subsystem reports configured /
 * reachable and, where it failed, a category — never the connection string, the
 * host, or the error text a driver produced.
 */
final class Health
{
    /** Tables that must exist before the API can serve anything. */
    private const REQUIRED_TABLES = [
        'email_accounts',
        'email_mailboxes',
        'email_mailbox_members',
        'email_message_refs',
        'email_drafts',
        'email_send_jobs',
        'email_audit_log',
    ];

    /** @return array<string, mixed> */
    public static function database(): array
    {
        if (Env::get('DB_NAME') === '' || Env::get('DB_USER') === '') {
            return ['configured' => false, 'reachable' => false, 'schema' => ['ready' => false, 'missing' => self::REQUIRED_TABLES]];
        }

        try {
            Db::scalar('SELECT 1');
        } catch (\Throwable $e) {
            error_log('[email-health] database unreachable: ' . $e->getMessage());

            return ['configured' => true, 'reachable' => false, 'schema' => ['ready' => false, 'missing' => null]];
        }

        try {
            $present = Db::all(
                'SELECT table_name FROM information_schema.tables WHERE table_name = ANY(:names)',
                ['names' => '{' . implode(',', self::REQUIRED_TABLES) . '}'],
            );
            $found = array_map(static fn (array $row) => (string) $row['table_name'], $present);
            $missing = array_values(array_diff(self::REQUIRED_TABLES, $found));
        } catch (\Throwable $e) {
            error_log('[email-health] schema probe failed: ' . $e->getMessage());

            return ['configured' => true, 'reachable' => true, 'schema' => ['ready' => false, 'missing' => null]];
        }

        return [
            'configured' => true,
            'reachable'  => true,
            'schema'     => ['ready' => $missing === [], 'missing' => $missing],
        ];
    }

    /** @return array<string, mixed> */
    public static function report(): array
    {
        $database = self::database();
        $mail = MailAdapters::status();

        return [
            'status'   => 'ok',
            'app'      => 'Email',
            'env'      => Env::get('APP_ENV', 'unknown'),
            'time'     => gmdate('c'),
            'database' => $database,
            'mail'     => $mail,
            'ai'       => ['configured' => AiClient::isConfigured()],
            // One field to read when something is wrong. Mail is part of it:
            // an Email API that cannot reach a mail store is up, not usable.
            'usable'   => $database['reachable']
                && ($database['schema']['ready'] ?? false)
                && ($mail['store']['configured'] ?? false),
        ];
    }
}
