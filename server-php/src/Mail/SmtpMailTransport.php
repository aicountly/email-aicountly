<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Env;

/**
 * SMTP submission over a raw socket.
 *
 * No dependency, because this backend has none and a vendor/ directory per
 * product is megabytes of nothing for a deploy that is an rsync. What it does
 * implement is the part that matters for correctness:
 *
 *  - STARTTLS (587) and implicit TLS (465), with certificate verification ON.
 *    `MAIL_SMTP_ALLOW_INSECURE=1` exists for a local test server and refuses to
 *    apply to a non-loopback host.
 *  - AUTH PLAIN and AUTH LOGIN, chosen from the server's EHLO advertisement.
 *  - Dot-stuffing and CRLF normalisation, without which any message containing
 *    a line starting with "." is silently truncated at that line.
 *  - The four outcomes in MailTransport, and in particular UNCERTAIN: if the
 *    connection dies after DATA is written, this reports uncertain rather than
 *    guessing. Guessing is how a message gets sent twice.
 *
 * Credentials are read from the server .env at call time and never leave this
 * process. Nothing here is ever returned to the browser.
 */
final class SmtpMailTransport implements MailTransport
{
    private const CONNECT_TIMEOUT = 10;
    private const COMMAND_TIMEOUT = 30;

    public function isConfigured(): bool
    {
        return Env::get('MAIL_SMTP_HOST') !== '';
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        if (!$this->isConfigured()) {
            return (new UnconfiguredMailTransport('No SMTP host is configured for this deployment.'))->describe();
        }

        return [
            'configured'  => true,
            'driver'      => 'smtp',
            // The host is operational configuration an administrator needs and is
            // not a secret; the username and password never appear.
            'host'        => Env::get('MAIL_SMTP_HOST'),
            'port'        => (int) Env::get('MAIL_SMTP_PORT', '587'),
            'encryption'  => $this->encryption(),
            'authenticates' => Env::get('MAIL_SMTP_USERNAME') !== '',
            'reason'      => null,
        ];
    }

