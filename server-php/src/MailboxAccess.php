<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * May this caller open this mailbox, and to do what?
 *
 * EVERY mailbox-scoped route goes through here, and it is the only place that
 * answers the question. An id in a URL is a claim: `/threads/{id}` belonging to
 * someone else's mailbox is a 404, and `/mailboxes/{id}/threads` for a mailbox
 * the caller is not a member of is a 403. Neither is ever "an empty list",
 * which would leak the difference between "nothing here" and "not yours".
 *
 * Grants are rows in `email_mailbox_members`, which Email owns. The portal says
 * WHO the caller is; Manage says which COMPANY they may open; this table says
 * which MAILBOX. Three different questions, three different owners.
 */
final class MailboxAccess
{
    public const MEMBERS_TABLE  = 'email_mailbox_members';
    public const MAILBOX_TABLE  = 'email_mailboxes';

    public const READ        = 'read';
    public const SEND_AS     = 'send_as';
    public const SEND_ON_BEHALF = 'send_on_behalf';
    public const MANAGE      = 'manage';

    /** @var array<string, array<string, mixed>|null> */
    private static array $memo = [];

    /**
     * The mailbox row plus this caller's grant, or a 403/404.
     *
     * @return array{mailbox: array<string, mixed>, grant: array<string, mixed>}
     */
    public static function require(Auth $auth, Account $account, int $mailboxId, string $permission = self::READ): array
    {
        $resolved = self::resolve($auth, $account, $mailboxId);

        if ($resolved === null) {
            // Deliberately 404, not 403: a 403 on an id the caller cannot see
            // confirms the id exists, which is an enumeration oracle.
            Http::notFound('That mailbox does not exist, or you do not have access to it.');
        }

        if (!self::granted($resolved['grant'], $permission)) {
            Http::forbidden('You do not have ' . str_replace('_', ' ', $permission) . ' permission on this mailbox.');
        }

        return $resolved;
    }

    /**
     * The same check without the throw, for capability reporting.
     *
     * @return array{mailbox: array<string, mixed>, grant: array<string, mixed>}|null
     */
    public static function resolve(Auth $auth, Account $account, int $mailboxId): ?array
    {
        $key = $account->accountId . ':' . $mailboxId . ':' . $auth->fingerprint();
        if (array_key_exists($key, self::$memo)) {
            /** @var array{mailbox: array<string, mixed>, grant: array<string, mixed>}|null */
            return self::$memo[$key];
        }

        $mailbox = Db::first(
            'SELECT * FROM ' . self::MAILBOX_TABLE . ' WHERE mailbox_id = :id AND status <> :deleted',
            ['id' => $mailboxId, 'deleted' => 'deleted'],
        );
        if ($mailbox === null) {
            return self::$memo[$key] = null;
        }

        $grant = Db::first(
            'SELECT * FROM ' . self::MEMBERS_TABLE . ' WHERE mailbox_id = :id AND member_uuid = :uuid',
            ['id' => $mailboxId, 'uuid' => $auth->uuid],
        );

        // The owner of a mailbox always has full rights on it, whether or not a
        // membership row was ever written.
        if ($grant === null && (int) $mailbox['account_id'] === $account->accountId) {
            $grant = [
                'mailbox_id'  => $mailboxId,
                'member_uuid' => $auth->uuid,
                'role'        => 'owner',
                'permissions' => [self::READ, self::SEND_AS, self::MANAGE],
            ];
        }

        if ($grant === null) {
            return self::$memo[$key] = null;
        }

        // A shared mailbox belongs to a company, and company access is Manage's
        // answer, not ours. A membership row alone is not enough once the user
        // has been removed from the company.
        $cmpId = (int) ($mailbox['cmp_id'] ?? 0);
        if ($cmpId > 0 && !$auth->isService()) {
            Context::forCompany($cmpId)->assertAllowed($auth);
        }

        $grant['permissions'] = self::permissionList($grant);

        return self::$memo[$key] = ['mailbox' => $mailbox, 'grant' => $grant];
    }

    /** @param array<string, mixed> $grant */
    public static function granted(array $grant, string $permission): bool
    {
        $permissions = self::permissionList($grant);

        if ($permission === self::SEND_ON_BEHALF && in_array(self::SEND_AS, $permissions, true)) {
            return true;
        }

        return in_array($permission, $permissions, true);
    }

    /**
     * Mailboxes this caller may open, owned and delegated.
     *
     * @return list<array<string, mixed>>
     */
    public static function listFor(Auth $auth, Account $account): array
    {
        // PDO with native prepares cannot reuse one named placeholder, so the
        // account id is bound three times under three names.
        return Db::all(
            'SELECT m.*, COALESCE(mm.role, CASE WHEN m.account_id = :account_role THEN :owner ELSE NULL END) AS member_role,
                    mm.permissions AS member_permissions
               FROM ' . self::MAILBOX_TABLE . ' m
               LEFT JOIN ' . self::MEMBERS_TABLE . ' mm
                      ON mm.mailbox_id = m.mailbox_id AND mm.member_uuid = :uuid
              WHERE m.status <> :deleted
                AND (m.account_id = :account_filter OR mm.member_uuid IS NOT NULL)
              ORDER BY (m.account_id = :account_sort) DESC, m.address',
            [
                'account_role'   => $account->accountId,
                'account_filter' => $account->accountId,
                'account_sort'   => $account->accountId,
                'uuid'           => $auth->uuid,
                'owner'          => 'owner',
                'deleted'        => 'deleted',
            ],
        );
    }

    /**
     * @param array<string, mixed> $grant
     * @return list<string>
     */
    private static function permissionList(array $grant): array
    {
        $role = (string) ($grant['role'] ?? '');
        if ($role === 'owner') {
            return [self::READ, self::SEND_AS, self::SEND_ON_BEHALF, self::MANAGE];
        }

        $raw = $grant['permissions'] ?? [];
        $list = is_array($raw) ? $raw : Db::jsonColumn($raw);

        $clean = [];
        foreach ($list as $permission) {
            if (in_array($permission, [self::READ, self::SEND_AS, self::SEND_ON_BEHALF, self::MANAGE], true)) {
                $clean[] = (string) $permission;
            }
        }

        // A membership row with no usable permissions still reads: that is what
        // being a member of a shared mailbox means at minimum.
        return $clean === [] ? [self::READ] : array_values(array_unique($clean));
    }

    /** CLI only: forget memoised decisions between test cases. */
    public static function resetForTest(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$memo = [];
    }
}
