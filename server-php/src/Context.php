<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Clients\ManageClient;

/**
 * The company / branch / financial year a business request carries.
 *
 * Three ids and nothing else. The names and dates behind them belong to Manage
 * and are read from Manage at the point of use. Email stores `cmp_id` on a
 * shared mailbox and on an audit row; it stores no company name, no branch
 * master and no financial-year dates.
 *
 * OPTIONAL, unlike the rest of the fleet. A personal account has no company at
 * all, and requiring one would mean asking a personal user to create a
 * business. `optional()` returns null for them; `require()` is used only by the
 * routes that genuinely need a company (shared mailboxes, business comparisons,
 * product actions).
 *
 * TENANT ISOLATION: `cmp_id` arriving in a query string is a claim, not a fact.
 * assertAllowed() checks it against what Manage says this session may open, and
 * a company the session has no access to is a 403 — never a query that simply
 * returns nothing, which would leak the difference between "no rows" and "not
 * yours" and would break the moment a query forgot its WHERE clause.
 */
final class Context
{
    /** @var array<string, bool> */
    private static array $verified = [];

    private function __construct(
        public readonly int $cmpId,
        public readonly int $fyId,
        /** 0 = consolidated, all branches. */
        public readonly int $boId,
    ) {
    }

    /**
     * A scope for one company, for a check that needs no financial year.
     *
     * The shared-mailbox check is the caller: a shared mailbox belongs to a
     * company, and whether this session may open that company is Manage's
     * answer whichever screen is asking.
     */
    public static function forCompany(int $cmpId): self
    {
        return new self($cmpId, 0, 0);
    }

    public static function forTest(int $cmpId, int $fyId = 1, int $boId = 0): self
    {
        return new self($cmpId, $fyId, $boId);
    }

    /** The scope this request carries, or null when it carries none. */
    public static function optional(): ?self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        if ($cmpId <= 0) {
            return null;
        }

        return new self($cmpId, max(0, Http::intParam('fy_id', 0) ?? 0), max(0, Http::intParam('bo_id', 0) ?? 0));
    }

    /** Read the scope out of the request, refusing anything incomplete. */
    public static function require(): self
    {
        $cmpId = Http::intParam('cmp_id', 0) ?? 0;
        $fyId  = Http::intParam('fy_id', 0) ?? 0;

        if ($cmpId <= 0 || $fyId <= 0) {
            Http::error(400, 'context_required', 'Pick a company and financial year first (cmp_id and fy_id are required).');
        }

        return new self($cmpId, $fyId, max(0, Http::intParam('bo_id', 0) ?? 0));
    }

    /**
     * Confirm this session may open this company, per Manage.
     *
     * Memoised per request because it runs on every scoped endpoint; a failure to
     * reach Manage is a 503 and not an allow, because the alternative is serving
     * one tenant's data to another whenever Manage has a bad minute.
     */
    public function assertAllowed(Auth $auth): void
    {
        if ($auth->isService()) {
            // A service key is issued to a product, not to a person, and the
            // owning product has already checked the human behind it.
            return;
        }

        $key = $this->cmpId . ':' . $auth->fingerprint();
        if (isset(self::$verified[$key])) {
            return;
        }

        $result = (new ManageClient())->withSession($auth->sesKey())->companyInfo($this->cmpId);

        if (!$result['ok']) {
            // Unreachable is not "allowed". A tenant check that fails open is not
            // a tenant check.
            Http::error(503, 'context_unavailable', 'Cannot confirm company access right now. Please retry.');
        }

        $body = $result['body'] ?? [];
        $company = $body['data'] ?? $body['company'] ?? $body;
        $resolved = (int) ($company['cmp_id'] ?? $company['comp_id'] ?? $company['id'] ?? 0);

        if ($resolved !== $this->cmpId) {
            Http::forbidden('You do not have access to this company.');
        }

        self::$verified[$key] = true;
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asQuery(): array
    {
        return ['cmp_id' => $this->cmpId, 'fy_id' => $this->fyId, 'bo_id' => $this->boId];
    }

    /** @return array{cmp_id:int, fy_id:int, bo_id:int} */
    public function asBody(): array
    {
        return $this->asQuery();
    }

    /** CLI only: forget which companies have been verified, between test cases. */
    public static function resetForTest(): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$verified = [];
    }
}
