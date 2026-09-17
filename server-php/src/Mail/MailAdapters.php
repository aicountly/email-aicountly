<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Env;

/**
 * Which mail store and transport this deployment has, and whether they exist.
 *
 * One place chooses, so there is one place to add a JMAP or provider-API driver
 * later and one place the health check and the capabilities endpoint read.
 *
 * With nothing configured, both fall back to the Unconfigured* adapters, which
 * refuse honestly rather than pretending. That is the difference between an
 * Email that says "no mail store is configured for this deployment" and one
 * that shows an empty inbox to somebody who has mail.
 */
final class MailAdapters
{
    private static ?MailStore $store = null;
    private static ?MailTransport $transport = null;

    public static function store(): MailStore
    {
        if (self::$store !== null) {
            return self::$store;
        }

        $driver = strtolower(Env::get('MAIL_STORE_DRIVER', 'imap'));

        if ($driver === 'none') {
            return self::$store = new UnconfiguredMailStore('Mail storage is switched off for this deployment (MAIL_STORE_DRIVER=none).');
        }

        if ($driver === 'imap') {
            $imap = new ImapMailStore();

            return self::$store = $imap->isConfigured()
                ? $imap
                : new UnconfiguredMailStore((string) ($imap->describe()['reason'] ?? 'The IMAP mail store is not configured.'));
        }

        return self::$store = new UnconfiguredMailStore('MAIL_STORE_DRIVER is set to "' . $driver . '", which this build has no adapter for.');
    }

    public static function transport(): MailTransport
    {
        if (self::$transport !== null) {
            return self::$transport;
        }

        $driver = strtolower(Env::get('MAIL_TRANSPORT_DRIVER', 'smtp'));

        if ($driver === 'none') {
            return self::$transport = new UnconfiguredMailTransport('Outbound mail is switched off for this deployment (MAIL_TRANSPORT_DRIVER=none).');
        }

        if ($driver === 'smtp') {
            $smtp = new SmtpMailTransport();

            return self::$transport = $smtp->isConfigured()
                ? $smtp
                : new UnconfiguredMailTransport('No SMTP host is configured for this deployment.');
        }

        return self::$transport = new UnconfiguredMailTransport('MAIL_TRANSPORT_DRIVER is set to "' . $driver . '", which this build has no adapter for.');
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        return [
            'store'     => self::store()->describe(),
            'transport' => self::transport()->describe(),
            // Stated rather than implied: nothing in this deployment observes
            // delivery, so the UI must never claim it.
            'delivery_confirmation' => [
                'available' => Env::get('MAIL_DSN_WEBHOOK_ENABLED') === '1',
                'reason'    => Env::get('MAIL_DSN_WEBHOOK_ENABLED') === '1'
                    ? null
                    : 'No delivery-status feed is connected, so Email reports acceptance by the outbound server and never claims delivery.',
            ],
            'malware_scanning' => [
                'available' => Env::get('MAIL_SCANNER_ENABLED') === '1',
                'reason'    => Env::get('MAIL_SCANNER_ENABLED') === '1'
                    ? null
                    : 'No malware scanner is connected. Attachments are type- and size-checked; they are not scanned, and Email does not say they are.',
            ],
        ];
    }

    /** CLI only: let a test install a fake store or transport. */
    public static function setForTest(?MailStore $store, ?MailTransport $transport): void
    {
        if (PHP_SAPI !== 'cli') {
            return;
        }
        self::$store = $store;
        self::$transport = $transport;
    }
}