    /**
     * @param list<string> $recipients
     * @return array{outcome:string, queue_id:?string, code:?int, detail:?string}
     */
    public function send(string $envelopeFrom, array $recipients, string $rawMessage): array
    {
        if (!$this->isConfigured()) {
            return ['outcome' => self::REJECTED, 'queue_id' => null, 'code' => null, 'detail' => 'SMTP is not configured.'];
        }
        if ($recipients === []) {
            return ['outcome' => self::REJECTED, 'queue_id' => null, 'code' => null, 'detail' => 'No recipients.'];
        }

        $host = Env::get('MAIL_SMTP_HOST');
        $port = (int) Env::get('MAIL_SMTP_PORT', '587');
        $encryption = $this->encryption();

        $address = ($encryption === 'tls' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create(['ssl' => $this->sslOptions($host)]);

        $socket = @stream_socket_client($address, $errno, $errstr, self::CONNECT_TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            // Connection never established — nothing was submitted, so this is a
            // clean deferral rather than an uncertainty.
            error_log('[email-smtp] connect failed (' . (int) $errno . ')');

            return ['outcome' => self::DEFERRED, 'queue_id' => null, 'code' => null, 'detail' => 'Could not reach the mail server.'];
        }
        stream_set_timeout($socket, self::COMMAND_TIMEOUT);

        // Everything from here to QUIT is "the conversation": a failure before
        // DATA is a clean outcome, a failure after it is uncertain.
        $dataWritten = false;

        try {
            $greeting = $this->read($socket);
            if ($greeting['code'] !== 220) {
                return $this->fail($socket, $greeting, 'The mail server refused the connection.');
            }

            $ehlo = $this->command($socket, 'EHLO ' . $this->heloName());
            if ($ehlo['code'] !== 250) {
                return $this->fail($socket, $ehlo, 'The mail server rejected EHLO.');
            }

            if ($encryption === 'starttls') {
                $start = $this->command($socket, 'STARTTLS');
                if ($start['code'] !== 220) {
                    return $this->fail($socket, $start, 'The mail server would not start TLS.');
                }
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) {
                    return $this->fail($socket, ['code' => null, 'text' => ''], 'TLS negotiation with the mail server failed.');
                }
                // The capability list before STARTTLS is not trustworthy; ask again.
                $ehlo = $this->command($socket, 'EHLO ' . $this->heloName());
                if ($ehlo['code'] !== 250) {
                    return $this->fail($socket, $ehlo, 'The mail server rejected EHLO after TLS.');
                }
            }

            $username = Env::get('MAIL_SMTP_USERNAME');
            if ($username !== '') {
                $auth = $this->authenticate($socket, $ehlo['text'], $username, Env::get('MAIL_SMTP_PASSWORD'));
                if ($auth !== null) {
                    return $this->fail($socket, $auth, 'The mail server rejected the mailbox credentials.');
                }
            }

            $from = $this->command($socket, 'MAIL FROM:<' . $envelopeFrom . '>');
            if ($from['code'] !== 250) {
                return $this->fail($socket, $from, 'The mail server refused the sender address.');
            }

            foreach ($recipients as $recipient) {
                $rcpt = $this->command($socket, 'RCPT TO:<' . $recipient . '>');
                if ($rcpt['code'] !== 250 && $rcpt['code'] !== 251) {
                    return $this->fail($socket, $rcpt, 'The mail server refused a recipient address.');
                }
            }

            $data = $this->command($socket, 'DATA');
            if ($data['code'] !== 354) {
                return $this->fail($socket, $data, 'The mail server refused to accept the message body.');
            }

            $dataWritten = true;
            $this->write($socket, $this->prepareBody($rawMessage) . "\r\n.\r\n");
            $result = $this->read($socket);

            if ($result['code'] === 250) {
                @fwrite($socket, "QUIT\r\n");

                return [
                    'outcome'  => self::ACCEPTED,
                    'queue_id' => $this->queueId($result['text']),
                    'code'     => 250,
                    // Worded so it cannot be mistaken for delivery.
                    'detail'   => 'Accepted by the outbound mail server for delivery.',
                ];
            }

            return $this->fail($socket, $result, 'The mail server did not accept the message.');
        } catch (\Throwable $e) {
            error_log('[email-smtp] transport error: ' . $e->getMessage());

            if ($dataWritten) {
                // The message may already be in the server's queue. Saying
                // "failed" here is what causes a duplicate on the retry.
                return [
                    'outcome'  => self::UNCERTAIN,
                    'queue_id' => null,
                    'code'     => null,
                    'detail'   => 'The connection to the mail server was lost after the message was written. It may or may not have been accepted.',
                ];
            }

            return ['outcome' => self::DEFERRED, 'queue_id' => null, 'code' => null, 'detail' => 'The mail server connection failed before the message was sent.'];
        } finally {
            if (is_resource($socket)) {
                @fclose($socket);
            }
        }
    }

    // -----------------------------------------------------------------------

    /** @return array{code:?int, text:string}|null null when authentication succeeded */
    private function authenticate($socket, string $capabilities, string $username, string $password): ?array
    {
        $supportsPlain = stripos($capabilities, 'AUTH') !== false && stripos($capabilities, 'PLAIN') !== false;
        $supportsLogin = stripos($capabilities, 'AUTH') !== false && stripos($capabilities, 'LOGIN') !== false;

        if ($supportsPlain) {
            $token = base64_encode("\0" . $username . "\0" . $password);
            $response = $this->command($socket, 'AUTH PLAIN ' . $token);

            return $response['code'] === 235 ? null : $response;
        }

        if ($supportsLogin) {
            $start = $this->command($socket, 'AUTH LOGIN');
            if ($start['code'] !== 334) {
                return $start;
            }
            $user = $this->command($socket, base64_encode($username));
            if ($user['code'] !== 334) {
                return $user;
            }
            $pass = $this->command($socket, base64_encode($password));

            return $pass['code'] === 235 ? null : $pass;
        }

        return ['code' => null, 'text' => 'The mail server advertises no supported authentication mechanism.'];
    }

