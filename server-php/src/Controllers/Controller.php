<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Account;
use Aicountly\Api\Auth;
use Aicountly\Api\Context;
use Aicountly\Api\Http;
use Aicountly\Api\MailboxAccess;
use Aicountly\Api\Mail\MailAdapters;
use Aicountly\Api\Mail\MailboxIdentity;
use Aicountly\Api\Mail\MailStore;

/**
 * What every controller needs, resolved once and in one order.
 *
 * The order is the security property: identity, then account, then mailbox.
 * Skipping straight to the mailbox is how a route ends up trusting an id from
 * the URL.
 */
abstract class Controller
{
    private ?Auth $auth = null;
    private ?Account $account = null;

    protected function auth(): Auth
    {
        return $this->auth ??= Auth::require();
    }

    protected function account(): Account
    {
        return $this->account ??= Account::ensure($this->auth());
    }

    /**
     * The mailbox in the URL, checked.
     *
     * @return array{mailbox: array<string, mixed>, grant: array<string, mixed>}
     */
    protected function mailbox(string $mailboxId, string $permission = MailboxAccess::READ): array
    {
        if (!ctype_digit($mailboxId)) {
            Http::notFound('That mailbox does not exist, or you do not have access to it.');
        }

        return MailboxAccess::require($this->auth(), $this->account(), (int) $mailboxId, $permission);
    }

    /** The company scope, verified against Manage, or null when the request carries none. */
    protected function context(): ?Context
    {
        $ctx = Context::optional();
        if ($ctx !== null) {
            $ctx->assertAllowed($this->auth());
        }

        return $ctx;
    }

    /** The company scope, required and verified. */
    protected function requireContext(): Context
    {
        $ctx = Context::require();
        $ctx->assertAllowed($this->auth());

        return $ctx;
    }

    protected function requireBusiness(string $feature): void
    {
        if (!$this->account()->can($feature)) {
            Http::forbidden('This is part of the business experience and this account does not have it.');
        }
    }

    protected function store(): MailStore
    {
        return MailAdapters::store();
    }

    /**
     * Mail-store credentials for a mailbox, or an honest 503.
     *
     * @param array<string, mixed> $mailbox
     */
    protected function identity(array $mailbox): MailboxIdentity
    {
        if (!$this->store()->isConfigured()) {
            $describe = $this->store()->describe();
            Http::notConfigured(
                (string) ($describe['reason'] ?? 'No mail store is configured for this deployment.'),
                ['admin_hint' => $describe['admin_hint'] ?? null, 'subsystem' => 'mail_store'],
            );
        }

        $identity = MailboxIdentity::forMailbox($mailbox);
        if ($identity === null) {
            Http::notConfigured(
                'This mailbox has no usable mail-store credentials on this deployment.',
                ['admin_hint' => 'Set MAIL_IMAP_MASTER_USER / MAIL_IMAP_MASTER_PASSWORD, or store a per-mailbox credential.',
                 'subsystem' => 'mailbox_credentials'],
            );
        }

        return $identity;
    }

    /**
     * Turn a mail-store failure into the right HTTP answer.
     *
     * A store that is configured but unreachable is 503 and retryable; the UI
     * shows "Mailbox unavailable — retry", never an empty inbox.
     *
     * @param array{ok:bool, error:?string} $result
     */
    protected function assertStoreOk(array $result): void
    {
        if ($result['ok']) {
            return;
        }

        Http::error(503, 'mailbox_unavailable', (string) ($result['error'] ?? 'The mailbox could not be reached.'), [
            'retryable' => true,
            'subsystem' => 'mail_store',
        ]);
    }
}
