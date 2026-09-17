<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Db;
use Aicountly\Api\Http;

/**
 * The account's own audit trail.
 *
 * Scoped to the caller's account, always — an audit endpoint that takes an
 * account id from the query string is a way to read somebody else's activity.
 */
final class AuditController extends Controller
{
    public function index(): void
    {
        $account = $this->account();
        $limit = Http::limit(50);
        $before = Http::intParam('before');

        $rows = Db::all(
            'SELECT audit_id, action, entity_type, entity_id, outcome, detail, correlation_id, created_at, actor_uuid, source_app
               FROM email_audit_log
              WHERE account_id = :account
                AND (:before_set = 0 OR audit_id < :before_id)
              ORDER BY audit_id DESC
              LIMIT :limit',
            // Named placeholders are not reusable under native prepares.
            [
                'account'    => $account->accountId,
                'before_set' => $before ?? 0,
                'before_id'  => $before ?? 0,
                'limit'      => $limit,
            ],
        );

        $next = count($rows) === $limit ? (string) $rows[count($rows) - 1]['audit_id'] : null;

        Http::page(
            array_map(static fn (array $row) => [
                'audit_id'       => (int) $row['audit_id'],
                'action'         => $row['action'],
                'entity_type'    => $row['entity_type'],
                'entity_id'      => $row['entity_id'],
                'outcome'        => $row['outcome'],
                'detail'         => Db::jsonColumn($row['detail']),
                'correlation_id' => $row['correlation_id'],
                'actor'          => $row['actor_uuid'],
                'source_app'     => $row['source_app'],
                'created_at'     => $row['created_at'],
            ], $rows),
            $limit,
            $next,
            null,
            ['note' => 'Audit rows record ids, outcomes and timestamps. They never contain message content or data fetched from another product.'],
        );
    }
}
