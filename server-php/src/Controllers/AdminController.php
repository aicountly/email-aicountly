<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Db;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\MailboxAccess;

/**
 * Administration: shared mailboxes, delegation, and custom-domain onboarding.
 *
 * Business only, and the check is `Account::can('administration')` — the
 * entitlement, never the hostname. Somebody who opens the business frontend
 * without the entitlement gets a 403 from every route here, which is exactly
 * what should happen when a UI is bypassed.
 *
 * DOMAIN ONBOARDING NEVER TOUCHES DNS. Email generates a verification token,
 * reads the domain's records to report what is actually there, and refuses to
 * provision a mailbox until ownership verifies. It does not create, change or
 * delete a record, and it does not touch MX — deploying a frontend is not a
 * reason to re-route somebody's mail.
 */
final class AdminController extends Controller
{
    public function mailboxes(): void
    {
        $this->requireBusiness('administration');
        $ctx = $this->requireContext();

        $rows = Db::all(
            'SELECT m.mailbox_id, m.address, m.display_name, m.kind, m.status, m.cmp_id, m.quota_bytes,
                    (m.credential_ciphertext IS NOT NULL) AS has_credential,
                    (SELECT COUNT(*) FROM email_mailbox_members mm WHERE mm.mailbox_id = m.mailbox_id) AS member_count
               FROM email_mailboxes m
              WHERE m.cmp_id = :cmp AND m.status <> :deleted
              ORDER BY m.address',
            ['cmp' => $ctx->cmpId, 'deleted' => 'deleted'],
        );

        Http::data($rows);
    }

    /** Grant or change somebody's access to a shared mailbox. */
    public function grant(string $mailboxId): void
    {
        $this->requireBusiness('delegation');
        $resolved = $this->mailbox($mailboxId, MailboxAccess::MANAGE);

        $memberUuid = trim((string) (Http::param('member_uuid') ?? ''));
        if ($memberUuid === '') {
            Http::validationFailed('The person to grant access to is required.', ['field' => 'member_uuid']);
        }

        $role = (string) (Http::param('role') ?? 'member');
        if (!in_array($role, ['owner', 'delegate', 'member'], true)) {
            Http::validationFailed('Unknown role.', ['field' => 'role', 'allowed' => ['owner', 'delegate', 'member']]);
        }

        $requested = array_values(array_intersect(
            array_map('strval', Http::arrayParam('permissions')),
            [MailboxAccess::READ, MailboxAccess::SEND_AS, MailboxAccess::SEND_ON_BEHALF, MailboxAccess::MANAGE],
        ));
        if ($requested === []) {
            $requested = [MailboxAccess::READ];
        }

        Db::run(
            'INSERT INTO email_mailbox_members (mailbox_id, member_uuid, role, permissions, granted_by, created_at)
             VALUES (:mailbox, :member, :role, :permissions, :by, :now)
             ON CONFLICT (mailbox_id, member_uuid) DO UPDATE
                SET role = EXCLUDED.role, permissions = EXCLUDED.permissions, granted_by = EXCLUDED.granted_by',
            [
                'mailbox' => (int) $resolved['mailbox']['mailbox_id'],
                'member'  => $memberUuid,
                'role'    => $role,
                'permissions' => json_encode($requested),
                'by'      => $this->auth()->uuid,
                'now'     => gmdate('Y-m-d H:i:s'),
            ],
        );

        Audit::record($this->auth(), $this->account(), 'mailbox.access_granted', 'mailbox', (int) $resolved['mailbox']['mailbox_id'], 'ok', [
            'member' => $memberUuid, 'role' => $role, 'permissions' => $requested,
        ]);

        Http::data(['mailbox_id' => (int) $resolved['mailbox']['mailbox_id'], 'member_uuid' => $memberUuid, 'role' => $role, 'permissions' => $requested]);
    }

    public function revoke(string $mailboxId, string $memberUuid): void
    {
        $this->requireBusiness('delegation');
        $resolved = $this->mailbox($mailboxId, MailboxAccess::MANAGE);

        // Removing the last manager would leave a shared mailbox nobody can
        // administer, which needs a support ticket to undo.
        $managers = (int) (Db::scalar(
            "SELECT COUNT(*) FROM email_mailbox_members
              WHERE mailbox_id = :mailbox AND (role = 'owner' OR permissions @> '[\"manage\"]'::jsonb)",
            ['mailbox' => (int) $resolved['mailbox']['mailbox_id']],
        ) ?? 0);

        $target = Db::first(
            'SELECT role, permissions FROM email_mailbox_members WHERE mailbox_id = :mailbox AND member_uuid = :member',
            ['mailbox' => (int) $resolved['mailbox']['mailbox_id'], 'member' => $memberUuid],
        );
        if ($target === null) {
            Http::notFound('That person does not have access to this mailbox.');
        }

        $targetManages = $target['role'] === 'owner' || in_array(MailboxAccess::MANAGE, Db::jsonColumn($target['permissions']), true);
        if ($targetManages && $managers <= 1) {
            Http::conflict('This is the only person who can administer that mailbox. Grant somebody else manage access first.', [
                'retryable' => false,
            ]);
        }

        Db::run('DELETE FROM email_mailbox_members WHERE mailbox_id = :mailbox AND member_uuid = :member',
            ['mailbox' => (int) $resolved['mailbox']['mailbox_id'], 'member' => $memberUuid]);

        Audit::record($this->auth(), $this->account(), 'mailbox.access_revoked', 'mailbox', (int) $resolved['mailbox']['mailbox_id'], 'ok', ['member' => $memberUuid]);
        Http::data(['revoked' => true, 'member_uuid' => $memberUuid]);
    }

