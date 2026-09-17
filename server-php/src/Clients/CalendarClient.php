<?php

declare(strict_types=1);

namespace Aicountly\Api\Clients;

use Aicountly\Api\Env;

/**
 * Live calls against calendar.aicountly.com.
 *
 * Calendar owns calendars, events, recurrence, free/busy and conflict
 * detection. When a commitment found in an email becomes a diary entry,
 * CALENDAR CREATES IT and Email keeps the returned uuid on the commitment row.
 * There is no events table in this product and there will not be one.
 *
 * Contract: appointments-aicountly/docs/CALENDAR_API_INTEGRATION.md, which is
 * where the header names and the conflict-check semantics below come from.
 * `checked: false` means Calendar could not establish an answer — Email treats
 * that as "not free", never as free.
 */
final class CalendarClient extends ApiClient
{
    private string $authorization = '';
    private string $serviceKey = '';
    private string $actorUuid = '';

    public function service(): string
    {
        return 'calendar';
    }

    protected function productionBase(): string
    {
        return 'https://calendar.aicountly.com';
    }

    protected function sandboxBase(): string
    {
        return 'https://calendar.gh.aicountly.com';
    }

    protected function baseEnvKey(): string
    {
        return 'CALENDAR_API_BASE';
    }

    public function withSession(string $sesKey): self
    {
        $this->authorization = 'Bearer ' . $sesKey;
        $this->serviceKey = '';

        return $this;
    }

    public function withService(string $actorUuid): self
    {
        $this->serviceKey = Env::get('CALENDAR_SERVICE_KEY');
        $this->actorUuid = $actorUuid;
        $this->authorization = '';

        return $this;
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        if ($this->serviceKey !== '') {
            return ['X-Service-Key' => $this->serviceKey, 'X-Actor-Uuid' => $this->actorUuid];
        }

        return ['Authorization' => $this->authorization];
    }

    /** @param array<string, mixed> $payload */
    public function createEvent(array $payload, string $idempotencyKey): array
    {
        return $this->request('POST', 'calendar/events', $payload, $this->authHeaders() + ['Idempotency-Key' => $idempotencyKey], true);
    }

    /** @param list<string> $subscribers */
    public function conflictCheck(array $subscribers, string $startsAt, string $endsAt): array
    {
        return $this->request('POST', 'calendar/conflict-check', [
            'subscribers' => $subscribers,
            'starts_at'   => $startsAt,
            'ends_at'     => $endsAt,
        ], $this->authHeaders(), true);
    }
}
