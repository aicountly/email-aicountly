<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * Handing one message to the outbound mail system.
 *
 * THE OUTCOMES ARE THE POINT. SMTP can tell you four different things and a
 * product that collapses them into "sent" lies to its users:
 *
 *   ACCEPTED   the transport took responsibility and gave a queue id. This is
 *              NOT "delivered": the recipient's server has not been heard from.
 *   REJECTED   a permanent refusal (5xx, bad address). Retrying sends it again
 *              to the same refusal.
 *   DEFERRED   a temporary refusal (4xx). Retrying the SAME message is correct.
 *   UNCERTAIN  the connection died after DATA was written. The server may or
 *              may not have accepted it. Resending here is how people send the
 *              same invoice twice, so it is never done automatically.
 *
 * Delivery CONFIRMED is not in this list on purpose. It is not something a
 * submission client can observe; it needs a DSN or provider webhook, and until
 * one is wired up the product must not claim it.
 */
interface MailTransport
{
    public const ACCEPTED  = 'accepted';
    public const REJECTED  = 'rejected';
    public const DEFERRED  = 'deferred';
    public const UNCERTAIN = 'uncertain';

    /** Is the transport configured at all? Drives the honest unavailable state. */
    public function isConfigured(): bool;

    /** @return array<string, mixed> configured / host presence / mode — never a credential */
    public function describe(): array;

    /**
     * Submit one already-built RFC 5322 message.
     *
     * @param list<string> $recipients envelope recipients (To + Cc + Bcc)
     * @return array{outcome:string, queue_id:?string, code:?int, detail:?string}
     */
    public function send(string $envelopeFrom, array $recipients, string $rawMessage): array;
}
