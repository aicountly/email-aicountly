<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * One id per request, echoed on every response and written into every audit
 * row and cross-service log line.
 *
 * A caller-supplied `X-Correlation-Id` is honoured so a trace started in the
 * browser survives the hop, but it is sanitised first: it ends up in logs and
 * in a header, and an unfiltered value there is a log-injection and a response-
 * splitting bug at once.
 */
final class Correlation
{
    private static string $id = '';

    public static function id(): string
    {
        if (self::$id !== '') {
            return self::$id;
        }

        $supplied = Http::header('X-Correlation-Id');
        $clean = preg_replace('/[^A-Za-z0-9._:-]/', '', $supplied) ?? '';
        $clean = substr($clean, 0, 64);

        return self::$id = $clean !== '' ? $clean : bin2hex(random_bytes(8));
    }
}
