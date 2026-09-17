<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Actions\ActionService;
use Aicountly\Api\Ai\AiClient;
use Aicountly\Api\Audit;
use Aicountly\Api\Clients\PurchaseClient;
use Aicountly\Api\Db;
use Aicountly\Api\Http;
use Aicountly\Api\Integrations\Registry;
use Aicountly\Api\Mail\HtmlSanitizer;
use Aicountly\Api\MailboxAccess;
use Aicountly\Api\Pulse\Classifier;
use Aicountly\Api\Pulse\Comparison;
use Aicountly\Api\Pulse\CommitmentExtractor;
use Aicountly\Api\Pulse\PulseService;
use Aicountly\Api\RateLimit;

/**
 * Pulse: briefing, thread analysis, the business comparison, commitments, the
 * reply assistant and the action pipeline.
 *
 * AI ORCHESTRATION HAPPENS HERE, ON THE SERVER, AND ONLY HERE. The browser
 * never holds a model key, never chooses what context goes into a prompt, and
 * never receives anything the model said that has not been through a schema.
 * Every endpoint below is rate-limited per account, every one degrades to a
 * rules answer when no model is configured, and none of them can cause a write
 * to another product — that needs an approval, and approvals go through
 * ActionService.
 */
final class PulseController extends Controller
{
    public function briefing(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);

