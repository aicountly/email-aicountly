<?php

declare(strict_types=1);

namespace Aicountly\Api\Controllers;

use Aicountly\Api\Audit;
use Aicountly\Api\Env;
use Aicountly\Api\Http;
use Aicountly\Api\Mail\HtmlSanitizer;

/**
 * Reading a conversation, a message and an attachment.
 *
 * Every body that leaves here has been through HtmlSanitizer. That is half the
 * defence; the browser renders the result in a sandboxed iframe with its own
 * CSP, which is the other half. Neither is trusted alone.
 */
final class ThreadsController extends Controller
{
    /** Types that may be previewed inline. Everything else downloads. */
    private const PREVIEWABLE = ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'];

    public function show(string $mailboxId, string $threadKey): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);
        $folder = (string) (Http::param('folder') ?? 'INBOX');

        $result = $this->store()->thread($identity, $folder, rawurldecode($threadKey));
        $this->assertStoreOk($result);

        if ($result['messages'] === []) {
            Http::notFound('That conversation is no longer in this mailbox.');
        }

        $allowRemote = $this->remoteImagePolicy();

        Http::json(200, [
            'data' => [
                'thread_key' => rawurldecode($threadKey),
                'folder'     => $folder,
                'messages'   => array_map(fn (array $m) => $this->present($m, $allowRemote), $result['messages']),
            ],
            'meta' => ['source' => 'mail_store', 'fetched_at' => gmdate('c')],
        ]);
    }

    public function message(string $mailboxId, string $uid): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);
        $folder = (string) (Http::param('folder') ?? 'INBOX');

        $result = $this->store()->message($identity, $folder, $uid);
        $this->assertStoreOk($result);

        if ($result['message'] === null) {
            Http::notFound('That message is no longer in this mailbox.');
        }

        Http::json(200, [
            'data' => $this->present($result['message'], $this->remoteImagePolicy()),
            'meta' => ['source' => 'mail_store', 'fetched_at' => gmdate('c')],
        ]);
    }

    /**
     * Download or preview one attachment.
     *
     * Authorised like everything else, streamed with a filename the browser
     * cannot be talked into re-interpreting, and served with a content type
     * that is either on the preview list or forced to a download.
     */
    public function attachment(string $mailboxId, string $uid, string $partId): void
    {
        $resolved = $this->mailbox($mailboxId);
        $identity = $this->identity($resolved['mailbox']);
        $folder = (string) (Http::param('folder') ?? 'INBOX');

        $result = $this->store()->attachment($identity, $folder, $uid, $partId);
        if (!$result['ok']) {
            Http::error(503, 'attachment_unavailable', (string) $result['error'], ['retryable' => true]);
        }

        $bytes = (string) $result['bytes'];
        $maxBytes = (int) Env::get('MAIL_MAX_ATTACHMENT_BYTES', '26214400');
        if (strlen($bytes) > $maxBytes) {
            Http::error(413, 'attachment_too_large', 'This attachment is larger than this deployment allows to be served (' . $maxBytes . ' bytes).');
        }

        $filename = $this->safeFilename((string) ($result['filename'] ?? 'attachment'));
        $mime = strtolower((string) ($result['mime_type'] ?? 'application/octet-stream'));
        $wantsPreview = Http::boolParam('preview', false) === true;

        // Only a short list may render in the browser. Anything else is sent as
        // an attachment whatever it claims to be — a text/html "attachment"
        // rendered inline is same-origin script.
        $inline = $wantsPreview && in_array($mime, self::PREVIEWABLE, true);
        if (!$inline) {
            $mime = 'application/octet-stream';
        }

        Audit::record($this->auth(), $this->account(), 'attachment.read', 'attachment', $uid . ':' . $partId, 'ok', [
            'mailbox_id' => (int) $resolved['mailbox']['mailbox_id'],
            'size'       => strlen($bytes),
            'inline'     => $inline,
        ]);

        if (PHP_SAPI === 'cli') {
            Http::data(['filename' => $filename, 'mime_type' => $mime, 'size' => strlen($bytes), 'inline' => $inline]);
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . $filename . '"'
            . "; filename*=UTF-8''" . rawurlencode($filename));
        header('X-Content-Type-Options: nosniff');
        // An attachment must never be able to run in the app's origin.
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Cache-Control: private, no-store');
        header('X-Correlation-Id: ' . \Aicountly\Api\Correlation::id());

        echo $bytes;
        exit;
    }

    /**
     * Sanitise, and say what was removed.
     *
     * The counts matter on screen: "3 remote images blocked" with a button to
     * load them is a choice the reader makes, and silently loading them is a
     * read receipt they did not agree to.
     *
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function present(array $message, bool $allowRemoteImages): array
    {
        $html = (string) ($message['html'] ?? '');
        $text = (string) ($message['text'] ?? '');

        $sanitised = $html !== ''
            ? HtmlSanitizer::sanitize($html, $allowRemoteImages)
            : ['html' => HtmlSanitizer::fromPlainText($text), 'blocked_remote_images' => 0, 'removed_elements' => 0];

        $attachments = [];
        foreach ((array) ($message['attachments'] ?? []) as $attachment) {
            $mime = strtolower((string) ($attachment['mime_type'] ?? 'application/octet-stream'));
            $attachments[] = [
                'part_id'     => $attachment['part_id'],
                'filename'    => $this->safeFilename((string) $attachment['filename']),
                'mime_type'   => $mime,
                'size'        => (int) $attachment['size'],
                'inline'      => (bool) ($attachment['inline'] ?? false),
                'previewable' => in_array($mime, self::PREVIEWABLE, true),
                // Stated on every attachment, so nobody reads the absence of a
                // warning as a clean bill of health.
                'scanned'     => Env::get('MAIL_SCANNER_ENABLED') === '1',
                'scan_note'   => Env::get('MAIL_SCANNER_ENABLED') === '1'
                    ? null
                    : 'No malware scanner is connected to this deployment. This file has not been scanned.',
            ];
        }

        return [
            'uid'         => $message['uid'],
            'folder'      => $message['folder'] ?? null,
            'message_id'  => $message['message_id'] ?? '',
            'thread_key'  => $message['thread_key'] ?? '',
            'subject'     => $message['subject'] ?? '(no subject)',
            'from'        => $message['from'] ?? [],
            'to'          => $message['to'] ?? [],
            'cc'          => $message['cc'] ?? [],
            'reply_to'    => $message['reply_to'] ?? [],
            'date'        => $message['date'] ?? null,
            'text'        => $text,
            // Already sanitised. The frontend still renders it inside a
            // sandboxed iframe; see SecureMessageFrame.tsx.
            'html'        => $sanitised['html'],
            'render'      => [
                'sanitized'             => true,
                'blocked_remote_images' => $sanitised['blocked_remote_images'],
                'removed_elements'      => $sanitised['removed_elements'],
                'remote_images_allowed' => $allowRemoteImages,
            ],
            'attachments' => $attachments,
            'seen'        => (bool) ($message['seen'] ?? false),
            'flagged'     => (bool) ($message['flagged'] ?? false),
            'answered'    => (bool) ($message['answered'] ?? false),
            'authentication' => [
                'header'  => $message['auth_results'] ?? null,
                // The sentence that stops a pass being read as a guarantee.
                'caveat'  => 'SPF, DKIM and DMARC say the message came from a server permitted to send for that domain. '
                           . 'They do not confirm who wrote it or that anything in it is true.',
            ],
        ];
    }

    private function remoteImagePolicy(): bool
    {
        if (Http::boolParam('load_remote_images', false) === true) {
            return true;
        }

        $row = \Aicountly\Api\Db::first(
            'SELECT remote_images FROM email_accounts WHERE account_id = :id',
            ['id' => $this->account()->accountId],
        );

        return ($row['remote_images'] ?? 'ask') === 'always';
    }

    /**
     * A filename a browser cannot be talked into re-interpreting.
     *
     * Path separators, control characters and CR/LF go; the extension survives.
     * A name that becomes empty gets a generic one rather than an empty
     * Content-Disposition, which some browsers treat as the URL's last segment.
     */
    private function safeFilename(string $raw): string
    {
        $base = basename(str_replace(['\\', "\0"], '/', $raw));
        $clean = preg_replace('/[\r\n"\x00-\x1F\x7F]/', '', $base) ?? $base;
        $clean = trim($clean, ". \t");

        return $clean === '' ? 'attachment' : mb_substr($clean, 0, 180);
    }
}
