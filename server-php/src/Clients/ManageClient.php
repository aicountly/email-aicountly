<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

/**
 * Live reads against manage.aicountly.com.
 *
 * Manage owns the company, the branch and the financial year. Email stores
 * `cmp_id` on a shared mailbox and on an audit row, and nothing else — no
 * company name, no branch master, no financial-year dates. A screen that needs
 * the company's name asks here, on that request.
 */
final class ManageClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'manage';
    }

    protected function productionBase(): string
    {
        return 'https://manage.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://manage.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'MANAGE_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    /**
     * Company, its branches and its financial years, in one call.
     *
     * Memoised for the request by ApiClient: the tenant check runs on every
     * company-scoped endpoint, and asking per endpoint would put Manage in the
     * hot path of every screen Email draws.
     */
    public function companyInfo(int $cmpId): array
    {
        return $this->request('GET', 'companyinfo' . self::query(['comp_id' => $cmpId]), null, ['Authorization' => $this->authorization]);
    }

    /** Companies this session may open — the workspace switcher. */
    public function companies(array $filters = []): array
    {
        return $this->request('GET', 'companies' . self::query($filters), null, ['Authorization' => $this->authorization]);
    }
}
