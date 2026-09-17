<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * What Email uses when no outbound transport has been set up.
 *
 * It refuses, loudly and specifically, and it NEVER pretends. The difference
 * between this and a transport that silently drops mail is the difference
 * between a product that tells you it is not finished and one that loses your
 * mail.
 */
final class UnconfiguredMailTransport implements MailTransport
{
    public function __construct(private readonly string $reason) {}

    public function isConfigured(): bool
    {
        return false;
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            'configured' => false,
            'driver'     => 'none',
            'reason'     => $this->reason,
            'admin_hint' => 'Set MAIL_SMTP_HOST, MAIL_SMTP_PORT, MAIL_SMTP_USERNAME and MAIL_SMTP_PASSWORD in api/.env.',
        ];
    }

    /** @param list<string> $recipients */
    public function send(string $envelopeFrom, array $recipients, string $rawMessage): array
    {
        return [
            'outcome'  => self::REJECTED,
            'queue_id' => null,
            'code'     => null,
            'detail'   => $this->reason,
        ];
    }
}
