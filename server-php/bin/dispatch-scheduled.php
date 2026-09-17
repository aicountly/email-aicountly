<?php

declare(strict_types=1);

/**
 * Put due scheduled sends on the wire, and retry deferred ones.
 *
 *   php server-php/bin/dispatch-scheduled.php
 *
 * Run it from cron, every minute, on the API host:
 *
 *   * * * * * /usr/local/bin/php /home/<user>/public_html/api/bin/dispatch-scheduled.php >/dev/null 2>&1
 *
 * WHAT THIS IS AND IS NOT. It is the one scheduled job Email has, and it exists
 * because "send this at 9am tomorrow" has to happen at 9am rather than when
 * somebody next opens a page. It touches EMAIL'S OWN send jobs and nothing
 * else: it reads no other product, writes no other product, and copies nothing
 * between databases. That is the line between a scheduler and the
 * cross-application synchronisation this architecture forbids — see
 * docs/EMAIL_DATA_OWNERSHIP.md.
 *
 * An UNCERTAIN outcome is never retried here. The job is left for a person to
 * decide on, because the automatic choice is either a lost message or a
 * duplicate one.
 */

namespace Aicountly\Api;

require __DIR__ . '/../src/Env.php';
require __DIR__ . '/../src/Autoload.php';

Env::load(__DIR__ . '/../.env');

use Aicountly\Api\Mail\Sender;
use Aicountly\Api\Mail\SendJob;

$limit = (int) ($argv[1] ?? 25);
$due = SendJob::due($limit > 0 ? $limit : 25);

if ($due === []) {
    echo "Nothing due.\n";
    exit(0);
}

$counts = ['accepted' => 0, 'failed' => 0, 'deferred' => 0, 'uncertain' => 0, 'skipped' => 0];

foreach ($due as $job) {
    $status = Sender::dispatch((int) $job['job_id']);
    $counts[$status] = ($counts[$status] ?? 0) + 1;
}

foreach ($counts as $status => $count) {
    if ($count > 0) {
        echo str_pad($status, 12) . $count . "\n";
    }
}

// A non-zero exit would make cron mail the administrator on every uncertain
// outcome. They are reported in the UI, on the message, where they can be acted
// on; the exit code stays 0 unless the process itself failed.
exit(0);
