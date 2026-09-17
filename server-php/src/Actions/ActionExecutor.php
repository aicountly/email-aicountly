<?php

declare(strict_types=1);

namespace Aicountly\Api\Actions;

use Aicountly\Api\Account;
use Aicountly\Api\Auth;
use Aicountly\Api\Clients\CalendarClient;
use Aicountly\Api\Clients\PurchaseClient;
use Aicountly\Api\Context;
use Aicountly\Api\Integrations\Registry;

/**
 * The one place an approved action actually calls another product.
 *
 * Every branch below is a capability the Registry marks verified — anything
 * else never reaches here, because ActionService refuses it first. The result
 * shape is the same for all of them so that the receipt, the audit row and the
 * UI have one thing to read.
 *
 * WHAT IS KEPT FROM A RESPONSE: the identifier. Nothing else. An action that
 * creates a calendar event keeps the event uuid; it does not keep the event,
 * its title, its attendees or its time, because Calendar owns those and a copy
 * of them here would be a second answer that nothing reconciles.
 *
 * IDEMPOTENCY: the request carries the action row's own key, which was minted
 * and stored before the call. A retry after a timeout presents the same key, so
 * a product that honours it replays rather than writing twice.
 */
final class ActionExecutor
{
    /**
     * @param array<string, mixed> $row    the email_action_requests row
     * @param array<string, mixed> $action its ActionCatalog entry
     * @return array{status:string, external_ref:array<string, mixed>, error:?string}
     */
    public static function run(Auth $auth, Account $account, array $row, array $action, ?Context $ctx): array
    {
        $decoded = is_array($row['proposal']) ? $row['proposal'] : json_decode((string) $row['proposal'], true);
        $proposal = is_array($decoded) ? $decoded : [];
        $idempotencyKey = (string) $row['idempotency_key'];

        try {
            return match ((string) $row['operation']) {
                'calendar.create_event'  => self::createCalendarEvent($auth, $proposal, $idempotencyKey),
                'purchases.open_order'   => self::readPurchaseOrder($auth, $proposal, $ctx),
                default                  => self::unsupported((string) $row['target_service'], (string) $row['operation']),
            };
        } catch (\Throwable $e) {
            error_log('[email-action] ' . $row['operation'] . ' failed: ' . $e->getMessage());

            return [
                'status'       => 'failed',
                'external_ref' => [],
                'error'        => 'The action could not be completed. ' . self::safeMessage($e),
            ];
        }
    }

    /**
     * Calendar owns the event. Email keeps its uuid on the commitment and
     * nothing else about it.
     *
     * @param array<string, mixed> $proposal
     * @return array{status:string, external_ref:array<string, mixed>, error:?string}
     */
    private static function createCalendarEvent(Auth $auth, array $proposal, string $idempotencyKey): array
    {
        $client = (new CalendarClient())->withSession($auth->sesKey());

        $result = $client->createEvent([
            'title'      => (string) ($proposal['title'] ?? ''),
            'starts_at'  => (string) ($proposal['starts_at'] ?? ''),
            'ends_at'    => (string) ($proposal['ends_at'] ?? ''),
            // Always explicit. "09:00" with no zone is three different moments.
            'timezone'   => (string) ($proposal['timezone'] ?? 'Asia/Kolkata'),
            'attendees'  => is_array($proposal['attendees'] ?? null) ? $proposal['attendees'] : [],
            'notes'      => (string) ($proposal['notes'] ?? ''),
            'source_app' => 'email',
        ], $idempotencyKey);

        if ($result['ok']) {
            $body = $result['body']['data'] ?? $result['body'] ?? [];

            return [
                'status'       => 'succeeded',
                'external_ref' => [
                    'service'   => 'calendar',
                    'event_uuid' => (string) ($body['uuid'] ?? $body['event_uuid'] ?? $body['id'] ?? ''),
                ],
                'error'        => null,
            ];
        }

        // A transport failure is genuinely uncertain: Calendar may have created
        // the event before the connection died. Saying "failed" would invite a
        // retry that creates a second one, so it is reported as uncertain and
        // the idempotency key makes a deliberate retry safe.
        if ($result['status'] === 0) {
            Registry::recordHealth('calendar', Registry::TEMPORARILY_UNAVAILABLE, null, 'No response');

            return [
                'status'       => 'uncertain',
                'external_ref' => [],
                'error'        => 'Calendar did not answer. The event may or may not have been created — open Calendar to check before trying again.',
            ];
        }

        Registry::recordHealth('calendar', Registry::NOT_CONNECTED, $result['status'], (string) $result['error']);

        return [
            'status'       => 'failed',
            'external_ref' => [],
            'error'        => $result['status'] === 403
                ? 'Calendar refused this: the signed-in user does not have permission to write to that calendar.'
                : 'Calendar refused this action (' . $result['status'] . ').',
        ];
    }

    /**
     * A read, so the receipt is the reference and the time it was read — never
     * the record itself.
     *
     * @param array<string, mixed> $proposal
     * @return array{status:string, external_ref:array<string, mixed>, error:?string}
     */
    private static function readPurchaseOrder(Auth $auth, array $proposal, ?Context $ctx): array
    {
        if ($ctx === null) {
            return ['status' => 'failed', 'external_ref' => [], 'error' => 'A company and financial year are needed to read a purchase order.'];
        }

        $result = (new PurchaseClient())
            ->withSession($auth->sesKey())
            ->purchaseOrder($ctx, (string) ($proposal['purchase_order_id'] ?? ''));

        if (!$result['ok']) {
            Registry::recordHealth('purchases', $result['status'] === 0 ? Registry::TEMPORARILY_UNAVAILABLE : Registry::NOT_CONNECTED, $result['status'] ?: null, (string) $result['error']);

            return [
                'status'       => 'failed',
                'external_ref' => [],
                'error'        => $result['status'] === 0
                    ? 'Purchase did not answer in time.'
                    : 'Purchase refused the read (' . $result['status'] . ').',
            ];
        }

        Registry::recordHealth('purchases', Registry::CONNECTED, $result['status'], null);
        $body = $result['body']['data'] ?? [];

        return [
            'status'       => 'succeeded',
            'external_ref' => [
                'service'  => 'purchases',
                'po_id'    => (string) ($body['id'] ?? $proposal['purchase_order_id'] ?? ''),
                'read_at'  => gmdate('c'),
            ],
            'error'        => null,
        ];
    }

    /** @return array{status:string, external_ref:array<string, mixed>, error:?string} */
    private static function unsupported(string $service, string $operation): array
    {
        return [
            'status'       => 'failed',
            'external_ref' => [],
            'error'        => 'Email has no executor for ' . $operation . ' on ' . $service . ' yet.',
        ];
    }

    /**
     * An exception message the user may see.
     *
     * Exception text can carry a URL, a query string or a credential, so only a
     * category leaves this process; the detail is in the log with the
     * correlation id.
     */
    private static function safeMessage(\Throwable $e): string
    {
        return $e instanceof \JsonException
            ? 'The response could not be read.'
            : 'The correlation id in this response will find the detail in the server log.';
    }
}
