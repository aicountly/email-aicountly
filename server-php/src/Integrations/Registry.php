<?php

declare(strict_types=1);

namespace Aicountly\Api\Integrations;

use Aicountly\Api\Account;
use Aicountly\Api\Auth;
use Aicountly\Api\Db;
use Aicountly\Api\Env;

/**
 * Every AICOUNTLY product Email knows how to talk to, and the honest state of
 * each one.
 *
 * A REGISTRY ENTRY IS NOT A PROMISE. Every capability below carries `verified`,
 * and only a verified one is ever advertised to the UI as available. `verified`
 * means the endpoint and its shape were read out of that product's own code or
 * contract document in this repository set — not inferred from a pattern, and
 * not guessed from the product's name. An unverified capability renders as
 * "Unsupported" with the reason, and its button is disabled.
 *
 * Six states, and they mean different things to the person reading the screen:
 *
 *   connected               configured, entitled, and the last probe succeeded
 *   not_connected           configured, but the last probe failed
 *   not_configured          no base URL for this deployment
 *   permission_required     the account is not entitled, or the user is not
 *   temporarily_unavailable configured and entitled, and it timed out
 *   unsupported             Email has no verified contract for what was asked
 *
 * None of these blocks email. A dead integration greys out one panel; the inbox
 * keeps working.
 */
final class Registry
{
    public const CONNECTED    = 'connected';
    public const NOT_CONNECTED = 'not_connected';
    public const NOT_CONFIGURED = 'not_configured';
    public const PERMISSION_REQUIRED = 'permission_required';
    public const TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';
    public const UNSUPPORTED = 'unsupported';

    public const HEALTH_TABLE = 'email_integration_health';

