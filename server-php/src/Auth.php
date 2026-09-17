<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Who is calling.
 *
 * Two ways in, exactly as the rest of the fleet accepts:
 *
 *  1. A human — `Authorization: Bearer <ses_key>`, validated at my.aicountly.com.
 *     The ses_key is kept so Email can call Manage, Purchase, Books, Calendar
 *     and the rest AS THAT USER, which is what makes their permissions apply
 *     over there instead of Email re-implementing them.
 *
 *  2. A trusted product backend — `X-Service-Key`, plus `X-Actor-Uuid` naming the
 *     human it is acting for, for the audit trail.
 *
 * `source_app` is decided HERE and never read from a header: a service key
 * resolves to its product, a human session is always Email.
 *
 * THIS CLASS ESTABLISHES IDENTITY ONLY. It says nothing about which mailbox the
 * caller may open — that is MailboxAccess, and every mailbox-scoped route calls
 * it. An id in a URL is a claim, not an authorisation.
 */
final class Auth
{
    private function __construct(
        public readonly string $uuid,
        /** 'user' | 'service' */
        public readonly string $kind,
        public readonly string $sourceApp,
        private readonly string $sesKey,
        /** @var array<string, mixed>|null */
        private readonly ?array $session,
    ) {
    }

    /**
     * The caller a CLI test has stood in as.
     *
     * The same seam as ResponseSent, for the same reason: a controller resolves
     * its caller from HTTP headers, which a test has none of. Rather than let
     * tests reach past the controllers into the services — where the permission
     * checks are not — they adopt an identity and call the real endpoint.
     *
     * CLI ONLY. Under a web SAPI this is ignored outright, so it cannot become
     * an authentication bypass however it is called.
     */
    private static ?self $adopted = null;

    public static function adopt(?self $auth): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$adopted = $auth;
    }

    /** @param array<string, mixed> $session */
    public static function forTest(string $uuid, string $kind = 'user', string $sourceApp = 'email', array $session = []): self
    {
        return new self($uuid, $kind, $sourceApp, $kind === 'user' ? 'test-ses-key' : '', $session);
    }

    /** Resolve the caller, or answer 401 and stop. */
    public static function require(): self
    {
        if (PHP_SAPI === 'cli' && self::$adopted !== null) {
            return self::$adopted;
        }

        $resolved = self::resolve();
        if ($resolved === null) {
            Http::unauthorized();
        }

        return $resolved;
    }

    public static function resolve(): ?self
    {
        $serviceKey = Http::header('X-Service-Key');
        if ($serviceKey !== '') {
            $app = ServiceKeys::resolveApp($serviceKey);
            if ($app === null) {
                return null;
            }
            // Proven by the key, not claimed in a header. Recording it is what
            // stops us calling that product back inside its own request.
            CrossServiceCallContext::adoptAuthenticatedOrigin($app);
            $actor = Http::header('X-Actor-Uuid');

            return new self(
                $actor !== '' ? $actor : 'service:' . $app,
                'service',
                $app,
                '',
                null,
            );
        }

        $sesKey = self::bearer();
        if ($sesKey === '') {
            return null;
        }

        $session = Portal::validateSesKey($sesKey);
        if ($session === null) {
            return null;
        }

        return new self(
            (string) ($session['uuid_aictly'] ?? $session['uuid'] ?? ''),
            'user',
            Env::get('APP_PRODUCT_KEY', 'email'),
            $sesKey,
            $session,
        );
    }

    public function isService(): bool
    {
        return $this->kind === 'service';
    }

    /**
     * The session key, for calling other products as this user.
     *
     * Empty for a service caller, which is correct: a service acts with its own
     * key over there, not with a borrowed human session.
     */
    public function sesKey(): string
    {
        return $this->sesKey;
    }

    /** Stable per-session identifier for memo keys. Never the key itself, which must not reach a log or a cache key. */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->kind . '|' . $this->uuid . '|' . $this->sesKey), 0, 32);
    }

    /** Portal access type for the company when the portal reported one: 1 = owner. */
    public function accessType(): ?int
    {
        return isset($this->session['acs_type']) ? (int) $this->session['acs_type'] : null;
    }

    public function displayName(): string
    {
        foreach (['name', 'full_name', 'user_name', 'email'] as $field) {
            $value = $this->session[$field] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->uuid;
    }

    /** The portal's email for this user, when it reported one. Used for display, never for authorisation. */
    public function portalEmail(): string
    {
        $value = $this->session['email'] ?? '';

        return is_string($value) ? $value : '';
    }

    private static function bearer(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($header) || $header === '') {
            if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strcasecmp((string) $name, 'Authorization') === 0) {
                        $header = (string) $value;
                        break;
                    }
                }
            }
        }
        if (!is_string($header) || preg_match('/Bearer\s+(.+)/i', $header, $m) !== 1) {
            return '';
        }

        return trim($m[1]);
    }
}
