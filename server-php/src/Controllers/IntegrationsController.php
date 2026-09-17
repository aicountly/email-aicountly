<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Actions\ActionCatalog;
use Aicountly\Api\Http;
use Aicountly\Api\Integrations\Registry;

/**
 * The integration registry, as the UI shows it.
 *
 * Listing probes nothing — twenty synchronous HTTP calls to render a settings
 * panel is how a PHP-FPM pool deadlocks. The listed state comes from the last
 * recorded probe; `POST /integrations/{service}/check` runs exactly one, on
 * demand, when somebody presses Check.
 */
final class IntegrationsController extends Controller
{
    public function index(): void
    {
        Http::json(200, [
            'data' => [
                'services' => Registry::describeAll($this->auth(), $this->account()),
                'actions'  => ActionCatalog::availableFor($this->account()->isBusiness()),
            ],
            'meta' => [
                'note' => 'A registry entry means Email knows how to talk to that product. Only a capability with a verified contract is offered, and a failing integration never blocks email.',
                'fetched_at' => gmdate('c'),
            ],
        ]);
    }

    /** One probe, on demand. Health only — nothing from the response body is stored. */
    public function check(string $service): void
    {
        $service = strtolower($service);
        if (!isset(Registry::SERVICES[$service])) {
            Http::notFound('Email has no adapter for that service.');
        }

        $base = Registry::baseUrl($service);
        if ($base === null) {
            Registry::recordHealth($service, Registry::NOT_CONFIGURED, null, null);
            Http::data(['service' => $service, 'state' => Registry::NOT_CONFIGURED,
                        'reason' => 'No API base URL is configured for this service on this deployment.']);
        }

        $handle = curl_init(rtrim($base, '/') . '/api/health');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_HTTPHEADER     => ['X-Saas-Origin: email'],
        ]);
        curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        $state = match (true) {
            $status === 0            => Registry::TEMPORARILY_UNAVAILABLE,
            $status >= 200 && $status < 400 => Registry::CONNECTED,
            $status === 401 || $status === 403 => Registry::PERMISSION_REQUIRED,
            default                  => Registry::NOT_CONNECTED,
        };

        Registry::recordHealth($service, $state, $status ?: null, null);

        Http::data(['service' => $service, 'state' => $state, 'http_status' => $status ?: null, 'checked_at' => gmdate('c')]);
    }
}
