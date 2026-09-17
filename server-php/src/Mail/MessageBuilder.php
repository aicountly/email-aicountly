<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Env;

/**
 * Builds the RFC 5322 message that goes on the wire.
 *
 * Every header value passes through AddressValidator first, so nothing a user
 * typed can add a header of its own. The structure is the conventional one:
 *
 *   multipart/mixed
 *     multipart/alternative
 *       text/plain
 *       text/html
 *     application/... (each attachment)
 *
 * and the simple cases collapse — a plain-text message with no attachment is a
 * single text/plain part, not a one-part multipart.
 */
final class MessageBuilder
{
    /** @var array<string, mixed> */
    private array $draft;

    /** @param array<string, mixed> $draft */
    public function __construct(array $draft)
    {
        $this->draft = $draft;
    }

    /**
     * @param array{name:string,address:string}                              $from
     * @param list<array{name:string,address:string}>                        $to
     * @param list<array{name:string,address:string}>                        $cc
     * @param list<array{filename:string,mime_type:string,bytes:string}>     $attachments
     * @param array<string, string>                                          $extraHeaders
     */
    public static function build(
        array $from,
        array $to,
        array $cc,
        string $subject,
        string $text,
        string $html,
        array $attachments = [],
        array $extraHeaders = [],
        ?array $sender = null,
    ): string {
        $boundaryMixed = 'mix_' . bin2hex(random_bytes(12));
        $boundaryAlt   = 'alt_' . bin2hex(random_bytes(12));

        $headers = [
            'Date'         => gmdate('r'),
            'Message-ID'   => self::messageId($from['address'] ?? ''),
            'From'         => AddressValidator::format($from),
            'To'           => AddressValidator::formatList($to),
            'Subject'      => AddressValidator::encodeHeader($subject),
            'MIME-Version' => '1.0',
        ];

        if ($cc !== []) {
            $headers['Cc'] = AddressValidator::formatList($cc);
        }

        // Bcc never appears in the message. It is an envelope recipient only —
        // putting it in a header is how a Bcc list reaches everybody on it.

        if ($sender !== null && strtolower($sender['address']) !== strtolower($from['address'])) {
            // Send-on-behalf: the mailbox in From is the one being written for,
            // Sender is the human who actually pressed send. Receiving clients
            // render this as "on behalf of", which is the honest presentation.
            $headers['Sender'] = AddressValidator::format($sender);
        }

        foreach ($extraHeaders as $name => $value) {
            $cleanName = preg_replace('/[^A-Za-z0-9\-]/', '', $name) ?? '';
            if ($cleanName === '' || isset($headers[$cleanName])) {
                continue;
            }
            $headers[$cleanName] = AddressValidator::safeHeaderText((string) $value);
        }

        $hasHtml = trim($html) !== '';
        $hasAttachments = $attachments !== [];

        if (!$hasHtml && !$hasAttachments) {
            $headers['Content-Type'] = 'text/plain; charset=UTF-8';
            $headers['Content-Transfer-Encoding'] = 'base64';

            return self::headerBlock($headers) . "\r\n" . self::base64Body($text);
        }

        $body = '';

        if ($hasAttachments) {
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundaryMixed . '"';
            $body .= '--' . $boundaryMixed . "\r\n";
        }

        if ($hasHtml) {
            $alternative = "Content-Type: multipart/alternative; boundary=\"{$boundaryAlt}\"\r\n\r\n"
                . '--' . $boundaryAlt . "\r\n"
                . self::textPart($text)
                . '--' . $boundaryAlt . "\r\n"
                . self::htmlPart($html)
                . '--' . $boundaryAlt . "--\r\n";

            if ($hasAttachments) {
                $body .= $alternative;
            } else {
                $headers['Content-Type'] = 'multipart/alternative; boundary="' . $boundaryAlt . '"';
                $body = '--' . $boundaryAlt . "\r\n"
                    . self::textPart($text)
                    . '--' . $boundaryAlt . "\r\n"
                    . self::htmlPart($html)
                    . '--' . $boundaryAlt . "--\r\n";
            }
        } elseif ($hasAttachments) {
            $body .= self::textPart($text);
        }

        foreach ($attachments as $attachment) {
            $body .= '--' . $boundaryMixed . "\r\n" . self::attachmentPart($attachment);
        }
        if ($hasAttachments) {
            $body .= '--' . $boundaryMixed . "--\r\n";
        }

        return self::headerBlock($headers) . "\r\n" . $body;
    }

    /** @param array<string, string> $headers */
    private static function headerBlock(array $headers): string
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            if ($value === '') {
                continue;
            }
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    private static function textPart(string $text): string
    {
        return "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($text);
    }

    private static function htmlPart(string $html): string
    {
        return "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . self::base64Body($html);
    }

    /** @param array{filename:string,mime_type:string,bytes:string} $attachment */
    private static function attachmentPart(array $attachment): string
    {
        $filename = AddressValidator::encodeHeader(basename($attachment['filename']));
        $mime = preg_replace('#[^A-Za-z0-9!\#$&^_.+\-/]#', '', $attachment['mime_type']) ?: 'application/octet-stream';

        return 'Content-Type: ' . $mime . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . 'Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . "\"\r\n\r\n"
            . self::base64Body($attachment['bytes']);
    }

    /** Base64 wrapped at 76 characters — longer lines are non-conforming and some MTAs rewrite them. */
    private static function base64Body(string $raw): string
    {
        return chunk_split(base64_encode($raw), 76, "\r\n");
    }

    /**
     * A Message-ID with the sending domain in it.
     *
     * The domain matters: some receivers reject a Message-ID whose right-hand
     * side does not resolve, and the id is what the thread key is built from.
     */
    private static function messageId(string $fromAddress): string
    {
        $configured = Env::get('MAIL_MESSAGE_ID_DOMAIN');
        if ($configured === '') {
            $parts = explode('@', $fromAddress);
            $configured = count($parts) === 2 ? $parts[1] : 'aicountly.com';
        }
        $domain = preg_replace('/[^A-Za-z0-9.\-]/', '', $configured) ?: 'aicountly.com';

        return '<' . bin2hex(random_bytes(16)) . '.' . time() . '@' . $domain . '>';
    }
}
