<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Context;

/**
 * Live reads against purchase.aicountly.com.
 *
 * Purchase owns the requisition, the RFQ, the purchase order and the vendor
 * bill. Email reads one of those to put next to an email — the "email vs
 * business record" comparison — and keeps NOTHING from the response. Reopening
 * the comparison re-reads it, because a purchase order that was copied once is
 * a purchase order that keeps its old price after somebody corrects it.
 *
 * READ ONLY from Email. Revising a purchase order is Purchase's own workflow,
 * with its own approvals; Email's job is to notice the discrepancy and hand the
 * user to the right screen.
 */
final class PurchaseClient extends ApiClient
{
    private string $authorization = '';

    public function service(): string
    {
        return 'purchases';
    }

    protected function productionBase(): string
    {
        return 'https://purchase.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://purchase.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'PURCHASES_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;

        return $this;
    }

    public function purchaseOrder(Context $ctx, string $id): array
    {
        return $this->request(
            'GET',
            'v1/purchase-orders/' . rawurlencode($id) . self::query($ctx->asQuery()),
            null,
            ['Authorization' => $this->authorization],
        );
    }

    /** Find a purchase order by the number a supplier quoted in an email. */
    public function findByNumber(Context $ctx, string $number): array
    {
        return $this->request(
            'GET',
            'v1/purchase-orders' . self::query(['q' => $number, 'limit' => 5] + $ctx->asQuery()),
            null,
            ['Authorization' => $this->authorization],
        );
    }
}
