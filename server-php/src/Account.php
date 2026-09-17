<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The Email-owned record of an account, and what that account is entitled to.
 *
 * THIS — not the hostname — decides what a signed-in user may do. A business
 * user who opens aicountly.io keeps `account_type = business` and keeps their
 * shared mailboxes; a personal user who opens email.aicountly.com is still
 * `personal` and gains nothing. The frontend reads this through
 * `GET /api/v1/capabilities` and uses it to decide what to render; the backend
 * reads it again on every write, because a UI decision is not a permission.
 *
 * Email owns this table. It holds no company master, no contact, no calendar
 * event and no accounting figure — only which mailboxes exist, who may open
 * them, and which Email features this account has.
 */
final class Account
{
    public const TABLE = 'email_accounts';

    public const TYPE_BUSINESS = 'business';
    public const TYPE_PERSONAL = 'personal';

    private function __construct(
        public readonly int $accountId,
        public readonly string $subjectUuid,
        /** 'business' | 'personal' */
        public readonly string $accountType,
        public readonly string $status,
        public readonly string $planKey,
        public readonly int $storageQuotaBytes,
        public readonly bool $aiOptOut,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['account_id'],
            (string) $row['subject_uuid'],
            (string) $row['account_type'] === self::TYPE_BUSINESS ? self::TYPE_BUSINESS : self::TYPE_PERSONAL,
            (string) ($row['status'] ?? 'active'),
            (string) ($row['plan_key'] ?? 'free'),
            (int) ($row['storage_quota_bytes'] ?? 0),
            (bool) ($row['ai_opt_out'] ?? false),
        );
    }

    public static function find(string $subjectUuid): ?self
    {
        if ($subjectUuid === '') {
            return null;
        }
        $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE subject_uuid = :uuid', ['uuid' => $subjectUuid]);

        return $row === null ? null : self::fromRow($row);
    }

    /**
     * The account for this caller, created on first sight if Email is allowed
     * to provision one.
     *
     * Creating the ROW is always safe — it is Email's own bookkeeping and holds
     * nothing but a reference to a portal identity. Creating a MAILBOX is not:
     * that needs real mail infrastructure, so it happens in
     * Mail\MailboxProvisioner and reports honestly when the adapter is not
     * configured. An account with no mailbox is a normal, visible state.
     *
     * The default type is `personal`. A business entitlement is granted by an
     * administrator or by the business signup flow, never inferred from the
     * hostname the browser used and never from the address suffix.
     */
    public static function ensure(Auth $auth): self
    {
        $existing = self::find($auth->uuid);
        if ($existing !== null) {
            return $existing;
        }

        if (Env::get('EMAIL_AUTO_PROVISION_ACCOUNTS', '1') !== '1') {
            Http::error(403, 'account_not_provisioned', 'This AICOUNTLY account has no Email account yet. Ask an administrator to enable Email for you.');
        }

        Db::insert(self::TABLE, [
            'subject_uuid'        => $auth->uuid,
            'account_type'        => self::TYPE_PERSONAL,
            'status'              => 'active',
            'plan_key'            => Env::get('EMAIL_DEFAULT_PLAN', 'free'),
            'storage_quota_bytes' => (int) Env::get('EMAIL_DEFAULT_QUOTA_BYTES', '2147483648'),
            'ai_opt_out'          => false,
            'created_at'          => gmdate('Y-m-d H:i:s'),
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ], 'account_id');

        $created = self::find($auth->uuid);
        if ($created === null) {
            Http::error(500, 'account_not_created', 'Could not open an Email account for this user.');
        }

        return $created;
    }

    public function isBusiness(): bool
    {
        return $this->accountType === self::TYPE_BUSINESS;
    }

    /**
     * Whether this account may use a named Email feature.
     *
     * Business-only features are business-only wherever the browser is. AI is
     * additionally gated on the account not having opted out and on a model
     * actually being configured — checked by the caller, because "the user said
     * no" and "nobody set it up" are different states and the UI says so.
     */
    public function can(string $feature): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        return match ($feature) {
            'shared_mailboxes', 'delegation', 'team_assignment', 'business_comparison',
            'product_actions', 'custom_domains', 'administration' => $this->isBusiness(),
            'ai'                                                  => !$this->aiOptOut,
            default                                               => true,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'account_id'          => $this->accountId,
            'account_type'        => $this->accountType,
            'status'              => $this->status,
            'plan_key'            => $this->planKey,
            'storage_quota_bytes' => $this->storageQuotaBytes,
            'ai_opt_out'          => $this->aiOptOut,
        ];
    }
}
