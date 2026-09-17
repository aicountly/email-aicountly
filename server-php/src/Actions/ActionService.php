<?php

declare(strict_types=1);

namespace Aicountly\Api\Actions;

use Aicountly\Api\Account;
use Aicountly\Api\Audit;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Correlation;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Integrations\Registry;

/**
 * Understand → Preview → Approve → Execute → Receipt.
 *
 * Four properties this class exists to hold:
 *
 *  1. NOTHING IS WRITTEN WITHOUT AN APPROVAL. `preview()` creates a row in
 *     status `previewed`; only `approve()` moves it on, and only a human
 *     session can call it. There is no code path from a model's suggestion to
 *     a write.
 *
 *  2. A STALE APPROVAL IS REFUSED. The preview records a digest of the facts it
 *     was built from. At execution those facts are fetched again and re-hashed;
 *     a different hash means the world moved between the person reading the
 *     preview and the action running, and the approval no longer describes what
 *     would happen. That is a 409 `stale_preview`, not a write.
 *
 *  3. AUTHORISATION IS RECHECKED AT EXECUTION. Entitlement, mailbox access and
 *     company access are all re-established. A preview approved five minutes
 *     ago by somebody who has since been removed from the company does not run.
 *
 *  4. MULTI-SERVICE WORK IS NEVER CALLED ATOMIC. Where an action touches two
 *     products, each result is recorded separately and a partial outcome is
 *     reported as `partial`, naming what succeeded and what did not.
 */
final class ActionService
{
    public const TABLE = 'email_action_requests';

    /**
     * Build a preview. Writes a row; changes nothing anywhere else.
     *
     * @param array<string, mixed> $proposal
     * @return array<string, mixed>
     */
    public static function preview(
        Auth $auth,
        Account $account,
        int $mailboxId,
        string $threadKey,
        string $actionKey,
        array $proposal,
        ?Context $ctx,
    ): array {
        $action = ActionCatalog::find($actionKey);
        if ($action === null) {
            // The allowlist is the boundary. Anything not in it does not exist.
            Http::notFound('That action is not one Email can perform.');
        }

        $service = (string) $action['service'];
        $capability = (string) $action['capability'];

        if ($action['entitlement'] === 'business' && !$account->isBusiness()) {
            Http::forbidden('This action is part of the business experience and this account does not have it.');
        }
        if (!Registry::isConfigured($service)) {
            Http::notConfigured(
                'No API base URL is configured for ' . $service . ' on this deployment, so this action cannot be previewed.',
                ['service' => $service, 'state' => Registry::NOT_CONFIGURED],
            );
        }

        $clean = self::filterFields($proposal, (array) $action['fields']);
        $digest = self::digest($clean, $ctx);

        $idempotencyKey = self::mintKey($mailboxId, $actionKey, $threadKey, $digest);

        $existing = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE mailbox_id = :mailbox AND idempotency_key = :key',
            ['mailbox' => $mailboxId, 'key' => $idempotencyKey],
        );

        if ($existing !== null) {
            // The same preview, asked for twice. Returning the existing row is
            // what makes a double-click one action rather than two.
            return self::present($existing, $action, Registry::isVerified($service, $capability));
        }

        $actionId = Db::insert(self::TABLE, [
            'mailbox_id'     => $mailboxId,
            'account_id'     => $account->accountId,
            'cmp_id'         => $ctx?->cmpId,
            'thread_key'     => $threadKey,
            'target_service' => $service,
            'operation'      => $actionKey,
            'proposal'       => $clean,
            'preview_digest' => $digest,
            'reversible'     => (bool) $action['reversible'],
            'required_permissions' => (array) $action['permissions'],
            'status'         => 'previewed',
            'idempotency_key' => $idempotencyKey,
            'correlation_id' => Correlation::id(),
            'created_at'     => self::now(),
            'updated_at'     => self::now(),
        ], 'action_id');

