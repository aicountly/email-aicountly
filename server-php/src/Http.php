<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Request parsing and JSON responses.
 *
 * The envelopes match the rest of the AICOUNTLY fleet so a client written
 * against Books or Purchases reads this API without a second set of rules:
 *
 *   single  {"data": {...}}
 *   list    {"data": [...], "meta": {"total"|null, "limit", "cursor", "next_cursor"}}
 *   error   {"error": {"code", "message", "details"}, "message"}
 *
 * Every response carries `X-Correlation-Id`. Mail work fans out across a mail
 * store, a transport and several product APIs, and without one id tying those
 * log lines together a failed send is unreconstructable.
 */
final class Http
{
    /** Largest page a list endpoint will serve, however large a client asks for. */
    public const MAX_LIMIT = 200;

    /** @var array<string, mixed>|null */
    private static ?array $body = null;

    /** @var array<string, string> */
    private static array $extraHeaders = [];

    /** Queue a header to go out with the eventual response (rate-limit counters, mostly). */
    public static function addHeader(string $name, string $value): void
    {
        self::$extraHeaders[$name] = $value;
    }

    /**
     * Send the response and stop.
     *
     * Under CLI it throws ResponseSent instead of exiting, so the test suite can
     * assert on what a real controller produced. The web path is unchanged.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): never
    {
        $payload['correlation_id'] ??= Correlation::id();

        if (PHP_SAPI === 'cli') {
            throw new ResponseSent($status, $payload);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Correlation-Id: ' . Correlation::id());
        foreach (self::$extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function data(array $data, int $status = 200): never
    {
        self::json($status, ['data' => $data]);
    }

    /**
     * @param list<mixed>          $rows
     * @param array<string, mixed> $extra merged into meta
     */
    public static function list(array $rows, int $total, int $limit, int $offset, array $extra = []): never
    {
        self::json(200, [
            'data' => $rows,
            'meta' => ['total' => $total, 'limit' => $limit, 'offset' => $offset] + $extra,
        ]);
    }

    /**
     * A cursor page.
     *
     * `total` is nullable and usually null on purpose: counting a mailbox to
     * render one page is a full scan the user never asked for, and a wrong
     * total is worse than no total.
     *
     * @param list<mixed> $rows
     * @param array<string, mixed> $extra
     */
    public static function page(array $rows, int $limit, ?string $nextCursor, ?int $total = null, array $extra = []): never
    {
        self::json(200, [
            'data' => $rows,
            'meta' => [
                'limit'       => $limit,
                'cursor'      => self::param('cursor') ?: null,
                'next_cursor' => $nextCursor,
                'has_more'    => $nextCursor !== null,
                'total'       => $total,
            ] + $extra,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function error(int $status, string $code, string $message, array $details = []): never
    {
        self::json($status, [
            'error'   => ['code' => $code, 'message' => $message, 'details' => $details],
            'message' => $message,
        ]);
    }

    /** @param array<string, mixed> $details */
    public static function validationFailed(string $message, array $details = []): never
    {
        self::error(422, 'validation_failed', $message, $details);
    }

    public static function notFound(string $message = 'Not found.'): never
    {
        self::error(404, 'not_found', $message);
    }

    public static function forbidden(string $message = 'You do not have permission to do that.'): never
    {
        self::error(403, 'forbidden', $message);
    }

    public static function unauthorized(string $message = 'Sign in again to continue.'): never
    {
        self::error(401, 'unauthorized', $message);
    }

    /** @param array<string, mixed> $details */
    public static function conflict(string $message, array $details = []): never
    {
        self::error(409, 'conflict', $message, $details);
    }

    /**
     * A dependency this request needed is not configured.
     *
     * 503 and not 500: nothing is broken, something has not been set up, and the
     * UI shows an honest "not configured" state rather than an error toast.
     *
     * @param array<string, mixed> $details
     */
    public static function notConfigured(string $message, array $details = []): never
    {
        self::error(503, 'not_configured', $message, $details + ['retryable' => false]);
    }

    /**
     * The decoded JSON request body, or an empty array.
     *
     * Parsed once: reading php://input twice returns nothing the second time on
     * some SAPIs, which turns a perfectly good request into a validation error.
     *
     * @return array<string, mixed>
     */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        $raw = (string) file_get_contents('php://input');
        if ($raw === '') {
            return self::$body = [];
        }
        $decoded = json_decode($raw, true);

        return self::$body = is_array($decoded) ? $decoded : [];
    }

    /** CLI only: stand in for a request body so tests can call controllers. */
    public static function setBodyForTest(array $body): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$body = $body;
    }

    public static function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function header(string $name): string
    {
        $key = 'HTTP_' . str_replace('-', '_', strtoupper($name));
        $value = $_SERVER[$key] ?? $_SERVER['REDIRECT_' . $key] ?? '';

        return is_string($value) ? trim($value) : '';
    }

    /** Query parameter, falling back to the JSON body so POST callers need not duplicate context in the URL. */
    public static function param(string $name, ?string $default = null): ?string
    {
        if (isset($_GET[$name]) && is_scalar($_GET[$name])) {
            return trim((string) $_GET[$name]);
        }
        $body = self::body();
        if (isset($body[$name]) && is_scalar($body[$name])) {
            return trim((string) $body[$name]);
        }

        return $default;
    }

    public static function intParam(string $name, ?int $default = null): ?int
    {
        $raw = self::param($name);

        return ($raw === null || $raw === '') ? $default : (int) $raw;
    }

    public static function boolParam(string $name, ?bool $default = null): ?bool
    {
        $raw = self::param($name);
        if ($raw === null || $raw === '') {
            return $default;
        }

        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    /** @return list<mixed> */
    public static function arrayParam(string $name): array
    {
        $body = self::body();
        if (isset($body[$name]) && is_array($body[$name])) {
            return array_values($body[$name]);
        }
        $raw = self::param($name, '');

        return $raw === '' ? [] : array_values(array_filter(array_map('trim', explode(',', (string) $raw)), static fn ($v) => $v !== ''));
    }

    /** Page size, clamped. An unbounded page is a denial-of-service one query string long. */
    public static function limit(int $default = 40): int
    {
        $limit = (int) (self::param('limit') ?? $default);

        return max(1, min(self::MAX_LIMIT, $limit === 0 ? $default : $limit));
    }

    /**
     * The caller's idempotency key for a write, or ''.
     *
     * Only a bounded, printable key is accepted: it becomes a database key and
     * appears in logs, so an arbitrary-length binary value has no business here.
     */
    public static function idempotencyKey(): string
    {
        $key = self::header('Idempotency-Key');
        if ($key === '') {
            $key = (string) (self::param('idempotency_key') ?? '');
        }
        $clean = preg_replace('/[^A-Za-z0-9._:\-]/', '', $key) ?? '';

        return substr($clean, 0, 120);
    }
}
