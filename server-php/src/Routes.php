<?php

declare(strict_types=1);

namespace Aicountly\Api;

use Aicountly\Api\Controllers\AdminController;
use Aicountly\Api\Controllers\AuditController;
use Aicountly\Api\Controllers\DraftsController;
use Aicountly\Api\Controllers\IntegrationsController;
use Aicountly\Api\Controllers\MailboxesController;
use Aicountly\Api\Controllers\MessagesController;
use Aicountly\Api\Controllers\PulseController;
use Aicountly\Api\Controllers\SearchController;
use Aicountly\Api\Controllers\SessionController;
use Aicountly\Api\Controllers\SettingsController;
use Aicountly\Api\Controllers\ThreadsController;

/**
 * Every route this API serves.
 *
 * TWO CONVENTIONS, AND BOTH ARE DELIBERATE.
 *
 * Resources are nested under their mailbox — `/v1/mailboxes/{id}/threads/{key}`
 * rather than `/v1/threads/{key}`. A flat id makes it easy to write a handler
 * that trusts the id, and one such handler is a cross-tenant read. Nesting puts
 * the mailbox check on the path to every resource, and the base controller runs
 * it before the handler sees anything.
 *
 * GET NEVER WRITES. Every state change below is POST, PATCH, PUT or DELETE. A
 * GET that writes is a GET that a link preview, a prefetch or a crawler can
 * trigger.
 */
final class Routes
{
    public static function register(Router $router): void
    {
        // --- Session and entitlements --------------------------------------
        $router->get('v1/session', [new SessionController(), 'session']);
        $router->get('v1/capabilities', [new SessionController(), 'capabilities']);
        $router->get('v1/manage/companies', [new SessionController(), 'companies']);

        // --- Mailboxes ------------------------------------------------------
        $router->get('v1/mailboxes', [new MailboxesController(), 'index']);
        $router->get('v1/mailboxes/{id}/folders', [new MailboxesController(), 'folders']);
        $router->get('v1/mailboxes/{id}/threads', [new MailboxesController(), 'threads']);
        $router->get('v1/mailboxes/{id}/quota', [new MailboxesController(), 'quota']);
        $router->get('v1/mailboxes/{id}/search', [new SearchController(), 'search']);

        // --- Reading --------------------------------------------------------
        $router->get('v1/mailboxes/{id}/threads/{thread}', [new ThreadsController(), 'show']);
        $router->get('v1/mailboxes/{id}/messages/{uid}', [new ThreadsController(), 'message']);
        $router->get('v1/mailboxes/{id}/messages/{uid}/attachments/{part}', [new ThreadsController(), 'attachment']);

        // --- Changing a message ---------------------------------------------
        $router->patch('v1/mailboxes/{id}/messages/{uid}', [new MessagesController(), 'update']);
        $router->post('v1/mailboxes/{id}/messages/bulk', [new MessagesController(), 'bulk']);

        // --- Drafts and sending ---------------------------------------------
        $router->get('v1/mailboxes/{id}/drafts', [new DraftsController(), 'index']);
        $router->post('v1/mailboxes/{id}/drafts', [new DraftsController(), 'create']);
        $router->patch('v1/mailboxes/{id}/drafts/{draft}', [new DraftsController(), 'update']);
        $router->delete('v1/mailboxes/{id}/drafts/{draft}', [new DraftsController(), 'destroy']);
        $router->post('v1/mailboxes/{id}/drafts/{draft}/send', [new DraftsController(), 'send']);
        $router->post('v1/mailboxes/{id}/drafts/{draft}/schedule', [new DraftsController(), 'schedule']);
        $router->get('v1/mailboxes/{id}/sends/{job}', [new DraftsController(), 'sendStatus']);
        // Undo-send and unscheduling are the same operation: cancel a job that
        // has not left yet.
        $router->delete('v1/mailboxes/{id}/sends/{job}', [new DraftsController(), 'cancel']);

        // --- Pulse ------------------------------------------------------------
        $router->get('v1/mailboxes/{id}/pulse/briefing', [new PulseController(), 'briefing']);
        $router->post('v1/mailboxes/{id}/pulse/thread-analysis', [new PulseController(), 'analyseThread']);
        $router->post('v1/mailboxes/{id}/pulse/classification', [new PulseController(), 'correct']);
        $router->post('v1/mailboxes/{id}/pulse/dismiss', [new PulseController(), 'dismiss']);
        $router->post('v1/mailboxes/{id}/pulse/comparison', [new PulseController(), 'compare']);
        $router->get('v1/mailboxes/{id}/pulse/commitments', [new PulseController(), 'commitments']);
        $router->post('v1/mailboxes/{id}/pulse/commitments/{commitment}', [new PulseController(), 'confirmCommitment']);
        $router->post('v1/mailboxes/{id}/pulse/reply-draft', [new PulseController(), 'replyDraft']);

        // Understand -> Preview -> Approve -> Execute -> Receipt. There is no
        // route that previews and executes in one call.
        $router->post('v1/mailboxes/{id}/pulse/actions/preview', [new PulseController(), 'previewAction']);
        $router->post('v1/mailboxes/{id}/pulse/actions/{action}/approve', [new PulseController(), 'approveAction']);
        $router->get('v1/mailboxes/{id}/pulse/actions/{action}', [new PulseController(), 'showAction']);

        // --- Integrations, settings, audit ------------------------------------
        $router->get('v1/integrations', [new IntegrationsController(), 'index']);
        $router->post('v1/integrations/{service}/check', [new IntegrationsController(), 'check']);

        $router->get('v1/settings', [new SettingsController(), 'show']);
        $router->put('v1/settings', [new SettingsController(), 'update']);
        $router->get('v1/mailboxes/{id}/signatures', [new SettingsController(), 'signatures']);
        $router->post('v1/mailboxes/{id}/signatures', [new SettingsController(), 'saveSignature']);
        $router->get('v1/mailboxes/{id}/blocklist', [new SettingsController(), 'blocklist']);
        $router->post('v1/mailboxes/{id}/blocklist', [new SettingsController(), 'block']);
        $router->delete('v1/mailboxes/{id}/blocklist/{block}', [new SettingsController(), 'unblock']);

        $router->get('v1/audit', [new AuditController(), 'index']);

        // --- Administration (business entitlement, checked in the controller) --
        $router->get('v1/admin/mailboxes', [new AdminController(), 'mailboxes']);
        $router->post('v1/admin/mailboxes/{id}/members', [new AdminController(), 'grant']);
        $router->delete('v1/admin/mailboxes/{id}/members/{member}', [new AdminController(), 'revoke']);
        $router->get('v1/admin/domains', [new AdminController(), 'domains']);
        $router->post('v1/admin/domains', [new AdminController(), 'addDomain']);
        $router->post('v1/admin/domains/{domain}/verify', [new AdminController(), 'verifyDomain']);
    }
}