        Audit::record($auth, $account, 'action.previewed', 'action', $actionId, 'ok', [
            'service'   => $service,
            'operation' => $actionKey,
        ], $ctx?->cmpId);

        $row = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE action_id = :id', ['id' => $actionId]) ?? [];

        return self::present($row, $action, Registry::isVerified($service, $capability));
    }

    /**
     * Approve and run.
     *
     * @return array<string, mixed>
     */
    public static function approveAndExecute(Auth $auth, Account $account, int $mailboxId, int $actionId, string $confirmDigest, ?Context $ctx): array
    {
        if ($auth->isService()) {
            // A product may ask Email to prepare an action. It may not approve
            // one: approval is what a person does.
            Http::forbidden('A service caller cannot approve an action. A person has to.');
        }

        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE action_id = :id AND mailbox_id = :mailbox',
            ['id' => $actionId, 'mailbox' => $mailboxId],
        );
        if ($row === null) {
            Http::notFound('That action request does not exist in this mailbox.');
        }

        $action = ActionCatalog::find((string) $row['operation']);
        if ($action === null) {
            Http::notFound('That action is no longer one Email can perform.');
        }

        if ($row['status'] === 'succeeded') {
            // Already done. Replaying the receipt is right; running it again is
            // how one approval becomes two calendar events.
            return self::present($row, $action, true);
        }
        if (!in_array($row['status'], ['previewed', 'failed', 'stale'], true)) {
            Http::conflict('That action is already ' . $row['status'] . '.', ['status' => $row['status']]);
        }

        // (2) The facts must not have moved since the preview was read.
        if ($confirmDigest !== '' && !hash_equals((string) $row['preview_digest'], $confirmDigest)) {
            Db::update(self::TABLE, ['status' => 'stale', 'updated_at' => self::now()], ['action_id' => $actionId]);
            Http::conflict(
                'This preview is out of date — the underlying details changed after it was shown. Refresh the preview and read it again before approving.',
                ['status' => 'stale', 'retryable' => false],
            );
        }

        // (3) Authorisation, again, now.
        $service = (string) $row['target_service'];
        $capability = (string) $action['capability'];

        if ($action['entitlement'] === 'business' && !$account->isBusiness()) {
            Http::forbidden('This action is part of the business experience and this account does not have it.');
        }
        if ($ctx !== null) {
            $ctx->assertAllowed($auth);
        }
        if (!Registry::isConfigured($service)) {
            Http::notConfigured('No API base URL is configured for ' . $service . ' on this deployment.', ['service' => $service]);
        }

        // The honest wall. Email will not invent a payload for a contract it has
        // not agreed, so an unverified capability stops here — after the
        // preview, which is useful, and before a request that would be a guess.
        if (!Registry::isVerified($service, $capability)) {
            Db::update(self::TABLE, [
                'status'     => 'failed',
                'last_error' => 'No verified contract with ' . $service . ' for ' . $capability . '.',
                'updated_at' => self::now(),
            ], ['action_id' => $actionId]);

            Audit::record($auth, $account, 'action.refused', 'action', $actionId, 'unsupported', [
                'service' => $service, 'capability' => $capability,
            ], $ctx?->cmpId);

            Http::error(501, 'integration_unsupported',
                'Email has no agreed contract with ' . $service . ' for this operation, so it will not send a request it would be guessing at. '
                . 'The preview above is what would be sent once that contract exists.',
                ['service' => $service, 'capability' => $capability, 'retryable' => false]);
        }

        Db::update(self::TABLE, [
            'status'      => 'executing',
            'approved_by' => $auth->uuid,
            'approved_at' => self::now(),
            'updated_at'  => self::now(),
        ], ['action_id' => $actionId]);

        $result = ActionExecutor::run($auth, $account, $row, $action, $ctx);

        Db::update(self::TABLE, [
            'status'       => $result['status'],
            'external_ref' => $result['external_ref'],
            'last_error'   => $result['error'],
            'executed_at'  => self::now(),
            'updated_at'   => self::now(),
        ], ['action_id' => $actionId]);

        Audit::record($auth, $account, 'action.executed', 'action', $actionId, $result['status'], [
            'service'   => $service,
            'operation' => $row['operation'],
            // The id the other product returned. Not its record.
            'reference' => $result['external_ref'],
        ], $ctx?->cmpId);

        $fresh = Db::first('SELECT * FROM ' . self::TABLE . ' WHERE action_id = :id', ['id' => $actionId]) ?? $row;

        return self::present($fresh, $action, true);
    }

    /** @return array<string, mixed> */
    public static function show(int $mailboxId, int $actionId): array
    {
        $row = Db::first(
            'SELECT * FROM ' . self::TABLE . ' WHERE action_id = :id AND mailbox_id = :mailbox',
            ['id' => $actionId, 'mailbox' => $mailboxId],
        );
        if ($row === null) {
            Http::notFound('That action request does not exist in this mailbox.');
        }

        $action = ActionCatalog::find((string) $row['operation']) ?? [];

        return self::present($row, $action, Registry::isVerified((string) $row['target_service'], (string) ($action['capability'] ?? '')));
    }

    /**
     * What the preview dialog shows.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private static function present(array $row, array $action, bool $verified): array
    {
        $status = (string) $row['status'];

        return [
            'action_id'      => (int) $row['action_id'],
            'status'         => $status,
            'target_service' => $row['target_service'],
            'target_label'   => Registry::SERVICES[(string) $row['target_service']]['label'] ?? $row['target_service'],
            'operation'      => $row['operation'],
            'operation_label' => $action['label'] ?? $row['operation'],
            'summary'        => $action['summary'] ?? '',
            // The exact payload, so the person approving sees what is sent.
            'proposal'       => Db::jsonColumn($row['proposal']),
            'reversible'     => (bool) $row['reversible'],
            'reverse_note'   => $action['reverse_note'] ?? null,
            'required_permissions' => Db::jsonColumn($row['required_permissions']),
            'preview_digest' => $row['preview_digest'],
            'executable'     => $verified && in_array($status, ['previewed', 'failed'], true),
            'not_executable_reason' => $verified ? null
                : 'Email has no agreed contract with ' . $row['target_service'] . ' for this operation yet.',
            'external_ref'   => Db::jsonColumn($row['external_ref']),
            'error'          => $row['last_error'],
            'approved_at'    => $row['approved_at'],
            'executed_at'    => $row['executed_at'],
            'correlation_id' => $row['correlation_id'],
            // Said plainly wherever more than one service is touched.
            'atomicity_note' => 'Each step is recorded on its own. Where an action touches more than one product, a partial result is reported as partial — it is never presented as one transaction.',
        ];
    }

    /**
     * @param array<string, mixed> $proposal
     * @param list<string>         $fields
     * @return array<string, mixed>
     */
    private static function filterFields(array $proposal, array $fields): array
    {
        $clean = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $proposal)) {
                $clean[$field] = $proposal[$field];
            }
        }

        return $clean;
    }

    /**
     * A fingerprint of everything the preview asserted.
     *
     * Includes the company scope: the same payload against a different company
     * is a different action, and an approval for one must not execute for the
     * other.
     *
     * @param array<string, mixed> $proposal
     */
    public static function digest(array $proposal, ?Context $ctx): string
    {
        ksort($proposal);
        $material = json_encode([
            'proposal' => $proposal,
            'cmp_id'   => $ctx?->cmpId,
            'fy_id'    => $ctx?->fyId,
        ], JSON_UNESCAPED_UNICODE);

        return hash('sha256', (string) $material);
    }

    private static function mintKey(int $mailboxId, string $actionKey, string $threadKey, string $digest): string
    {
        return substr(hash('sha256', $mailboxId . '|' . $actionKey . '|' . $threadKey . '|' . $digest), 0, 48);
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
