<?php

declare(strict_types=1);

namespace Aicountly\Api;

/**
 * Per-account limits on the expensive and the abusable.
 *
 * Three things need a ceiling and they need different ones: AI calls cost money
 * per request, outbound mail is what an abused account is abused FOR, and
 * personal signup is what creates the accounts to abuse. Counted in Postgres
 * rather than in memory because PHP-FPM has many workers and an in-process
 * counter limits one worker.
 *
 * Fails OPEN on a database error and says so in the log. A rate limiter that
 * takes the product down when its own table is unavailable has converted a
 * cost problem into an outage.
 */
final class RateLimit
{
    public const TABLE = 'email_rate_counters';

    /** window seconds => how many are allowed in it */
    private const LIMITS = [
        'ai'        => ['window' => 3600, 'max' => 120],
        'send'      => ['window' => 3600, 'max' => 200],
        'action'    => ['window' => 3600, 'max' => 60],
        'search'    => ['window' => 300,  'max' => 120],
    ];

    /**
     * Consume one unit, or answer 429 and stop.
     *
     * @return array{used:int, max:int, resets_at:string}
     */
    public static function consume(string $bucket, Account $account): array
    {
        $usage = self::peek($bucket, $account, true);

        Http::addHeader('X-RateLimit-Limit', (string) $usage['max']);
        Http::addHeader('X-RateLimit-Remaining', (string) max(0, $usage['max'] - $usage['used']));
        Http::addHeader('X-RateLimit-Reset', $usage['resets_at']);

        if ($usage['used'] > $usage['max']) {
            Http::error(429, 'rate_limited', self::message($bucket) . ' The limit resets at ' . $usage['resets_at'] . ' UTC.', [
                'bucket'    => $bucket,
                'limit'     => $usage['max'],
                'resets_at' => $usage['resets_at'],
                'retryable' => true,
            ]);
        }

        return $usage;
    }

    /**
     * Current usage without consuming, for the usage-visibility panel.
     *
     * @return array{used:int, max:int, resets_at:string}
     */
    public static function peek(string $bucket, Account $account, bool $increment = false): array
    {
        $config = self::LIMITS[$bucket] ?? ['window' => 3600, 'max' => 100];
        $windowStart = gmdate('Y-m-d H:i:s', (int) (floor(time() / $config['window']) * $config['window']));
        $resetsAt = gmdate('Y-m-d H:i:s', (int) (floor(time() / $config['window']) * $config['window']) + $config['window']);

        try {
            if ($increment) {
                Db::run(
                    'INSERT INTO ' . self::TABLE . ' (account_id, bucket, window_start, used)
                     VALUES (:account, :bucket, :start, 1)
                     ON CONFLICT (account_id, bucket, window_start)
                     DO UPDATE SET used = ' . self::TABLE . '.used + 1',
                    ['account' => $account->accountId, 'bucket' => $bucket, 'start' => $windowStart],
                );
            }

            $used = (int) (Db::scalar(
                'SELECT used FROM ' . self::TABLE . ' WHERE account_id = :account AND bucket = :bucket AND window_start = :start',
                ['account' => $account->accountId, 'bucket' => $bucket, 'start' => $windowStart],
            ) ?? 0);
        } catch (\Throwable $e) {
            error_log('[email-ratelimit] counter unavailable for ' . $bucket . ': ' . $e->getMessage());
            $used = 0;
        }

        return ['used' => $used, 'max' => (int) $config['max'], 'resets_at' => $resetsAt];
    }

    /** Usage across every bucket, for Settings. @return array<string, array{used:int, max:int, resets_at:string}> */
    public static function summary(Account $account): array
    {
        $out = [];
        foreach (array_keys(self::LIMITS) as $bucket) {
            $out[$bucket] = self::peek($bucket, $account);
        }

        return $out;
    }

    private static function message(string $bucket): string
    {
        return match ($bucket) {
            'ai'     => 'You have used this hour\'s AI allowance.',
            'send'   => 'You have reached this hour\'s sending limit.',
            'action' => 'You have reached this hour\'s limit for product actions.',
            'search' => 'Too many searches in a short time.',
            default  => 'Too many requests.',
        };
    }
}
