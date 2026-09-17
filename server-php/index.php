<?php

declare(strict_types=1);

/**
 * Email API — front controller.
 *
 * Deployed to <document root>/api, so it is same-origin with the business app
 * on email.aicountly.com and email.gh.aicountly.com. The personal frontend on
 * aicountly.io is a DIFFERENT ORIGIN and reaches the same backend over CORS,
 * which is why the allowlist below exists and why it is a list rather than a
 * wildcard.
 *
 * Routes:
 *   GET  /api/health          liveness, readiness and which environment answered
 *   POST /api/global/{path}   allow-listed relay to the portal auth API
 *   GET  /api/session         who the caller is, per the portal
 *   *    /api/v1/...          the Email API proper — see src/Routes.php
 */

namespace Aicountly\Api;

require __DIR__ . '/src/Env.php';
require __DIR__ . '/src/Autoload.php';
require __DIR__ . '/src/Portal.php';

Env::load(__DIR__ . '/.env');

/**
 * Portal paths this API relays for the browser.
 *
 * The relay exists so the SPA never makes a cross-origin call to the portal:
 * a new product domain is not in the portal's CORS allowlist on day one.
 *
 * It is an allowlist and must stay one. Forwarding arbitrary paths would turn
 * this host into an open proxy for the portal's whole auth surface — login,
 * signup, OTP, user lookups — with the portal seeing this server's IP instead
 * of the caller's, so anything it rate-limits per IP could be driven through
 * here instead.
 */
const RELAYED_PATHS = [
    'seskey',
    'seskey/refresh',
    'refresh_authtoken',
];

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * @param array<string, mixed> $payload
 */
function send_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * The Authorization header, wherever this server happens to expose it.
 *
 * Under CGI/FastCGI Apache does not pass it to PHP unless it is copied
 * explicitly, and after an internal rewrite it arrives only under the
 * REDIRECT_ prefix. Reading just one of these is why an otherwise correct
 * deployment answers 401 to every sign-in.
 */
function authorization_header(): string
{
    $candidates = [
        $_SERVER['HTTP_AUTHORIZATION'] ?? '',
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
    ];

    if (function_exists('apache_request_headers')) {
        foreach ((array) apache_request_headers() as $name => $value) {
            if (strcasecmp((string) $name, 'Authorization') === 0) {
                $candidates[] = (string) $value;
                break;
            }
        }
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && $candidate !== '') {
            return $candidate;
        }
    }

    return '';
}

function bearer_token(): string
{
    $header = authorization_header();
    if ($header === '' || preg_match('/Bearer\s+(.+)/i', $header, $matches) !== 1) {
        return '';
    }

    return trim($matches[1]);
}

/**
 * Collapse a routed path to comparable segments.
 *
 * Percent-escapes are decoded first so `%2e%2e` cannot smuggle a traversal
 * segment past an allowlist.
 *
 * CASE IS PRESERVED. A Message-ID and an IMAP folder name are both
 * case-sensitive, and lower-casing the whole path would quietly break every
 * route that carries one.
 */
function normalise_path(string $path): string
{
    $decoded = str_replace('\\', '/', rawurldecode($path));
    $segments = array_values(array_filter(explode('/', $decoded), static fn ($s) => $s !== '' && $s !== '.' && $s !== '..'));

    return implode('/', $segments);
}

/**
 * CORS: an exact allowlist of the two frontend origins, plus local development.
 *
 * NEVER a wildcard. The two production frontends sit on different registrable
 * domains by design (email.aicountly.com and aicountly.io), so this is the one
 * place in the fleet where a real cross-origin API call is normal, and a `*`
 * here would let any page on the internet drive a user's mailbox with a token
 * it tricked out of them.
 *
 * Authentication is a Bearer ses_key in a header, not a cookie, so
 * Access-Control-Allow-Credentials is deliberately NOT sent: the browser does
 * not need to attach cookies, and not asking for them removes the CSRF surface
 * that cookie auth would bring. `Vary: Origin` keeps a proxy from serving one
 * origin's response to another.
 */
function apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '') {
        return;
    }

    $allowed = [
        'https://email.aicountly.com',
        'https://aicountly.io',
        'https://email.gh.aicountly.com',
        'https://io.gh.aicountly.com',
    ];
    foreach (array_filter(array_map('trim', explode(',', Env::get('CORS_ALLOWED_ORIGINS')))) as $extra) {
        $allowed[] = $extra;
    }

    if (!in_array($origin, $allowed, true)) {
        // No headers at all. The browser then blocks the response, which is the
        // correct outcome for an origin nobody configured.
        header('Vary: Origin');

        return;
    }

    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Idempotency-Key, X-Correlation-Id, X-Source-App, X-Saas-Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Expose-Headers: X-Correlation-Id, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset');
    header('Access-Control-Max-Age: 600');
    header('Vary: Origin');
}

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------

apply_cors();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

// Strip the directory this front controller is mounted under, so the same file
// works at <docroot>/api and at the root of a dedicated API vhost.
$mountPoint = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
if ($mountPoint !== '' && $mountPoint !== '/' && strpos($uri, $mountPoint) === 0) {
    $uri = substr($uri, strlen($mountPoint));
}

$path = normalise_path($uri);
$lowerPath = strtolower($path);

if ($path === '' || $lowerPath === 'health') {
    send_json(200, Health::report());
}

if (strpos($lowerPath, 'global/') === 0) {
    $portalPath = strtolower(substr($path, strlen('global/')));

    if (!in_array($portalPath, RELAYED_PATHS, true)) {
        send_json(404, ['message' => 'This path is not relayed. Call the portal API directly.']);
    }

    $headers = [];
    $authorization = authorization_header();
    if ($authorization !== '') {
        $headers[] = 'Authorization: ' . $authorization;
    }
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (is_string($contentType) && $contentType !== '') {
        $headers[] = 'Content-Type: ' . $contentType;
    }

    $body = (string) file_get_contents('php://input');
    $result = Portal::forward($method, $portalPath, $headers, $body);

    if ($result['status'] === 504) {
        send_json(504, ['message' => 'Auth service unavailable — please retry.']);
    }

    http_response_code($result['status']);
    header('Content-Type: ' . $result['contentType']);
    header('Cache-Control: no-store');
    echo $result['body'];
    exit;
}

if ($lowerPath === 'session') {
    $sesKey = bearer_token();
    if ($sesKey === '') {
        send_json(401, ['message' => 'Missing bearer session key.']);
    }

    $session = Portal::validateSesKey($sesKey);
    if ($session === null) {
        send_json(401, ['message' => 'Invalid or expired session.']);
    }

    send_json(200, [
        'authenticated' => true,
        'uuid' => $session['uuid_aictly'] ?? ($session['uuid'] ?? ''),
    ]);
}

// ---------------------------------------------------------------------------
// The Email API
//
// Everything above this line is the auth bootstrap and predates the product.
// Everything below is the product, and it all goes through one router so that
// authentication, account resolution and the mailbox check happen in one place
// rather than being remembered per endpoint.
// ---------------------------------------------------------------------------

$router = new Router();
Routes::register($router);

try {
    if ($router->dispatch($method, $path)) {
        exit;
    }
} catch (\PDOException $e) {
    // A database problem is ours, not the caller's. The detail goes to the log
    // with the correlation id; the caller gets something they can act on.
    error_log('[email] database error on ' . $path . ' [' . Correlation::id() . ']: ' . $e->getMessage());
    Http::error(503, 'database_unavailable', 'The Email database is not reachable right now. Please retry.', ['retryable' => true]);
} catch (\Throwable $e) {
    error_log('[email] unhandled error on ' . $path . ' [' . Correlation::id() . ']: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    Http::error(500, 'server_error', 'Something went wrong handling that request.');
}

send_json(404, ['message' => 'Not found.']);
