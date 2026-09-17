<?php

declare(strict_types=1);

namespace Aicountly\Api\Actions;

use Aicountly\Api\Integrations\Registry;

/**
 * The allowlist of things Email may ask another product to do.
 *
 * AN ACTION THAT IS NOT IN THIS TABLE CANNOT BE PREVIEWED, LET ALONE EXECUTED.
 * The model never names an operation — it can suggest that the user might want
 * to create a calendar event, and the code then looks the operation up here. A
 * model that emits `{"operation": "DELETE /api/v1/companies/3"}` gets a 404
 * from the catalogue, which is the point of having one.
 *
 * `reversible` is shown to the user in the preview, and it is honest: creating
 * a calendar event is reversible because Calendar can cancel it; asking Pay for
 * a payment link is not, because a link that has been issued has been issued.
 */
final class ActionCatalog
{
    /**
     * @var array<string, array<string, mixed>>
     */
    public const ACTIONS = [
        'calendar.create_event' => [
            'label'       => 'Create a calendar event',
            'service'     => 'calendar',
            'capability'  => 'event.create',
            'summary'     => 'Calendar will create an event. Email keeps only its reference.',
            'reversible'  => true,
            'reverse_note' => 'The event can be cancelled in Calendar afterwards.',
            'permissions' => ['A Calendar account for this user', 'Permission to write to the chosen calendar'],
            'entitlement' => 'any',
            'fields'      => ['title', 'starts_at', 'ends_at', 'timezone', 'attendees', 'notes'],
        ],
        'drive.save_attachment' => [
            'label'       => 'Save an attachment to Drive',
            'service'     => 'drive',
            'capability'  => 'file.save',
            'summary'     => 'Drive will store a copy of this attachment in the shared drive.',
            'reversible'  => true,
            'reverse_note' => 'The file can be deleted in Drive afterwards.',
            'permissions' => ['Write access to the destination folder in Drive'],
            'entitlement' => 'any',
            'fields'      => ['filename', 'folder_id', 'source_message_id'],
        ],
        'contacts.link_contact' => [
            'label'       => 'Link this sender to a contact',
            'service'     => 'contacts',
            'capability'  => 'contact.link',
            'summary'     => 'Contacts will record that this email address belongs to an existing contact.',
            'reversible'  => true,
            'reverse_note' => 'The link can be removed in Contacts.',
            'permissions' => ['Permission to edit the contact'],
            'entitlement' => 'any',
            'fields'      => ['contact_id', 'email_address'],
        ],
        'helpdesk.create_ticket' => [
            'label'       => 'Create a Helpdesk ticket',
            'service'     => 'helpdesk',
            'capability'  => 'ticket.create',
            'summary'     => 'Helpdesk will open a ticket from this thread.',
            'reversible'  => false,
            'reverse_note' => 'A ticket can be closed but not un-created.',
            'permissions' => ['Permission to create tickets in the chosen queue'],
            'entitlement' => 'business',
            'fields'      => ['subject', 'requester', 'priority', 'thread_reference'],
        ],
        'purchases.open_order' => [
            'label'       => 'Open the purchase order in Purchase',
            'service'     => 'purchases',
            'capability'  => 'purchase_order.read',
            'summary'     => 'Reads the live purchase order so it can be compared with this email. Changes nothing.',
            'reversible'  => true,
            'reverse_note' => 'Nothing is written.',
            'permissions' => ['Access to the company in Manage', 'Read access to purchase orders'],
            'entitlement' => 'business',
            'fields'      => ['purchase_order_id'],
        ],
        'sales.prepare_response' => [
            'label'       => 'Prepare a sales response',
            'service'     => 'sales',
            'capability'  => 'quotation.create',
            'summary'     => 'Sales will draft a quotation from this enquiry.',
            'reversible'  => true,
            'reverse_note' => 'A draft quotation can be discarded in Sales.',
            'permissions' => ['Access to the company in Manage', 'Permission to create quotations'],
            'entitlement' => 'business',
            'fields'      => ['customer_id', 'items', 'notes'],
        ],
        'pay.request_payment_link' => [
            'label'       => 'Ask Pay for a payment link',
            'service'     => 'pay',
            'capability'  => 'payment_link.request',
            // Stated on the preview itself, not only in the docs.
            'summary'     => 'Pay will create a payment link. Pay applies its own approvals; Email never moves money and never initiates a transfer.',
            'reversible'  => false,
            'reverse_note' => 'A link that has been issued cannot be withdrawn from Email.',
            'permissions' => ['Access to the company in Manage', 'Permission in Pay to request payment links'],
            'entitlement' => 'business',
            'fields'      => ['amount', 'currency', 'reference', 'payer_contact_id'],
        ],
        'connect.start_conversation' => [
            'label'       => 'Start a Connect conversation',
            'service'     => 'connect',
            'capability'  => 'conversation.create',
            'summary'     => 'Connect will open a conversation with this person.',
            'reversible'  => true,
            'reverse_note' => 'The conversation can be archived in Connect.',
            'permissions' => ['A Connect account for this user'],
            'entitlement' => 'any',
            'fields'      => ['participant', 'opening_message'],
        ],
        'lobby.open_visitor_context' => [
            'label'       => 'Open the visitor context in Lobby',
            'service'     => 'lobby',
            'capability'  => 'visit.read',
            'summary'     => 'Reads the visit this email refers to. Changes nothing.',
            'reversible'  => true,
            'reverse_note' => 'Nothing is written.',
            'permissions' => ['Access to the company in Manage', 'Read access in Lobby'],
            'entitlement' => 'business',
            'fields'      => ['visit_id'],
        ],
    ];

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        return self::ACTIONS[$key] ?? null;
    }

    /**
     * Actions that can actually be offered right now.
     *
     * An action whose capability is unverified in the Registry is listed with
     * `available: false` and the reason, so the UI can show a disabled button
     * with an explanation rather than hiding the feature and leaving the user
     * to wonder.
     *
     * @return list<array<string, mixed>>
     */
    public static function availableFor(bool $isBusiness): array
    {
        $out = [];
        foreach (self::ACTIONS as $key => $action) {
            $entitled = $action['entitlement'] !== 'business' || $isBusiness;
            $configured = Registry::isConfigured((string) $action['service']);
            $verified = Registry::isVerified((string) $action['service'], (string) $action['capability']);

            $out[] = [
                'key'          => $key,
                'label'        => $action['label'],
                'service'      => $action['service'],
                'summary'      => $action['summary'],
                'reversible'   => $action['reversible'],
                'permissions'  => $action['permissions'],
                'available'    => $entitled && $configured && $verified,
                'reason'       => match (true) {
                    !$entitled   => 'This action is part of the business experience and this account does not have it.',
                    !$configured => 'No API base URL is configured for ' . $action['service'] . ' on this deployment.',
                    !$verified   => 'Email has no agreed contract with ' . $action['service'] . ' for this operation yet.',
                    default      => null,
                },
            ];
        }

        return $out;
    }
}