    /** @param array{code:?int, text:string} $response */
    private function fail($socket, array $response, string $message): array
    {
        if (is_resource($socket)) {
            @fwrite($socket, "QUIT\r\n");
        }
        $code = $response['code'];
        error_log('[email-smtp] refused with code ' . ($code ?? 0));

        // 4xx is temporary and the same message may be retried; 5xx is not.
        $outcome = ($code !== null && $code >= 400 && $code < 500) ? self::DEFERRED : self::REJECTED;

        return ['outcome' => $outcome, 'queue_id' => null, 'code' => $code, 'detail' => $message];
    }

    /** @return array{code:?int, text:string} */
    private function command($socket, string $line): array
    {
        $this->write($socket, $line . "\r\n");

        return $this->read($socket);
    }

    private function write($socket, string $payload): void
    {
        $written = @fwrite($socket, $payload);
        if ($written === false) {
            throw new \RuntimeException('write failed');
        }
    }

    /** @return array{code:?int, text:string} */
    private function read($socket): array
    {
        $text = '';
        $code = null;

        while (true) {
            $line = @fgets($socket, 8192);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new \RuntimeException('read timed out');
                }
                throw new \RuntimeException('connection closed');
            }
            $text .= $line;
            $code = (int) substr($line, 0, 3);
            // "250-" continues, "250 " ends.
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return ['code' => $code, 'text' => $text];
    }

    /**
     * CRLF line endings and dot-stuffing.
     *
     * A bare "." at the start of a line ends DATA. Without the stuffing below a
     * message whose body contains such a line is delivered truncated, and the
     * sender has no way to tell.
     */
    private function prepareBody(string $raw): string
    {
        $normalised = preg_replace("/\r\n|\r|\n/", "\r\n", $raw) ?? $raw;

        return preg_replace("/^\./m", '..', $normalised) ?? $normalised;
    }

    /** Postfix and friends put a queue id in the 250 line; it is the only handle on the message afterwards. */
    private function queueId(string $response): ?string
    {
        if (preg_match('/queued as ([A-Za-z0-9._-]+)/i', $response, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/\b(?:id=)?([0-9A-F]{8,})\b/i', $response, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function encryption(): string
    {
        $configured = strtolower(Env::get('MAIL_SMTP_ENCRYPTION'));
        if (in_array($configured, ['tls', 'ssl'], true)) {
            return 'tls';
        }
        if ($configured === 'starttls') {
            return 'starttls';
        }
        if ($configured === 'none') {
            return 'none';
        }

        return ((int) Env::get('MAIL_SMTP_PORT', '587')) === 465 ? 'tls' : 'starttls';
    }

    /**
     * Certificate verification is on.
     *
     * The escape hatch is deliberately useless in production: it applies only
     * when the SMTP host is loopback, so a deployment cannot quietly turn off
     * verification against a real mail server.
     * @return array<string, mixed>
     */
    private function sslOptions(string $host): array
    {
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        $insecure = $loopback && Env::get('MAIL_SMTP_ALLOW_INSECURE') === '1';

        return [
            'verify_peer'       => !$insecure,
            'verify_peer_name'  => !$insecure,
            'allow_self_signed' => $insecure,
            'SNI_enabled'       => true,
            'peer_name'         => $host,
        ];
    }

    private function heloName(): string
    {
        $configured = Env::get('MAIL_SMTP_HELO');
        if ($configured !== '') {
            return $configured;
        }
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'email.aicountly.com');

        return preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $host)[0]) ?: 'email.aicountly.com';
    }
}
