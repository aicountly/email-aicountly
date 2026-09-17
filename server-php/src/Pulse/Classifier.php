<?php

declare(strict_types=1);

namespace Aicountly\Api\Pulse;

use Aicountly\Api\Ai\AiClient;

/**
 * Why a thread is in the decision inbox.
 *
 * RULES FIRST, MODEL SECOND. The rules below are deterministic, explainable and
 * free, and they carry the product when no model is configured. The model is
 * asked only to refine a thread the rules found ambiguous, and its answer is
 * constrained to the same fixed list of classes — it cannot invent one.
 *
 * Every classification carries:
 *   - the class,
 *   - the reason, in words the user can argue with,
 *   - the message it came from,
 *   - the deadline, or the explicit statement that none was found,
 *   - whether a model was involved.
 *
 * "No deadline found" is a first-class answer. A product that guesses a due
 * date is worse than one that admits it does not have one.
 */
final class Classifier
{
    public const NONE               = 'none';
    public const APPROVAL_REQUESTED = 'approval_requested';
    public const RESPONSE_REQUIRED  = 'response_required';
    public const PRICE_DISCREPANCY  = 'price_discrepancy';
    public const DELIVERY_CHANGE    = 'delivery_change';
    public const PAYMENT_FOLLOW_UP  = 'payment_follow_up';
    public const MEETING_REQUEST    = 'meeting_request';

    public const CLASSES = [
        self::APPROVAL_REQUESTED => 'Approval requested',
        self::RESPONSE_REQUIRED  => 'Response required',
        self::PRICE_DISCREPANCY  => 'Price discrepancy',
        self::DELIVERY_CHANGE    => 'Delivery change',
        self::PAYMENT_FOLLOW_UP  => 'Payment follow-up',
        self::MEETING_REQUEST    => 'Meeting request',
    ];

    /**
     * Ordered: the first pattern that matches wins, so the more specific
     * classes are listed before the catch-all "response required".
     *
     * @var list<array{class:string, reason:string, patterns:list<string>}>
     */
    private const RULES = [
        [
            'class'   => self::PRICE_DISCREPANCY,
            'reason'  => 'The message talks about a revised or increased price.',
            'patterns' => ['revised quote', 'revised quotation', 'revised rate', 'price increase', 'price revision',
                           'updated price', 'updated rate', 'new price', 'rate change', 'revised pricing'],
        ],
        [
            'class'   => self::DELIVERY_CHANGE,
            'reason'  => 'The message changes a delivery or dispatch date.',
            'patterns' => ['delivery date', 'revised delivery', 'delayed delivery', 'reschedule the delivery',
                           'new eta', 'revised eta', 'dispatch date', 'shipment delayed', 'delivery moved'],
        ],
        [
            'class'   => self::PAYMENT_FOLLOW_UP,
            'reason'  => 'The message is chasing or promising a payment.',
            'patterns' => ['payment is due', 'overdue', 'outstanding payment', 'kindly release the payment',
                           'payment reminder', 'we will pay', 'payment promised', 'clear the invoice', 'pending payment'],
        ],
        [
            'class'   => self::APPROVAL_REQUESTED,
            'reason'  => 'The sender is explicitly asking for an approval or a sign-off.',
            'patterns' => ['for your approval', 'please approve', 'approval needed', 'approval required',
                           'kindly approve', 'sign off', 'sign-off', 'awaiting your approval', 'confirm and approve'],
        ],
        [
            'class'   => self::MEETING_REQUEST,
            'reason'  => 'The sender is proposing a time to meet or talk.',
            'patterns' => ['can we meet', 'schedule a call', 'set up a meeting', 'are you available',
                           'book a slot', 'catch up on', 'would 11', 'meeting request', 'invite you to a call'],
        ],
        [
            'class'   => self::RESPONSE_REQUIRED,
            'reason'  => 'The sender asked a direct question and is waiting on an answer.',
            'patterns' => ['please confirm', 'could you confirm', 'let me know', 'awaiting your reply',
                           'please revert', 'kindly respond', 'your thoughts', 'waiting for your response'],
        ],
    ];

    /**
     * @param array<string, mixed> $message subject, text, from, date
     * @return array<string, mixed>
     */
    public static function classify(array $message, bool $useModel = false): array
    {
        $haystack = mb_strtolower(
            (string) ($message['subject'] ?? '') . ' ' . (string) ($message['text'] ?? ''),
        );

        foreach (self::RULES as $rule) {
            foreach ($rule['patterns'] as $pattern) {
                if (str_contains($haystack, $pattern)) {
                    return self::result(
                        $rule['class'],
                        $rule['reason'] . ' It contains the phrase "' . $pattern . '".',
                        'rules',
                        $message,
                    );
                }
            }
        }

        // A question mark in a message addressed to you is weak evidence, so it
        // is only used when nothing stronger matched, and the reason says so.
        if (str_contains($haystack, '?')) {
            $refined = $useModel ? self::refine($message) : null;
            if ($refined !== null) {
                return $refined;
            }

            return self::result(
                self::RESPONSE_REQUIRED,
                'The message contains a question and no reply has been sent yet.',
                'rules',
                $message,
            );
        }

        return self::result(self::NONE, 'Nothing in this message asks you for anything.', 'rules', $message);
    }

    /**
     * Ask the model to pick from the SAME fixed list.
     *
     * It cannot return a class that is not in CLASSES — an unrecognised answer
     * falls back to the rules result. This is the only place the model touches
     * classification, and it touches it by choosing a label, never by deciding
     * what happens next.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>|null
     */
    private static function refine(array $message): ?array
    {
        $result = AiClient::structured(
            'Choose the class that best describes what this email asks of the recipient.',
            ['classification' => 'string', 'reason' => 'string'],
            [
                'subject' => (string) ($message['subject'] ?? ''),
                'body'    => mb_substr((string) ($message['text'] ?? ''), 0, 6000),
            ],
            "The classification MUST be exactly one of: " . implode(', ', array_keys(self::CLASSES)) . ", none.\n"
            . "The reason is one short sentence quoting the part of the email that decided it.",
        );

        if (!$result['ok'] || $result['value'] === null) {
            return null;
        }

        $class = (string) $result['value']['classification'];
        if ($class !== self::NONE && !isset(self::CLASSES[$class])) {
            return null;
        }

        return self::result($class, (string) $result['value']['reason'], 'model', $message);
    }

    /** @param array<string, mixed> $message @return array<string, mixed> */
    private static function result(string $class, string $reason, string $generator, array $message): array
    {
        $deadline = DeadlineFinder::find((string) ($message['text'] ?? ''), (string) ($message['date'] ?? ''));

        return [
            'classification' => $class,
            'label'          => self::CLASSES[$class] ?? 'No action needed',
            'reason'         => $reason,
            'generator'      => $generator,
            'ai_generated'   => $generator === 'model',
            'source'         => [
                'uid'        => $message['uid'] ?? null,
                'message_id' => $message['message_id'] ?? null,
                'from'       => $message['from'][0]['address'] ?? null,
                'date'       => $message['date'] ?? null,
            ],
            'deadline'       => $deadline['date'],
            // Said out loud rather than left as a blank field.
            'deadline_note'  => $deadline['date'] === null ? 'No deadline found.' : $deadline['reason'],
        ];
    }
}