    public function domains(): void
    {
        $this->requireBusiness('custom_domains');
        $ctx = $this->requireContext();

        $rows = Db::all(
            'SELECT domain_id, domain, ownership_state, spf_state, dkim_state, dmarc_state, mx_state,
                    verification_token, last_checked_at, created_at
               FROM email_domains WHERE cmp_id = :cmp ORDER BY domain',
            ['cmp' => $ctx->cmpId],
        );

        Http::json(200, [
            'data' => $rows,
            'meta' => [
                'instructions' => 'Add the TXT record shown for each domain at your DNS provider. Email reads DNS to report what is there; it never creates, changes or deletes a record, and it never touches MX.',
            ],
        ]);
    }

    public function addDomain(): void
    {
        $this->requireBusiness('custom_domains');
        $ctx = $this->requireContext();
        $account = $this->account();

        $domain = strtolower(trim((string) (Http::param('domain') ?? '')));
        if (preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain) !== 1) {
            Http::validationFailed('That does not look like a domain name.', ['field' => 'domain']);
        }

        $token = 'aicountly-email-verification=' . bin2hex(random_bytes(16));

        Db::run(
            'INSERT INTO email_domains (account_id, cmp_id, domain, verification_token, ownership_state, created_at)
             VALUES (:account, :cmp, :domain, :token, :pending, :now)
             ON CONFLICT (domain) DO NOTHING',
            [
                'account' => $account->accountId, 'cmp' => $ctx->cmpId, 'domain' => $domain,
                'token' => $token, 'pending' => 'pending', 'now' => gmdate('Y-m-d H:i:s'),
            ],
        );

        $row = Db::first('SELECT * FROM email_domains WHERE domain = :domain', ['domain' => $domain]);
        if ($row === null || (int) $row['cmp_id'] !== $ctx->cmpId) {
            Http::conflict('That domain is already registered to another company.', ['retryable' => false]);
        }

        Audit::record($this->auth(), $account, 'domain.added', 'domain', $domain, 'ok', [], $ctx->cmpId);
        Http::data($this->describeDomain($row), 201);
    }

    /**
     * Read DNS and report what is there.
     *
     * READ ONLY. Every state below is what a resolver answered at the moment
     * this ran, and `unknown` is a real answer — it means the lookup did not
     * come back, not that the record is missing.
     */
    public function verifyDomain(string $domainId): void
    {
        $this->requireBusiness('custom_domains');
        $ctx = $this->requireContext();

        $row = Db::first('SELECT * FROM email_domains WHERE domain_id = :id AND cmp_id = :cmp',
            ['id' => (int) $domainId, 'cmp' => $ctx->cmpId]);
        if ($row === null) {
            Http::notFound('That domain is not registered to this company.');
        }

        $domain = (string) $row['domain'];
        $txt = self::txtRecords($domain);

        $ownership = in_array((string) $row['verification_token'], $txt, true) ? 'verified' : 'failed';
        $spf = self::recordState($txt, 'v=spf1');
        $dmarc = self::recordState(self::txtRecords('_dmarc.' . $domain), 'v=DMARC1');
        $dkimSelector = Env::get('MAIL_DKIM_SELECTOR', 'default');
        $dkim = self::recordState(self::txtRecords($dkimSelector . '._domainkey.' . $domain), 'v=DKIM1');
        $mx = self::mxState($domain);

        Db::update('email_domains', [
            'ownership_state' => $ownership,
            'spf_state'       => $spf,
            'dkim_state'      => $dkim,
            'dmarc_state'     => $dmarc,
            'mx_state'        => $mx,
            'last_checked_at' => gmdate('Y-m-d H:i:s'),
        ], ['domain_id' => (int) $domainId]);

        Audit::record($this->auth(), $this->account(), 'domain.verified', 'domain', $domain, $ownership, [
            'spf' => $spf, 'dkim' => $dkim, 'dmarc' => $dmarc, 'mx' => $mx,
        ], $ctx->cmpId);

        $fresh = Db::first('SELECT * FROM email_domains WHERE domain_id = :id', ['id' => (int) $domainId]) ?? $row;
        Http::data($this->describeDomain($fresh));
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function describeDomain(array $row): array
    {
        return [
            'domain_id'        => (int) $row['domain_id'],
            'domain'           => $row['domain'],
            'ownership_state'  => $row['ownership_state'],
            'spf_state'        => $row['spf_state'],
            'dkim_state'       => $row['dkim_state'],
            'dmarc_state'      => $row['dmarc_state'],
            'mx_state'         => $row['mx_state'],
            'last_checked_at'  => $row['last_checked_at'],
            'required_records' => [
                ['type' => 'TXT', 'host' => (string) $row['domain'], 'value' => (string) $row['verification_token'],
                 'purpose' => 'Proves you control this domain. Email will not provision a mailbox on it until this resolves.'],
            ],
            'provisioning_allowed' => $row['ownership_state'] === 'verified',
            'note' => 'Email reads these records. It never writes them, and it never changes MX — mail routing stays where you point it.',
        ];
    }

    /** @return list<string> */
    private static function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if ($records === false) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }

    /** @param list<string> $records */
    private static function recordState(array $records, string $marker): string
    {
        if ($records === []) {
            return 'missing';
        }
        foreach ($records as $record) {
            if (stripos($record, $marker) === 0) {
                return 'present';
            }
        }

        return 'missing';
    }

    private static function mxState(string $domain): string
    {
        $records = @dns_get_record($domain, DNS_MX);
        if ($records === false) {
            return 'unknown';
        }

        return $records === [] ? 'missing' : 'present';
    }
}
