<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Clients\ManageClient;
use Aicountly\Api\Http;
use Aicountly\Api\Mail\MailAdapters;
use Aicountly\Api\MailboxAccess;
use Aicountly\Api\RateLimit;

/**
 * Who is signed in, and what this account may actually do.
 *
 * `/capabilities` is the single source the frontend gates on. The hostname
 * chooses a presentation; this chooses the privileges, and the two are not
 * allowed to disagree — a business user on aicountly.io gets the personal
 * layout and keeps every business capability, and a personal user on
 * email.aicountly.com gets the business layout with the business capabilities
 * absent and the reason given.
 */
final class SessionController extends Controller
{
    public function session(): void
    {
        $auth = $this->auth();
        $account = $this->account();

        Http::data([
            'authenticated' => true,
            'uuid'          => $auth->uuid,
            'display_name'  => $auth->displayName(),
            'portal_email'  => $auth->portalEmail(),
            'account'       => $account->toArray(),
        ]);
    }

    public function capabilities(): void
    {
        $auth = $this->auth();
        $account = $this->account();

        $mailboxes = MailboxAccess::listFor($auth, $account);
        $mail = MailAdapters::status();
        $ai = AiClient::status();

        $features = [];
        foreach ([
            'shared_mailboxes'    => 'Shared mailboxes',
            'delegation'          => 'Delegated access',
            'team_assignment'     => 'Team assignment',
            'business_comparison' => 'Email vs business record',
            'product_actions'     => 'Aicountly product actions',
            'custom_domains'      => 'Custom domain onboarding',
            'administration'      => 'Administration',
        ] as $key => $label) {
            $features[$key] = [
                'label'   => $label,
                'enabled' => $account->can($key),
                'reason'  => $account->can($key) ? null : 'Part of the business experience; this account does not have it.',
            ];
        }

        $features['ai'] = [
            'label'   => 'Pulse AI',
            // Both conditions, reported separately, because "you turned it off"
            // and "nobody configured it" need different words on screen.
            'enabled' => $ai['available'] && !$account->aiOptOut,
            'reason'  => match (true) {
                $account->aiOptOut     => 'You have turned Pulse AI off for this account.',
                !$ai['available']      => $ai['reason'],
                default                => null,
            },
        ];

        $features['voice_briefing'] = [
            'label'   => 'Listen to the briefing',
            'enabled' => false,
            'reason'  => 'No voice service is configured for this deployment, so the briefing can be read but not played.',
        ];

        Http::data([
            'account'   => $account->toArray(),
            'features'  => $features,
            'mailboxes' => array_map(static fn (array $m) => [
                'mailbox_id'  => (int) $m['mailbox_id'],
                'address'     => $m['address'],
                'display_name' => $m['display_name'],
                'kind'        => $m['kind'],
                'cmp_id'      => $m['cmp_id'] === null ? null : (int) $m['cmp_id'],
                'role'        => $m['member_role'] ?? 'owner',
            ], $mailboxes),
            'mail'      => $mail,
            'ai'        => $ai + ['opted_out' => $account->aiOptOut],
            // Shown in Settings so a user can see what they have used, rather
            // than discovering a limit by hitting it.
            'usage'     => RateLimit::summary($account),
            'navigation' => self::navigation($account->isBusiness()),
        ]);
    }

    /**
     * Companies this session may open.
     *
     * Read live from Manage on this request. Email keeps no company list: a
     * cached one is a list that still shows a company after somebody's access
     * was removed.
     */
    public function companies(): void
    {
        $auth = $this->auth();
        $this->requireBusiness('shared_mailboxes');

        $result = (new ManageClient())->withSession($auth->sesKey())->companies(['limit' => 100]);

        if (!$result['ok']) {
            Http::error(503, 'context_unavailable', 'Manage did not answer, so the company list cannot be shown right now.', [
                'retryable' => true,
                'service'   => 'manage',
            ]);
        }

        $body = $result['body'] ?? [];
        Http::json(200, [
            'data' => $body['data'] ?? $body,
            'meta' => [
                'source'     => 'manage',
                'fetched_at' => gmdate('c'),
            ],
        ]);
    }

    /**
     * The navigation each experience shows.
     *
     * Served by the backend rather than hard-coded in the bundle so that the
     * two experiences cannot drift from what the account is entitled to, and so
     * that a business item never appears in the personal layout by accident.
     *
     * @return array<string, mixed>
     */
    private static function navigation(bool $isBusiness): array
    {
        $business = [
            ['key' => 'pulse',     'label' => 'Pulse',            'role' => 'pulse'],
            ['key' => 'inbox',     'label' => 'Inbox',            'role' => 'inbox'],
            ['key' => 'decisions', 'label' => 'Needs decision',   'role' => 'virtual'],
            ['key' => 'promises',  'label' => 'Promises',         'role' => 'virtual'],
            ['key' => 'waiting',   'label' => 'Waiting on others', 'role' => 'virtual'],
            ['key' => 'sent',      'label' => 'Sent',             'role' => 'sent'],
            ['key' => 'drafts',    'label' => 'Drafts',           'role' => 'drafts'],
            ['key' => 'scheduled', 'label' => 'Scheduled',        'role' => 'virtual'],
            ['key' => 'documents', 'label' => 'Documents',        'role' => 'virtual'],
            ['key' => 'shared',    'label' => 'Shared mailboxes', 'role' => 'virtual'],
            ['key' => 'labels',    'label' => 'Labels',           'role' => 'labels'],
            ['key' => 'spam',      'label' => 'Spam',             'role' => 'spam'],
            ['key' => 'trash',     'label' => 'Trash',            'role' => 'trash'],
            ['key' => 'settings',  'label' => 'Settings',         'role' => 'settings'],
        ];

        $personal = [
            ['key' => 'pulse',     'label' => 'Pulse',     'role' => 'pulse'],
            ['key' => 'inbox',     'label' => 'Inbox',     'role' => 'inbox'],
            ['key' => 'starred',   'label' => 'Starred',   'role' => 'virtual'],
            ['key' => 'reminders', 'label' => 'Reminders', 'role' => 'virtual'],
            ['key' => 'sent',      'label' => 'Sent',      'role' => 'sent'],
            ['key' => 'drafts',    'label' => 'Drafts',    'role' => 'drafts'],
            ['key' => 'scheduled', 'label' => 'Scheduled', 'role' => 'virtual'],
            ['key' => 'labels',    'label' => 'Labels',    'role' => 'labels'],
            ['key' => 'spam',      'label' => 'Spam',      'role' => 'spam'],
            ['key' => 'trash',     'label' => 'Trash',     'role' => 'trash'],
            ['key' => 'settings',  'label' => 'Settings',  'role' => 'settings'],
        ];

        return [
            'business' => $business,
            'personal' => $personal,
            // The entitlement, not the hostname, decides which one is right for
            // this account; the frontend renders the other one's LAYOUT when the
            // hostname asks for it, and still uses these items.
            'entitled' => $isBusiness ? 'business' : 'personal',
            'administration' => $isBusiness,
        ];
    }
}
