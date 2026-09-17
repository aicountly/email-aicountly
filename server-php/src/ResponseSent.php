<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * The response a controller produced, thrown instead of exited under CLI.
 *
 * Under a web SAPI Http::json() writes and exits. Under CLI it throws this, so
 * the test suite can call a real controller — through the real router, with the
 * real permission checks — and assert on what came back.
 */
final class ResponseSent extends \RuntimeException
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public readonly int $status,
        public readonly array $payload,
    ) {
        parent::__construct('HTTP ' . $status);
    }

    public function code(): string
    {
        $error = $this->payload['error'] ?? null;

        return is_array($error) ? (string) ($error['code'] ?? '') : '';
    }
}