        Http::data(PulseService::briefing(
            $this->auth(),
            $this->account(),
            (int) $resolved['mailbox']['mailbox_id'],
            $this->auth()->displayName(),
        ));
    }

    public function analyseThread(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $account = $this->account();

        $threadKey = trim((string) (Http::param('thread_key') ?? ''));
        if ($threadKey === '') {
            Http::validationFailed('A thread is required.', ['field' => 'thread_key']);
        }

        if (AiClient::isConfigured() && !$account->aiOptOut) {
            RateLimit::consume('ai', $account);
        }

        $result = PulseService::analyseThread(
            $this->auth(),
            $account,
            $resolved['mailbox'],
            (string) (Http::param('folder') ?? 'INBOX'),
            $threadKey,
        );

        if (!$result['ok']) {
            Http::error(503, 'mailbox_unavailable', (string) $result['error'], ['retryable' => true]);
        }

        Http::data($result);
    }

    /**
     * A user disagreeing with a classification.
     *
     * Kept, because it is the only signal that the classifier is wrong about
     * this kind of thread. It overrides the stored class everywhere the class
     * is read.
     */
    public function correct(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $threadKey = trim((string) (Http::param('thread_key') ?? ''));
        $to = (string) (Http::param('classification') ?? '');

        if ($threadKey === '') {
            Http::validationFailed('A thread is required.', ['field' => 'thread_key']);
        }
        if ($to !== Classifier::NONE && !isset(Classifier::CLASSES[$to])) {
            Http::validationFailed('Unknown classification.', ['field' => 'classification', 'allowed' => array_keys(Classifier::CLASSES)]);
        }

        Db::run(
            'UPDATE email_thread_insights SET corrected_to = :to, corrected_by = :by, updated_at = :now
              WHERE mailbox_id = :mailbox AND thread_key = :thread',
            [
                'to' => $to, 'by' => $this->auth()->uuid, 'now' => gmdate('Y-m-d H:i:s'),
                'mailbox' => (int) $resolved['mailbox']['mailbox_id'], 'thread' => $threadKey,
            ],
        );

        Audit::record($this->auth(), $this->account(), 'pulse.classification_corrected', 'thread', $threadKey, 'ok', ['to' => $to]);
        Http::data(['thread_key' => $threadKey, 'classification' => $to, 'corrected' => true]);
    }

    public function dismiss(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $threadKey = trim((string) (Http::param('thread_key') ?? ''));

        Db::run(
            'UPDATE email_thread_insights SET dismissed_at = :now WHERE mailbox_id = :mailbox AND thread_key = :thread',
            ['now' => gmdate('Y-m-d H:i:s'), 'mailbox' => (int) $resolved['mailbox']['mailbox_id'], 'thread' => $threadKey],
        );

        Http::data(['thread_key' => $threadKey, 'dismissed' => true]);
    }

    /**
     * Email vs business record.
     *
     * The record side is READ LIVE on this request and is not stored. The
     * arithmetic is Comparison's, which is ordinary code — no model is involved
     * in any subtraction or percentage. When the two sides are not stated on
     * the same basis the answer is "Comparison requires review", not a number.
     */
    public function compare(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $this->requireBusiness('business_comparison');
        $ctx = $this->requireContext();

        $service = strtolower((string) (Http::param('service') ?? 'purchases'));
        $recordId = trim((string) (Http::param('record_id') ?? ''));
        $emailSide = Http::body()['email'] ?? [];

        if ($recordId === '' || !is_array($emailSide) || $emailSide === []) {
            Http::validationFailed('A record id and the figures read from the email are both required.', [
                'fields' => ['record_id', 'email'],
            ]);
        }

        if ($service !== 'purchases') {
            Http::error(501, 'integration_unsupported',
                'Email can compare against Purchase today. A verified contract with ' . $service . ' has not been agreed yet.',
                ['service' => $service, 'retryable' => false]);
        }
        if (!Registry::isConfigured('purchases')) {
            Http::notConfigured('No API base URL is configured for Purchase on this deployment.', ['service' => 'purchases']);
        }

        $fetchedAt = gmdate('c');
        $result = (new PurchaseClient())->withSession($this->auth()->sesKey())->purchaseOrder($ctx, $recordId);

        if (!$result['ok']) {
            Registry::recordHealth('purchases', $result['status'] === 0 ? Registry::TEMPORARILY_UNAVAILABLE : Registry::NOT_CONNECTED, $result['status'] ?: null, null);
            Http::error(503, 'integration_unavailable',
                $result['status'] === 0
                    ? 'Purchase did not answer, so this comparison cannot be made right now.'
                    : 'Purchase refused the read (' . $result['status'] . ').',
                ['service' => 'purchases', 'retryable' => $result['status'] === 0]);
        }

        Registry::recordHealth('purchases', Registry::CONNECTED, $result['status'], null);
        $record = $result['body']['data'] ?? [];

        $comparison = Comparison::compare(
            $this->normaliseSide($emailSide),
            $this->normaliseSide($record),
            [
                'record' => [
                    'service'    => 'purchases',
                    'id'         => $recordId,
                    // The time it was read, shown on screen. A comparison with no
                    // retrieval time is a comparison against something unknown.
                    'fetched_at' => $fetchedAt,
                    'cmp_id'     => $ctx->cmpId,
                ],
                'email'  => [
                    'message_id' => Http::param('message_id'),
                    'uid'        => Http::param('uid'),
                ],
            ],
        );

        Audit::record($this->auth(), $this->account(), 'pulse.comparison', 'purchase_order', $recordId, 'ok', [
            'comparable'  => $comparison['comparable'],
            'differences' => count($comparison['differences']),
        ], $ctx->cmpId);

        Http::json(200, [
            'data' => $comparison,
            // Nothing from the fetched record is persisted; reopening this
            // screen reads Purchase again.
            'meta' => ['persisted' => false, 'note' => 'The business record is read live and is not stored by Email. Reopening this comparison reads it again.'],
        ]);
    }

    /** The commitment radar for one mailbox. */
    public function commitments(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId);

        $rows = Db::all(
            'SELECT * FROM email_commitments WHERE mailbox_id = :mailbox ORDER BY proposed_date NULLS LAST, commitment_id DESC LIMIT 200',
            ['mailbox' => (int) $resolved['mailbox']['mailbox_id']],
        );

        Http::json(200, [
            'data' => array_map([PulseService::class, 'presentCommitment'], $rows),
            'meta' => ['note' => 'A proposal stays a proposal until you confirm it. Nothing here is agreed on your behalf.'],
        ]);
    }

    /**
     * Confirm a commitment.
     *
     * THE ONLY PATH from `supplier_proposal` to `user_confirmed`, and it needs a
     * person: a service caller is refused outright.
     */
    public function confirmCommitment(string $mailboxId, string $commitmentId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);

        if ($this->auth()->isService()) {
            Http::forbidden('A service caller cannot accept a commitment on a person\'s behalf.');
        }

        $row = Db::first(
            'SELECT * FROM email_commitments WHERE commitment_id = :id AND mailbox_id = :mailbox',
            ['id' => (int) $commitmentId, 'mailbox' => (int) $resolved['mailbox']['mailbox_id']],
        );
        if ($row === null) {
            Http::notFound('That commitment does not exist in this mailbox.');
        }

        $target = (string) (Http::param('state') ?? CommitmentExtractor::STATE_CONFIRMED);
        $allowed = [CommitmentExtractor::STATE_CONFIRMED, 'completed', 'disputed', 'cancelled'];
        if (!in_array($target, $allowed, true)) {
            Http::validationFailed('Unknown commitment state.', ['field' => 'state', 'allowed' => $allowed]);
        }

        Db::update('email_commitments', [
            'state'        => $target,
            'confirmed_by' => $this->auth()->uuid,
            'confirmed_at' => gmdate('Y-m-d H:i:s'),
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ], ['commitment_id' => (int) $commitmentId]);

        Audit::record($this->auth(), $this->account(), 'commitment.' . $target, 'commitment', (int) $commitmentId, 'ok', [
            'from' => $row['state'],
        ]);

        $fresh = Db::first('SELECT * FROM email_commitments WHERE commitment_id = :id', ['id' => (int) $commitmentId]) ?? $row;
        Http::data(PulseService::presentCommitment($fresh));
    }

    /**
     * Draft, shorten, rewrite, translate or explain.
     *
     * Whatever comes back is a DRAFT. It is written to email_drafts with
     * `origin = ai_suggested` and it is sent only when a person presses Send —
     * there is no endpoint that generates and sends in one step, deliberately.
     */
    public function replyDraft(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $account = $this->account();

        $status = AiClient::status();
        if (!$status['available'] || $account->aiOptOut) {
            Http::notConfigured(
                $account->aiOptOut ? 'Pulse AI is turned off for this account.' : (string) $status['reason'],
                ['admin_hint' => $status['admin_hint'] ?? null, 'subsystem' => 'ai'],
            );
        }

        RateLimit::consume('ai', $account);

        $mode = (string) (Http::param('mode') ?? 'draft');
        $allowed = ['draft', 'shorten', 'rewrite', 'translate', 'explain', 'missing_information', 'check_claims'];
        if (!in_array($mode, $allowed, true)) {
            Http::validationFailed('Unknown reply mode.', ['field' => 'mode', 'allowed' => $allowed]);
        }

        $threadKey = trim((string) (Http::param('thread_key') ?? ''));
        $identity = $this->identity($resolved['mailbox']);
        $thread = $this->store()->thread($identity, (string) (Http::param('folder') ?? 'INBOX'), $threadKey);
        $this->assertStoreOk($thread);

        $grounding = [];
        foreach (array_slice($thread['messages'], -5) as $message) {
            $grounding[] = [
                'from'    => $message['from'][0]['address'] ?? '',
                'date'    => $message['date'] ?? '',
                'subject' => $message['subject'] ?? '',
                'body'    => mb_substr(HtmlSanitizer::snippet((string) ($message['html'] ?? ''), (string) ($message['text'] ?? ''), 4000), 0, 4000),
            ];
        }

        $tone = preg_replace('/[^a-z ]/i', '', (string) (Http::param('tone') ?? 'professional')) ?: 'professional';
        $existing = (string) (Http::param('current_text') ?? '');

        $result = AiClient::narrate(
            self::taskFor($mode, $tone),
            ['thread' => $grounding, 'current_draft' => mb_substr($existing, 0, 4000)],
            "Never state a price, quantity, stock level, payment status, delivery guarantee or agreement\n"
            . "that is not present in the thread. If the reply needs one you do not have, write a\n"
            . "placeholder in square brackets and list it under what is missing.\n"
            . "Do not promise anything on the sender's behalf.",
        );

        if (!$result['ok']) {
            Http::error(503, 'ai_unavailable', (string) $result['error'], ['retryable' => true, 'subsystem' => 'ai']);
        }

        Audit::record($this->auth(), $account, 'pulse.reply_draft', 'thread', $threadKey, 'ok', ['mode' => $mode]);

        Http::json(200, [
            'data' => [
                'mode'       => $mode,
                'tone'       => $tone,
                'text'       => $result['text'],
                // Stated on the payload itself, so no screen can forget it.
                'is_draft'   => true,
                'origin'     => 'ai_suggested',
                'disclaimer' => 'This is a draft. It is not sent until you send it, and nothing in it is verified.',
                'sources'    => array_map(static fn (array $m) => ['from' => $m['from'], 'date' => $m['date']], $grounding),
            ],
            'meta' => ['thread_key' => $threadKey],
        ]);
    }

    // --- Actions -----------------------------------------------------------

    public function previewAction(string $mailboxId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        $account = $this->account();
        RateLimit::consume('action', $account);

        $proposal = Http::body()['proposal'] ?? [];

        Http::data(ActionService::preview(
            $this->auth(),
            $account,
            (int) $resolved['mailbox']['mailbox_id'],
            (string) (Http::param('thread_key') ?? ''),
            (string) (Http::param('action') ?? ''),
            is_array($proposal) ? $proposal : [],
            $this->context(),
        ));
    }

    public function approveAction(string $mailboxId, string $actionId): void
    {
        $resolved = $this->mailbox($mailboxId, MailboxAccess::SEND_ON_BEHALF);
        RateLimit::consume('action', $this->account());

        Http::data(ActionService::approveAndExecute(
            $this->auth(),
            $this->account(),
            (int) $resolved['mailbox']['mailbox_id'],
            (int) $actionId,
            (string) (Http::param('preview_digest') ?? ''),
            $this->context(),
        ));
    }

    public function showAction(string $mailboxId, string $actionId): void
    {
        $resolved = $this->mailbox($mailboxId);

        Http::data(ActionService::show((int) $resolved['mailbox']['mailbox_id'], (int) $actionId));
    }

    // -----------------------------------------------------------------------

    /**
     * Only the fields a comparison is allowed to look at, normalised.
     *
     * A whitelist rather than the whole record: it keeps an unexpected field
     * from silently becoming part of the arithmetic, and it is the reason
     * nothing else from the fetched record travels any further than this
     * function.
     *
     * @param array<string, mixed> $side
     * @return array<string, mixed>
     */
    private function normaliseSide(array $side): array
    {
        $out = [];
        foreach (['currency', 'uom', 'quantity', 'tax_basis', 'discount_basis', 'freight_basis',
                  'unit_price', 'line_total', 'tax_amount', 'freight', 'grand_total',
                  'delivery_date', 'due_date'] as $field) {
            if (array_key_exists($field, $side) && is_scalar($side[$field])) {
                $out[$field] = $side[$field];
            }
        }

        return $out;
    }

    private static function taskFor(string $mode, string $tone): string
    {
        return match ($mode) {
            'shorten'             => 'Shorten the current draft, keeping every commitment and figure exactly as written.',
            'rewrite'             => 'Rewrite the current draft in a ' . $tone . ' tone. Do not add or remove any fact.',
            'translate'           => 'Translate the current draft into the language named in the draft itself, or into English if none is named.',
            'explain'             => 'Explain what this thread is about and what it is asking of the reader.',
            'missing_information' => 'List what the reader would need to know before replying that this thread does not say.',
            'check_claims'        => 'List the factual claims this thread makes and say, for each, whether the thread itself supports it.',
            default               => 'Write a ' . $tone . ' reply to the most recent message in this thread.',
        };
    }
}