    /**
     * The catalogue.
     *
     * `owns` is the sentence that settles arguments: it is what that product is
     * the authority for, and therefore what Email must never store.
     *
     * @var array<string, array<string, mixed>>
     */
    public const SERVICES = [
        'manage' => [
            'label' => 'Manage',
            'owns' => 'Company, branch and financial year',
            'production' => 'https://manage.aicountly.com',
            'sandbox' => 'https://manage.gh.aicountly.com',
            'env' => 'MANAGE_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'optional',
            'capabilities' => [
                'company.list' => ['verified' => true,  'operation' => 'GET /api/companies',   'writes' => false],
                'company.read' => ['verified' => true,  'operation' => 'GET /api/companyinfo', 'writes' => false],
            ],
        ],
        'purchases' => [
            'label' => 'Purchase',
            'owns' => 'Purchase requisitions, RFQs, purchase orders and vendor bills',
            'production' => 'https://purchase.aicountly.com',
            'sandbox' => 'https://purchase.gh.aicountly.com',
            'env' => 'PURCHASES_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'optional',
            'capabilities' => [
                'purchase_order.read' => ['verified' => true, 'operation' => 'GET /api/v1/purchase-orders/{id}', 'writes' => false],
                'purchase_order.list' => ['verified' => true, 'operation' => 'GET /api/v1/purchase-orders',      'writes' => false],
                // Purchase exposes the write, but Email has not agreed a payload
                // with it, so the action is previewed and then refused rather
                // than sent as a guess.
                'purchase_order.revise' => ['verified' => false, 'operation' => 'POST /api/v1/purchase-orders/{id}/revise', 'writes' => true],
            ],
        ],
        'sales' => [
            'label' => 'Sales',
            'owns' => 'Quotations, sales orders and the sales workflow',
            'production' => 'https://sales.aicountly.com',
            'sandbox' => 'https://sales.gh.aicountly.com',
            'env' => 'SALES_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'optional',
            'capabilities' => [
                'sales_order.read' => ['verified' => false, 'operation' => 'GET /api/v1/sales-orders/{id}', 'writes' => false],
                'quotation.create' => ['verified' => false, 'operation' => 'POST /api/v1/quotations',       'writes' => true],
            ],
        ],
        'books' => [
            'label' => 'Smart Books',
            'owns' => 'Commercial amounts, taxes, invoice totals, debtor and creditor effects, every financial posting',
            'production' => 'https://books.aicountly.com',
            'sandbox' => 'https://books.gh.aicountly.com',
            'env' => 'BOOKS_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'optional',
            'capabilities' => [
                'register.list'   => ['verified' => true, 'operation' => 'GET /api/registers',              'writes' => false],
                'bill_by_bill'    => ['verified' => true, 'operation' => 'GET /api/reports/bill-by-bill',   'writes' => false],
                'account.list'    => ['verified' => true, 'operation' => 'GET /api/masters/accounts',       'writes' => false],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory',
            'owns' => 'Items, stock, quantity movements, valuation and COGS',
            'production' => 'https://inventory.aicountly.com',
            'sandbox' => 'https://inventory.gh.aicountly.com',
            'env' => 'INVENTORY_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'optional',
            'capabilities' => [
                'availability.check' => ['verified' => true, 'operation' => 'GET /api/v1/availability',  'writes' => false],
                'item.search'        => ['verified' => true, 'operation' => 'GET /api/v1/items/search',  'writes' => false],
            ],
        ],
        'calendar' => [
            'label' => 'Calendar',
            'owns' => 'Calendars, events, recurrence, free/busy and conflict detection',
            'production' => 'https://calendar.aicountly.com',
            'sandbox' => 'https://calendar.gh.aicountly.com',
            'env' => 'CALENDAR_API_BASE',
            'entitlement' => 'any',
            'timeout' => 'required',
            'capabilities' => [
                'event.create'   => ['verified' => true, 'operation' => 'POST /api/calendar/events',         'writes' => true],
                'conflict.check' => ['verified' => true, 'operation' => 'POST /api/calendar/conflict-check', 'writes' => false],
                'free_busy.read' => ['verified' => true, 'operation' => 'GET /api/calendar/free-busy',       'writes' => false],
            ],
        ],
        'contacts' => [
            'label' => 'Contacts',
            'owns' => 'The party directory — names, addresses, GSTINs',
            'production' => 'https://contacts.aicountly.com',
            'sandbox' => 'https://contacts.gh.aicountly.com',
            'env' => 'CONTACTS_API_BASE',
            'entitlement' => 'any',
            'timeout' => 'optional',
            'capabilities' => [
                'contact.search' => ['verified' => true, 'operation' => 'GET /api/v1/contacts', 'writes' => false],
                'contact.read'   => ['verified' => true, 'operation' => 'GET /api/v1/contacts/{id}', 'writes' => false],
                'contact.link'   => ['verified' => false, 'operation' => 'POST /api/v1/contacts/{id}/links', 'writes' => true],
            ],
        ],
        'drive' => [
            'label' => 'Drive',
            'owns' => 'Files deliberately saved into the shared drive',
            'production' => 'https://drive.aicountly.com',
            'sandbox' => 'https://drive.gh.aicountly.com',
            'env' => 'DRIVE_API_BASE',
            'entitlement' => 'any',
            'timeout' => 'required',
            'capabilities' => [
                'file.save' => ['verified' => false, 'operation' => 'POST /api/v1/files', 'writes' => true],
            ],
        ],
        'pay' => [
            'label' => 'Pay',
            'owns' => 'Payment orchestration and provider integration',
            'production' => 'https://pay.aicountly.com',
            'sandbox' => 'https://pay.gh.aicountly.com',
            'env' => 'PAY_API_BASE',
            'entitlement' => 'business',
            'timeout' => 'required',
            'capabilities' => [
                // Even when this is verified it will stay approval-only and will
                // never move money: Email asks Pay for a payment LINK, and Pay
                // applies its own controls to it.
                'payment_link.request' => ['verified' => false, 'operation' => 'POST /api/v1/payment-links', 'writes' => true],
            ],
        ],
        'billing'      => ['label' => 'Billing',      'owns' => 'Billing documents and the biller desk',              'production' => 'https://billing.aicountly.com',      'sandbox' => 'https://billing.gh.aicountly.com',      'env' => 'BILLING_API_BASE',      'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'pos'          => ['label' => 'POS',          'owns' => 'Point-of-sale transactions',                          'production' => 'https://pos.aicountly.com',          'sandbox' => 'https://pos.gh.aicountly.com',          'env' => 'POS_API_BASE',          'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'connect'      => ['label' => 'Connect',      'owns' => 'Chats, calls and collaboration',                      'production' => 'https://connect.aicountly.com',      'sandbox' => 'https://connect.gh.aicountly.com',      'env' => 'CONNECT_API_BASE',      'entitlement' => 'any',      'timeout' => 'optional', 'capabilities' => []],
        'lobby'        => ['label' => 'Lobby',        'owns' => 'Visitor and receptionist workflows',                  'production' => 'https://lobby.aicountly.com',        'sandbox' => 'https://lobby.gh.aicountly.com',        'env' => 'LOBBY_API_BASE',        'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'appointments' => ['label' => 'Appointments', 'owns' => 'Booking orchestration (Calendar owns the events)',    'production' => 'https://appointments.aicountly.com', 'sandbox' => 'https://appointments.gh.aicountly.com', 'env' => 'APPOINTMENTS_API_BASE', 'entitlement' => 'any',      'timeout' => 'optional', 'capabilities' => []],
        'crm'          => ['label' => 'CRM',          'owns' => 'Leads, opportunities and the pipeline',               'production' => 'https://crm.aicountly.com',          'sandbox' => 'https://crm.gh.aicountly.com',          'env' => 'CRM_API_BASE',          'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'helpdesk'     => ['label' => 'Helpdesk',     'owns' => 'Support tickets',                                     'production' => 'https://helpdesk.aicountly.com',     'sandbox' => 'https://helpdesk.gh.aicountly.com',     'env' => 'HELPDESK_API_BASE',     'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'notes'        => ['label' => 'Notes',        'owns' => 'Notes',                                               'production' => 'https://notes.aicountly.com',        'sandbox' => 'https://notes.gh.aicountly.com',        'env' => 'NOTES_API_BASE',        'entitlement' => 'any',      'timeout' => 'optional', 'capabilities' => []],
        'contracts'    => ['label' => 'Contracts',    'owns' => 'Contracts and their lifecycle',                       'production' => 'https://contracts.aicountly.com',    'sandbox' => 'https://contracts.gh.aicountly.com',    'env' => 'CONTRACTS_API_BASE',    'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'hrms'         => ['label' => 'HRMS',         'owns' => 'People, payroll and leave',                           'production' => 'https://hrms.aicountly.com',         'sandbox' => 'https://hrms.gh.aicountly.com',         'env' => 'HRMS_API_BASE',         'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'auditor'      => ['label' => 'Auditor',      'owns' => 'Audit engagements and working papers',                'production' => 'https://auditor.aicountly.com',      'sandbox' => 'https://auditor.gh.aicountly.com',      'env' => 'AUDITOR_API_BASE',      'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'fr'           => ['label' => 'Financial Reporting', 'owns' => 'Statutory financial statements',               'production' => 'https://fr.aicountly.com',           'sandbox' => 'https://fr.gh.aicountly.com',           'env' => 'FR_API_BASE',           'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'secretarial'  => ['label' => 'Secretarial',  'owns' => 'Company-secretarial records and filings',             'production' => 'https://secretarial.aicountly.com',  'sandbox' => 'https://secretarial.gh.aicountly.com',  'env' => 'SECRETARIAL_API_BASE',  'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'vault'        => ['label' => 'Vault',        'owns' => 'Secure document storage',                             'production' => 'https://vault.aicountly.com',        'sandbox' => 'https://vault.gh.aicountly.com',        'env' => 'VAULT_API_BASE',        'entitlement' => 'business', 'timeout' => 'optional', 'capabilities' => []],
        'voice'        => ['label' => 'Voice',        'owns' => 'Speech synthesis and transcription',                  'production' => 'https://voice.aicountly.com',        'sandbox' => 'https://voice.gh.aicountly.com',        'env' => 'VOICE_API_BASE',        'entitlement' => 'any',      'timeout' => 'required', 'capabilities' => [
            'briefing.speak' => ['verified' => false, 'operation' => 'POST /api/v1/speech', 'writes' => false],
        ]],
    ];

    /** Is a base URL configured, or derivable from the deployment's own hostname? */
    public static function baseUrl(string $service): ?string
    {
        $descriptor = self::SERVICES[$service] ?? null;
        if ($descriptor === null) {
            return null;
        }

        $configured = Env::get((string) $descriptor['env']);
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        // Derived from our own hostname so sandbox talks to sandbox without a
        // second set of variables. An explicit override always wins.
        if (Env::get('INTEGRATIONS_DERIVE_HOSTS', '1') !== '1') {
            return null;
        }

        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

        return str_contains($host, '.gh.aicountly.com')
            ? (string) $descriptor['sandbox']
            : (string) $descriptor['production'];
    }

    public static function isConfigured(string $service): bool
    {
        return self::baseUrl($service) !== null;
    }

    /** @return array<string, mixed>|null */
    public static function capability(string $service, string $capability): ?array
    {
        return self::SERVICES[$service]['capabilities'][$capability] ?? null;
    }

    /** Only a capability read out of that product's own contract may be offered. */
    public static function isVerified(string $service, string $capability): bool
    {
        return (bool) (self::capability($service, $capability)['verified'] ?? false);
    }

    /**
     * The whole registry, as the UI shows it.
     *
     * NOTHING IS PROBED HERE. Each AICOUNTLY product runs a small PHP-FPM pool,
     * and a screen that opens twenty synchronous HTTP calls to render a settings
     * panel is how a pool deadlocks. The state comes from the last recorded
     * probe; `POST /api/v1/integrations/{service}/check` runs one on demand.
     *
     * @return list<array<string, mixed>>
     */
    public static function describeAll(Auth $auth, Account $account): array
    {
        $health = [];
        try {
            foreach (Db::all('SELECT * FROM ' . self::HEALTH_TABLE) as $row) {
                $health[(string) $row['service']] = $row;
            }
        } catch (\Throwable $e) {
            error_log('[email-integrations] health table unavailable: ' . $e->getMessage());
        }

        $out = [];
        foreach (self::SERVICES as $service => $descriptor) {
            $out[] = self::describe($service, $descriptor, $account, $health[$service] ?? null);
        }

        return $out;
    }

    /**
     * @param array<string, mixed>      $descriptor
     * @param array<string, mixed>|null $health
     * @return array<string, mixed>
     */
    private static function describe(string $service, array $descriptor, Account $account, ?array $health): array
    {
        $configured = self::isConfigured($service);
        $entitled = $descriptor['entitlement'] !== 'business' || $account->isBusiness();

        $capabilities = [];
        $anyVerified = false;
        foreach ((array) $descriptor['capabilities'] as $key => $capability) {
            $verified = (bool) $capability['verified'];
            $anyVerified = $anyVerified || $verified;
            $capabilities[] = [
                'key'       => $key,
                'operation' => $capability['operation'],
                'writes'    => (bool) $capability['writes'],
                'available' => $verified && $configured && $entitled,
                'reason'    => $verified ? null : 'Email has no agreed contract for this operation yet, so it is not offered.',
            ];
        }

        $state = match (true) {
            !$entitled   => self::PERMISSION_REQUIRED,
            !$configured => self::NOT_CONFIGURED,
            $descriptor['capabilities'] === [] || !$anyVerified => self::UNSUPPORTED,
            $health === null => self::NOT_CONNECTED,
            (string) $health['state'] === self::CONNECTED => self::CONNECTED,
            default => (string) $health['state'],
        };

        return [
            'service'        => $service,
            'label'          => $descriptor['label'],
            'owns'           => $descriptor['owns'],
            'api_base_url'   => self::baseUrl($service),
            'entitlement'    => $descriptor['entitlement'],
            'entitled'       => $entitled,
            'timeout_policy' => $descriptor['timeout'] === 'required' ? 'connect 3s / total 20s' : 'connect 2s / total 6s',
            'state'          => $state,
            'state_reason'   => self::reasonFor($state, $descriptor),
            'capabilities'   => $capabilities,
            'last_checked_at' => $health['last_checked_at'] ?? null,
        ];
    }

    /** @param array<string, mixed> $descriptor */
    private static function reasonFor(string $state, array $descriptor): ?string
    {
        return match ($state) {
            self::CONNECTED          => null,
            self::NOT_CONFIGURED     => 'No API base URL is configured for ' . $descriptor['label'] . ' on this deployment (' . $descriptor['env'] . ').',
            self::PERMISSION_REQUIRED => $descriptor['label'] . ' is part of the business experience and this account does not have it.',
            self::UNSUPPORTED        => 'Email has no verified contract with ' . $descriptor['label'] . ' yet. The adapter is in place; the operations are not offered.',
            self::TEMPORARILY_UNAVAILABLE => $descriptor['label'] . ' did not answer in time when it was last checked.',
            default                  => $descriptor['label'] . ' has not answered a health check from Email yet.',
        };
    }

    /** Record what a probe found. Status only — no response body is stored. */
    public static function recordHealth(string $service, string $state, ?int $status, ?string $detail): void
    {
        try {
            Db::run(
                'INSERT INTO ' . self::HEALTH_TABLE . ' (service, state, last_status, last_checked_at, detail)
                 VALUES (:service, :state, :status, :now, :detail)
                 ON CONFLICT (service) DO UPDATE
                 SET state = EXCLUDED.state, last_status = EXCLUDED.last_status,
                     last_checked_at = EXCLUDED.last_checked_at, detail = EXCLUDED.detail',
                [
                    'service' => $service,
                    'state'   => $state,
                    'status'  => $status,
                    'now'     => gmdate('Y-m-d H:i:s'),
                    'detail'  => $detail === null ? null : mb_substr($detail, 0, 240),
                ],
            );
        } catch (\Throwable $e) {
            error_log('[email-integrations] could not record health for ' . $service . ': ' . $e->getMessage());
        }
    }
}
