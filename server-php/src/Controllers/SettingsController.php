<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\MailboxAccess;
use Aicountly\Api\RateLimit;

/**
 * Preferences, signatures and sender blocking.
 *
 * Everything here is Email's own. The AI opt-out lives here too, and it is a
 * real switch: with it set, no endpoint sends anything to a model, and the
 * screens say "you have turned Pulse AI off" rather than the unconfigured text,
 * because those are different situations.
 */
final class SettingsController extends Controller
{
    public function show(): void
    {
        $account = $this->account();

        $preferences = Db::first('SELECT * FROM email_preferences WHERE account_id = :id', ['id' => $account->accountId]);

        Http::data([
            'account'      => $account->toArray(),
            'timezone'     => $preferences['timezone'] ?? 'Asia/Kolkata',
            'undo_seconds' => (int) ($preferences['undo_seconds'] ?? 10),
            'density'      => $preferences['density'] ?? 'comfortable',
            'remote_images' => Db::scalar('SELECT remote_images FROM email_accounts WHERE account_id = :id', ['id' => $account->accountId]),
            'preferences'  => $preferences === null ? [] : Db::jsonColumn($preferences['preferences']),
            'usage'        => RateLimit::summary($account),
        ]);
    }

    public function update(): void
    {
        $account = $this->account();

        $timezone = (string) (Http::param('timezone') ?? 'Asia/Kolkata');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            Http::validationFailed('That is not a timezone this server knows.', ['field' => 'timezone']);
        }

        $undo = max(0, min(60, Http::intParam('undo_seconds', 10) ?? 10));
        $remoteImages = (string) (Http::param('remote_images') ?? 'ask');
        if (!in_array($remoteImages, ['ask', 'always', 'never'], true)) {
            Http::validationFailed('Unknown remote-image policy.', ['field' => 'remote_images', 'allowed' => ['ask', 'always', 'never']]);
        }

        Db::run(
            'INSERT INTO email_preferences (account_id, timezone, undo_seconds, density, preferences, updated_at)
             VALUES (:account, :tz, :undo, :density, :prefs, :now)
             ON CONFLICT (account_id) DO UPDATE
                SET timezone = EXCLUDED.timezone, undo_seconds = EXCLUDED.undo_seconds,
                    density = EXCLUDED.density, preferences = EXCLUDED.preferences, updated_at = EXCLUDED.updated_at',
            [
                'account' => $account->accountId,
                'tz'      => $timezone,
                'undo'    => $undo,
                'density' => (string) (Http::param('density') ?? 'comfortable'),
                'prefs'   => json_encode(is_array(Http::body()['preferences'] ?? null) ? Http::body()['preferences'] : []),
                'now'     => gmdate('Y-m-d H:i:s'),
            ],
        );

        $aiOptOut = Http::boolParam('ai_opt_out');
        Db::update('email_accounts', array_filter([
            'remote_images' => $remoteImages,
            'ai_opt_out'    => $aiOptOut,
            'updated_at'    => gmdate('Y-m-d H:i:s'),
        ], static fn ($v) => $v !== null), ['account_id' => $account->accountId]);

        Audit::record($this->auth(), $account, 'settings.updated', 'account', $account->accountId, 'ok', [
            'timezone' => $timezone, 'undo_seconds' => $undo, 'remote_images' => $remoteImages, 'ai_opt_out' => $aiOptOut,
        ]);

        $this->show();
    }

    public function signatures(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);

        Http::data(Db::all(
            'SELECT signature_id, name, body_text, body_html, is_default FROM email_signatures WHERE mailbox_id = :id ORDER BY is_default DESC, name',
            ['id' => (int) $resolved['mailbox']['mailbox_id']],
        ));
    }

    public function saveSignature(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::MANAGE);
        $mailbox = (int) $resolved['mailbox']['mailbox_id'];

        $signatureId = Http::intParam('signature_id');
        $values = [
            'name'       => mb_substr((string) (Http::param('name') ?? 'Default'), 0, 120),
            'body_text'  => mb_substr((string) (Http::param('body_text') ?? ''), 0, 8000),
            'body_html'  => mb_substr((string) (Http::param('body_html') ?? ''), 0, 16000),
            'is_default' => Http::boolParam('is_default', false),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($signatureId !== null) {
            $owned = Db::first('SELECT signature_id FROM email_signatures WHERE signature_id = :id AND mailbox_id = :mailbox',
                ['id' => $signatureId, 'mailbox' => $mailbox]);
            if ($owned === null) {
                Http::notFound('That signature does not exist in this mailbox.');
            }
            Db::update('email_signatures', $values, ['signature_id' => $signatureId]);
        } else {
            $signatureId = (int) Db::insert('email_signatures', $values + ['mailbox_id' => $mailbox, 'created_at' => gmdate('Y-m-d H:i:s')], 'signature_id');
        }

        if ($values['is_default'] === true) {
            Db::run('UPDATE email_signatures SET is_default = FALSE WHERE mailbox_id = :mailbox AND signature_id <> :keep',
                ['mailbox' => $mailbox, 'keep' => $signatureId]);
        }

        $this->signatures($mailboxId);
    }

    public function blocklist(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);

        Http::data(Db::all(
            'SELECT block_id, pattern, scope, created_at FROM email_blocklist WHERE mailbox_id = :id ORDER BY created_at DESC',
            ['id' => (int) $resolved['mailbox']['mailbox_id']],
        ));
    }

    public function block(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::MANAGE);

        $pattern = strtolower(trim((string) (Http::param('pattern') ?? '')));
        $scope = (string) (Http::param('scope') ?? 'sender');

        if ($pattern === '' || !in_array($scope, ['sender', 'domain'], true)) {
            Http::validationFailed('A sender address or domain is required.', ['fields' => ['pattern', 'scope']]);
        }
        // A blocklist entry becomes a filter rule; a wildcard pattern in it is
        // how somebody blocks their own mail by accident.
        if (preg_match('/^[a-z0-9._%+\-]+(@[a-z0-9.\-]+\.[a-z]{2,})?$/', $pattern) !== 1) {
            Http::validationFailed('That does not look like an address or a domain.', ['field' => 'pattern']);
        }

        Db::run(
            'INSERT INTO email_blocklist (mailbox_id, pattern, scope, created_by, created_at)
             VALUES (:mailbox, :pattern, :scope, :by, :now)
             ON CONFLICT (mailbox_id, scope, pattern) DO NOTHING',
            [
                'mailbox' => (int) $resolved['mailbox']['mailbox_id'],
                'pattern' => $pattern, 'scope' => $scope,
                'by' => $this->auth()->uuid, 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        Audit::record($this->auth(), $this->account(), 'blocklist.added', 'blocklist', $pattern, 'ok', ['scope' => $scope]);
        $this->blocklist($mailboxId);
    }

    public function unblock(string $mailboxId, string $blockId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::MANAGE);

        Db::run('DELETE FROM email_blocklist WHERE block_id = :id AND mailbox_id = :mailbox',
            ['id' => (int) $blockId, 'mailbox' => (int) $resolved['mailbox']['mailbox_id']]);

        $this->blocklist($mailboxId);
    }
}
