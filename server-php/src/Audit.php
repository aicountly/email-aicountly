<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Append-only audit of Email's own actions.
 *
 * WHAT GOES IN A ROW: who did it, what they did, which resource it was, when,
 * the correlation id, and the outcome. Ids and status — never a copy of the
 * record. Auditing "created a calendar event" stores the event's uuid and that
 * Calendar accepted it; it does not store the event.
 *
 * WHAT NEVER GOES IN A ROW: message bodies, subjects, attachment contents,
 * recipient lists, tokens, or any payload fetched from another product. An
 * audit table that quietly accumulates mail content is a second mail store with
 * none of a mail store's controls.
 */
final class Audit
{
    public const TABLE = 'email_audit_log';

    /**
     * @param array<string, mixed> $detail ids, counts, status — no content
     */
    public static function record(
        Auth $auth,
        ?Account $account,
        string $action,
        string $entityType,
        int|string|null $entityId,
        string $outcome = 'ok',
        array $detail = [],
        ?int $cmpId = null,
    ): void {
        try {
            Db::insert(self::TABLE, [
                'account_id'     => $account?->accountId,
                'cmp_id'         => $cmpId,
                'actor_uuid'     => $auth->uuid,
                'actor_kind'     => $auth->kind,
                'source_app'     => $auth->sourceApp,
                'action'         => $action,
                'entity_type'    => $entityType,
                'entity_id'      => $entityId === null ? null : (string) $entityId,
                'outcome'        => $outcome,
                'detail'         => self::scrub($detail),
                'correlation_id' => Correlation::id(),
                'ip_address'     => self::clientIp(),
                'created_at'     => gmdate('Y-m-d H:i:s'),
            ], 'audit_id');
        } catch (\Throwable $e) {
            // An audit write must never be the reason a user's action fails. It
            // is logged loudly instead, because a silently missing audit row is
            // worse than a noisy one.
            error_log('[email-audit] failed to record ' . $action . ' on ' . $entityType . ': ' . $e->getMessage());
        }
    }

    /**
     * Drop anything that looks like content or a credential before it is stored.
     *
     * A belt-and-braces filter, not a licence to pass content in: callers are
     * expected to hand over ids and counts. This is what stops one careless
     * call site turning the audit table into a mail archive.
     *
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private static function scrub(array $detail): array
    {
        $banned = ['body', 'body_html', 'body_text', 'html', 'text', 'subject', 'snippet',
                   'password', 'token', 'auth_token', 'ses_key', 'secret', 'api_key',
                   'attachment', 'content', 'recipients', 'to', 'cc', 'bcc'];

        $clean = [];
        foreach ($detail as $key => $value) {
            if (in_array(strtolower((string) $key), $banned, true)) {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = self::scrub($value);
                continue;
            }
            if (is_string($value) && strlen($value) > 240) {
                $clean[$key] = substr($value, 0, 240);
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function clientIp(): ?string
    {
        $candidates = [$_SERVER['HTTP_X_FORWARDED_FOR'] ?? '', $_SERVER['REMOTE_ADDR'] ?? ''];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                return $first;
            }
        }

        return null;
    }
}
