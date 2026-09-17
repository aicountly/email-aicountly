<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

use Aicountly\Api\Env;

/**
 * The IMAP implementation of MailStore.
 *
 * Uses the `imap` extension, which is what cPanel/CloudLinux hosts ship and
 * what the rest of this fleet's PHP already relies on for its own mail. If the
 * extension is absent the store reports itself unconfigured with that as the
 * reason, rather than fataling on the first inbox request — an administrator
 * can read "the imap extension is not installed" and act on it.
 *
 * WHAT THIS CLASS DOES NOT DO:
 *  - It does not authorise. MailboxAccess did that before the call.
 *  - It does not store anything. Bodies and attachments stay in the store; the
 *    caller keeps a reference.
 *  - It does not sanitise HTML. That is HtmlSanitizer, applied at the point of
 *    rendering, so a raw body fetched for a download is not silently altered.
 *
 * Cursors are UIDs. IMAP UIDs rise monotonically within a folder, so
 * "everything below this UID, newest first" is stable while new mail arrives —
 * which an offset is not.
 */
final class ImapMailStore implements MailStore
{
    private const CONNECT_RETRIES = 1;

    /** @var array<string, \IMAP\Connection|resource> */
    private array $connections = [];

    public function isConfigured(): bool
    {
        return Env::get('MAIL_IMAP_HOST') !== '' && extension_loaded('imap');
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        if (!extension_loaded('imap')) {
            return (new UnconfiguredMailStore('The PHP imap extension is not installed on this server.'))->describe();
        }
        if (Env::get('MAIL_IMAP_HOST') === '') {
            return (new UnconfiguredMailStore('No IMAP host is configured for this deployment.'))->describe();
        }

        return [
            'configured'  => true,
            'driver'      => 'imap',
            'host'        => Env::get('MAIL_IMAP_HOST'),
            'port'        => (int) Env::get('MAIL_IMAP_PORT', '993'),
            'encryption'  => Env::get('MAIL_IMAP_ENCRYPTION', 'ssl'),
            'auth_mode'   => Env::get('MAIL_IMAP_MASTER_USER') !== '' ? 'master' : 'per-mailbox',
            'reason'      => null,
        ];
    }

    // -----------------------------------------------------------------------
    // Folders
    // -----------------------------------------------------------------------

    public function folders(MailboxIdentity $identity): array
    {
        $connection = $this->connect($identity, 'INBOX');
        if ($connection === null) {
            return ['ok' => false, 'folders' => [], 'error' => $this->lastError()];
        }

        $list = @imap_getmailboxes($connection, $this->reference(), '*');
        if ($list === false) {
            return ['ok' => false, 'folders' => [], 'error' => $this->lastError()];
        }

        $reference = $this->reference();
        $folders = [];
        foreach ($list as $entry) {
            $full = (string) $entry->name;
            $name = str_starts_with($full, $reference) ? substr($full, strlen($reference)) : $full;
            $name = $this->decodeFolderName($name);
            if ($name === '') {
                continue;
            }

            $status = @imap_status($connection, $full, SA_MESSAGES | SA_UNSEEN);
            $folders[] = [
                'id'        => $name,
                'name'      => $name,
                'role'      => $this->roleFor($name),
                'delimiter' => (string) ($entry->delimiter ?? '/'),
                'total'     => $status === false ? null : (int) $status->messages,
                'unread'    => $status === false ? null : (int) $status->unseen,
            ];
        }

        return ['ok' => true, 'folders' => $folders, 'error' => null];
    }

    // -----------------------------------------------------------------------
    // Reading
    // -----------------------------------------------------------------------

    public function threads(MailboxIdentity $identity, string $folder, ?string $cursor, int $limit, array $filters = []): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'threads' => [], 'next_cursor' => null, 'error' => $this->lastError()];
        }

        $before = $cursor !== null && ctype_digit($cursor) ? (int) $cursor : null;
        $criteria = $this->searchCriteria($filters);

        $uids = @imap_search($connection, $criteria, SE_UID);
        if ($uids === false) {
            // imap_search answers false for "no matches" as well as for failure.
            // imap_errors() distinguishes them; an empty error list means the
            // folder genuinely has nothing matching.
            $errors = imap_errors();

            return $errors === false
                ? ['ok' => true, 'threads' => [], 'next_cursor' => null, 'error' => null]
                : ['ok' => false, 'threads' => [], 'next_cursor' => null, 'error' => 'The mail store could not search this folder.'];
        }

        rsort($uids, SORT_NUMERIC);
        if ($before !== null) {
            $uids = array_values(array_filter($uids, static fn ($uid) => (int) $uid < $before));
        }

        // One extra row tells us whether there is another page without counting
        // the whole folder.
        $window = array_slice($uids, 0, $limit + 1);
        $hasMore = count($window) > $limit;
        $page = array_slice($window, 0, $limit);

        $threads = [];
        foreach ($page as $uid) {
            $header = @imap_headerinfo($connection, (int) imap_msgno($connection, (int) $uid));
            if ($header === false) {
                continue;
            }
            $threads[] = $this->summaryFrom($connection, (string) $uid, $header, $folder);
        }

        $nextCursor = $hasMore && $page !== [] ? (string) end($page) : null;

        return ['ok' => true, 'threads' => $threads, 'next_cursor' => $nextCursor, 'error' => null];
    }

    public function thread(MailboxIdentity $identity, string $folder, string $threadKey): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'messages' => [], 'error' => $this->lastError()];
        }

        // Thread membership is the References chain, not the subject: two people
        // can both write "Re: invoice" about different invoices.
        $uids = @imap_search($connection, 'TEXT "' . $this->escapeSearch($threadKey) . '"', SE_UID);
        $uids = $uids === false ? [] : $uids;

        $messages = [];
        foreach ($uids as $uid) {
            $loaded = $this->message($identity, $folder, (string) $uid);
            if ($loaded['ok'] && $loaded['message'] !== null && $loaded['message']['thread_key'] === $threadKey) {
                $messages[] = $loaded['message'];
            }
        }

        usort($messages, static fn (array $a, array $b) => strcmp((string) $a['date'], (string) $b['date']));

        return ['ok' => true, 'messages' => $messages, 'error' => null];
    }

    public function message(MailboxIdentity $identity, string $folder, string $uid): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'message' => null, 'error' => $this->lastError()];
        }

        $rawHeaders = @imap_fetchheader($connection, (int) $uid, FT_UID);
        if ($rawHeaders === false) {
            return ['ok' => false, 'message' => null, 'error' => 'That message is no longer in the mailbox.'];
        }

        $headers = $this->parseHeaders($rawHeaders);
        $structure = @imap_fetchstructure($connection, (int) $uid, FT_UID);
        $parts = $structure === false ? ['text' => '', 'html' => '', 'attachments' => []] : $this->walk($connection, (int) $uid, $structure);

        $overview = @imap_fetch_overview($connection, $uid, FT_UID);
        $flags = is_array($overview) && isset($overview[0]) ? $overview[0] : null;

        return [
            'ok' => true,
            'message' => [
                'uid'          => $uid,
                'folder'       => $folder,
                'message_id'   => $headers['message-id'] ?? '',
                'thread_key'   => $this->threadKey($headers),
                'subject'      => $this->decodeMime($headers['subject'] ?? ''),
                'from'         => $this->addressList($headers['from'] ?? ''),
                'to'           => $this->addressList($headers['to'] ?? ''),
                'cc'           => $this->addressList($headers['cc'] ?? ''),
                'reply_to'     => $this->addressList($headers['reply-to'] ?? ($headers['from'] ?? '')),
                'date'         => $this->isoDate($headers['date'] ?? ''),
                'text'         => $parts['text'],
                'html'         => $parts['html'],
                'attachments'  => $parts['attachments'],
                'size'         => $flags !== null ? (int) ($flags->size ?? 0) : null,
                'seen'         => $flags !== null ? (bool) ($flags->seen ?? false) : false,
                'flagged'      => $flags !== null ? (bool) ($flags->flagged ?? false) : false,
                'answered'     => $flags !== null ? (bool) ($flags->answered ?? false) : false,
                // Authentication-Results is what the receiving MTA concluded. It
                // is reported, never interpreted as "safe" — see HtmlSanitizer
                // and the payment-change alert.
                'auth_results' => $headers['authentication-results'] ?? null,
            ],
            'error' => null,
        ];
    }

    public function attachment(MailboxIdentity $identity, string $folder, string $uid, string $partId): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'filename' => null, 'mime_type' => null, 'bytes' => null, 'size' => null, 'error' => $this->lastError()];
        }

        $structure = @imap_fetchstructure($connection, (int) $uid, FT_UID);
        if ($structure === false) {
            return ['ok' => false, 'filename' => null, 'mime_type' => null, 'bytes' => null, 'size' => null, 'error' => 'That message is no longer in the mailbox.'];
        }

        $found = null;
        foreach ($this->walk($connection, (int) $uid, $structure)['attachments'] as $candidate) {
            if ($candidate['part_id'] === $partId) {
                $found = $candidate;
                break;
            }
        }
        if ($found === null) {
            return ['ok' => false, 'filename' => null, 'mime_type' => null, 'bytes' => null, 'size' => null, 'error' => 'That attachment is not part of this message.'];
        }

        $raw = @imap_fetchbody($connection, (int) $uid, $partId, FT_UID);
        if ($raw === false) {
            return ['ok' => false, 'filename' => null, 'mime_type' => null, 'bytes' => null, 'size' => null, 'error' => 'The attachment could not be read.'];
        }

        $bytes = $this->decodeBody($raw, (int) $found['encoding']);

        return [
            'ok'        => true,
            'filename'  => $found['filename'],
            'mime_type' => $found['mime_type'],
            'bytes'     => $bytes,
            'size'      => strlen($bytes),
            'error'     => null,
        ];
    }

    // -----------------------------------------------------------------------
    // Writing
    // -----------------------------------------------------------------------

    public function setFlags(MailboxIdentity $identity, string $folder, array $uids, array $add, array $remove): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'updated' => 0, 'error' => $this->lastError()];
        }

        $sequence = $this->uidSequence($uids);
        if ($sequence === '') {
            return ['ok' => true, 'updated' => 0, 'error' => null];
        }

        $ok = true;
        if ($add !== []) {
            $ok = @imap_setflag_full($connection, $sequence, implode(' ', $this->allowedFlags($add)), ST_UID) && $ok;
        }
        if ($remove !== []) {
            $ok = @imap_clearflag_full($connection, $sequence, implode(' ', $this->allowedFlags($remove)), ST_UID) && $ok;
        }

        return ['ok' => $ok, 'updated' => $ok ? count($uids) : 0, 'error' => $ok ? null : 'The mail store refused the flag change.'];
    }

    public function move(MailboxIdentity $identity, string $folder, array $uids, string $targetFolder): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'moved' => 0, 'error' => $this->lastError()];
        }

        $sequence = $this->uidSequence($uids);
        if ($sequence === '') {
            return ['ok' => true, 'moved' => 0, 'error' => null];
        }

        $target = $this->reference() . $this->safeFolder($targetFolder);
        if (!@imap_mail_move($connection, $sequence, $target, CP_UID)) {
            return ['ok' => false, 'moved' => 0, 'error' => 'The mail store refused the move.'];
        }
        @imap_expunge($connection);

        return ['ok' => true, 'moved' => count($uids), 'error' => null];
    }

    public function search(MailboxIdentity $identity, string $folder, string $query, int $limit): array
    {
        $connection = $this->connect($identity, $folder);
        if ($connection === null) {
            return ['ok' => false, 'threads' => [], 'error' => $this->lastError()];
        }

        $term = $this->escapeSearch($query);
        $uids = @imap_search($connection, 'TEXT "' . $term . '"', SE_UID);
        if ($uids === false) {
            return imap_errors() === false
                ? ['ok' => true, 'threads' => [], 'error' => null]
                : ['ok' => false, 'threads' => [], 'error' => 'The mail store could not run that search.'];
        }

        rsort($uids, SORT_NUMERIC);
        $threads = [];
        foreach (array_slice($uids, 0, $limit) as $uid) {
            $header = @imap_headerinfo($connection, (int) imap_msgno($connection, (int) $uid));
            if ($header !== false) {
                $threads[] = $this->summaryFrom($connection, (string) $uid, $header, $folder);
            }
        }

        return ['ok' => true, 'threads' => $threads, 'error' => null];
    }

    public function append(MailboxIdentity $identity, string $folder, string $rawMessage, array $flags = []): array
    {
        $connection = $this->connect($identity, 'INBOX');
        if ($connection === null) {
            return ['ok' => false, 'uid' => null, 'error' => $this->lastError()];
        }

        $target = $this->reference() . $this->safeFolder($folder);
        $flagString = $flags === [] ? null : implode(' ', $this->allowedFlags($flags));

        if (!@imap_append($connection, $target, $rawMessage, $flagString)) {
            return ['ok' => false, 'uid' => null, 'error' => 'The mail store would not store the sent copy.'];
        }

        // IMAP APPEND does not report the new UID without UIDPLUS, and the copy
        // is a convenience rather than the record of the send, so a missing UID
        // is not an error.
        return ['ok' => true, 'uid' => null, 'error' => null];
    }

    public function quota(MailboxIdentity $identity): array
    {
        $connection = $this->connect($identity, 'INBOX');
        if ($connection === null) {
            return ['ok' => false, 'used_bytes' => null, 'quota_bytes' => null, 'error' => $this->lastError()];
        }

        $quota = @imap_get_quotaroot($connection, 'INBOX');
        if ($quota === false || !isset($quota['STORAGE'])) {
            // Plenty of servers do not publish a quota. "Unknown" is the honest
            // answer; a zero would be read as "full".
            return ['ok' => true, 'used_bytes' => null, 'quota_bytes' => null, 'error' => null];
        }

        return [
            'ok'          => true,
            'used_bytes'  => (int) $quota['STORAGE']['usage'] * 1024,
            'quota_bytes' => (int) $quota['STORAGE']['limit'] * 1024,
            'error'       => null,
        ];
    }

    // -----------------------------------------------------------------------
    // Connection
    // -----------------------------------------------------------------------

    /** @return \IMAP\Connection|resource|null */
    private function connect(MailboxIdentity $identity, string $folder)
    {
        $key = $identity->address . '|' . $folder;
        if (isset($this->connections[$key])) {
            return $this->connections[$key];
        }
        if (!$this->isConfigured()) {
            return null;
        }

        $mailbox = $this->reference() . $this->safeFolder($folder);
        $connection = @imap_open($mailbox, $identity->login, $identity->password(), 0, self::CONNECT_RETRIES, [
            'DISABLE_AUTHENTICATOR' => ['GSSAPI', 'NTLM'],
        ]);

        if ($connection === false) {
            // imap_errors() can contain the login string; only the count goes to
            // the log, and nothing at all goes to the caller.
            $errors = imap_errors();
            error_log('[email-imap] connection failed for mailbox ' . $identity->mailboxId . ' (' . count($errors === false ? [] : $errors) . ' errors)');

            return null;
        }

        return $this->connections[$key] = $connection;
    }

    private function lastError(): string
    {
        // Deliberately fixed text. imap_last_error() can echo the connection
        // string, which carries the login.
        imap_errors();

        return 'The mail store could not be reached for this mailbox.';
    }

    /** `{host:993/imap/ssl}` */
    private function reference(): string
    {
        $host = Env::get('MAIL_IMAP_HOST');
        $port = (int) Env::get('MAIL_IMAP_PORT', '993');
        $encryption = strtolower(Env::get('MAIL_IMAP_ENCRYPTION', 'ssl'));

        $flags = '/imap';
        if ($encryption === 'ssl' || $encryption === 'tls') {
            $flags .= '/ssl';
        } elseif ($encryption === 'starttls') {
            $flags .= '/tls';
        } else {
            $flags .= '/notls';
        }
        // Certificate validation stays on unless the host is loopback, so a
        // deployment cannot quietly disable it against a real server.
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        $flags .= ($loopback && Env::get('MAIL_IMAP_ALLOW_INSECURE') === '1') ? '/novalidate-cert' : '/validate-cert';

        return '{' . $host . ':' . $port . $flags . '}';
    }

    /**
     * Folder names come from the client. `}` would close the connection string
     * and let a caller point this at a different server; newlines would inject
     * an IMAP command.
     */
    private function safeFolder(string $folder): string
    {
        $clean = str_replace(["\r", "\n", '{', '}', '"'], '', trim($folder));

        return $clean === '' ? 'INBOX' : $clean;
    }

    // -----------------------------------------------------------------------
    // Parsing
    // -----------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function summaryFrom($connection, string $uid, object $header, string $folder): array
    {
        $rawHeaders = @imap_fetchheader($connection, (int) $uid, FT_UID);
        $parsed = $rawHeaders === false ? [] : $this->parseHeaders($rawHeaders);

        return [
            'uid'         => $uid,
            'folder'      => $folder,
            'message_id'  => $parsed['message-id'] ?? '',
            'thread_key'  => $this->threadKey($parsed),
            'subject'     => $this->decodeMime((string) ($header->subject ?? '')),
            'from'        => $this->addressList($parsed['from'] ?? ''),
            'to'          => $this->addressList($parsed['to'] ?? ''),
            'date'        => $this->isoDate((string) ($header->date ?? '')),
            'seen'        => ($header->Unseen ?? 'U') !== 'U' && ($header->Recent ?? '') !== 'N',
            'flagged'     => ($header->Flagged ?? ' ') === 'F',
            'answered'    => ($header->Answered ?? ' ') === 'A',
            'size'        => (int) ($header->Size ?? 0),
            'has_attachments' => str_contains(strtolower($parsed['content-type'] ?? ''), 'multipart/mixed'),
        ];
    }

    /** @return array<string, string> */
    private function parseHeaders(string $raw): array
    {
        $unfolded = preg_replace("/\r\n[ \t]+/", ' ', $raw) ?? $raw;
        $headers = [];
        foreach (preg_split("/\r\n|\n/", $unfolded) ?: [] as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            $name = strtolower(trim(substr($line, 0, $pos)));
            $value = trim(substr($line, $pos + 1));
            // Keep the FIRST occurrence: a second From: is a spoofing technique,
            // and clients that read the last one disagree with the MTA that read
            // the first.
            $headers[$name] ??= $value;
        }

        return $headers;
    }

    /**
     * The stable identity of a conversation.
     *
     * The root of the References chain, falling back to In-Reply-To and then to
     * the message's own id. Subject is deliberately NOT part of it: people
     * rename threads, and two unrelated "Re: invoice" messages are not one
     * conversation.
     *
     * @param array<string, string> $headers
     */
    private function threadKey(array $headers): string
    {
        $references = trim($headers['references'] ?? '');
        if ($references !== '' && preg_match('/<[^>]+>/', $references, $m) === 1) {
            return $m[0];
        }
        $inReplyTo = trim($headers['in-reply-to'] ?? '');
        if ($inReplyTo !== '' && preg_match('/<[^>]+>/', $inReplyTo, $m) === 1) {
            return $m[0];
        }

        return trim($headers['message-id'] ?? '');
    }

    /** @return list<array{name:string, address:string}> */
    private function addressList(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $parsed = @imap_rfc822_parse_adrlist($raw, '');
        $entries = is_array($parsed) ? $parsed : [];
        $out = [];
        foreach ($entries as $entry) {
            $host = (string) ($entry->host ?? '');
            $mailbox = (string) ($entry->mailbox ?? '');
            if ($mailbox === '' || $host === '' || $host === '.SYNTAX-ERROR.') {
                continue;
            }
            $out[] = [
                'name'    => $this->decodeMime((string) ($entry->personal ?? '')),
                'address' => strtolower($mailbox . '@' . $host),
            ];
        }

        return $out;
    }

    private function decodeMime(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $parts = @imap_mime_header_decode($value);
        if ($parts === false) {
            return $value;
        }

        $out = '';
        foreach ($parts as $part) {
            $charset = strtolower((string) $part->charset);
            $text = (string) $part->text;
            $out .= ($charset === 'default' || $charset === 'utf-8')
                ? $text
                : (@mb_convert_encoding($text, 'UTF-8', $charset) ?: $text);
        }

        return $out;
    }

    private function isoDate(string $raw): string
    {
        $timestamp = $raw === '' ? false : strtotime($raw);

        return gmdate('c', $timestamp === false ? time() : $timestamp);
    }

    /**
     * Walk the MIME tree once, collecting the plain part, the HTML part and the
     * attachments.
     *
     * @return array{text:string, html:string, attachments:list<array<string,mixed>>}
     */
    private function walk($connection, int $uid, object $structure, string $prefix = ''): array
    {
        $result = ['text' => '', 'html' => '', 'attachments' => []];

        $parts = $structure->parts ?? [];
        if ($parts === []) {
            $partId = $prefix === '' ? '1' : $prefix;
            $this->collect($connection, $uid, $structure, $partId, $result);

            return $result;
        }

        foreach ($parts as $index => $part) {
            $partId = ($prefix === '' ? '' : $prefix . '.') . ($index + 1);
            if (!empty($part->parts)) {
                $nested = $this->walk($connection, $uid, $part, $partId);
                $result['text'] = $result['text'] !== '' ? $result['text'] : $nested['text'];
                $result['html'] = $result['html'] !== '' ? $result['html'] : $nested['html'];
                $result['attachments'] = array_merge($result['attachments'], $nested['attachments']);
                continue;
            }
            $this->collect($connection, $uid, $part, $partId, $result);
        }

        return $result;
    }

    /** @param array{text:string, html:string, attachments:list<array<string,mixed>>} $result */
    private function collect($connection, int $uid, object $part, string $partId, array &$result): void
    {
        $filename = $this->partFilename($part);
        $disposition = strtoupper((string) ($part->disposition ?? ''));
        $isAttachment = $filename !== '' || $disposition === 'ATTACHMENT';

        if ($isAttachment) {
            $result['attachments'][] = [
                'part_id'   => $partId,
                'filename'  => $filename !== '' ? $filename : 'attachment-' . $partId,
                'mime_type' => $this->mimeType($part),
                'size'      => (int) ($part->bytes ?? 0),
                'encoding'  => (int) ($part->encoding ?? 0),
                'inline'    => $disposition === 'INLINE',
            ];

            return;
        }

        $subtype = strtoupper((string) ($part->subtype ?? ''));
        if ($subtype !== 'PLAIN' && $subtype !== 'HTML') {
            return;
        }

        $raw = @imap_fetchbody($connection, $uid, $partId, FT_UID);
        if ($raw === false) {
            return;
        }
        $decoded = $this->decodeBody($raw, (int) ($part->encoding ?? 0));
        $decoded = $this->toUtf8($decoded, $this->charset($part));

        if ($subtype === 'PLAIN' && $result['text'] === '') {
            $result['text'] = $decoded;
        } elseif ($subtype === 'HTML' && $result['html'] === '') {
            $result['html'] = $decoded;
        }
    }

    private function partFilename(object $part): string
    {
        foreach (['dparameters', 'parameters'] as $bag) {
            foreach ($part->{$bag} ?? [] as $parameter) {
                $attribute = strtolower((string) ($parameter->attribute ?? ''));
                if ($attribute === 'filename' || $attribute === 'name') {
                    return $this->decodeMime((string) $parameter->value);
                }
            }
        }

        return '';
    }

    private function mimeType(object $part): string
    {
        $primary = [0 => 'text', 1 => 'multipart', 2 => 'message', 3 => 'application',
                    4 => 'audio', 5 => 'image', 6 => 'video', 7 => 'other'];
        $type = $primary[(int) ($part->type ?? 7)] ?? 'application';

        return strtolower($type . '/' . ((string) ($part->subtype ?? 'octet-stream')));
    }

    private function charset(object $part): string
    {
        foreach ($part->parameters ?? [] as $parameter) {
            if (strtolower((string) ($parameter->attribute ?? '')) === 'charset') {
                return (string) $parameter->value;
            }
        }

        return 'UTF-8';
    }

    private function decodeBody(string $raw, int $encoding): string
    {
        return match ($encoding) {
            3 => (string) base64_decode($raw, false),
            4 => (string) quoted_printable_decode($raw),
            default => $raw,
        };
    }

    private function toUtf8(string $text, string $charset): string
    {
        $charset = strtoupper(trim($charset));
        if ($charset === '' || $charset === 'UTF-8' || $charset === 'US-ASCII') {
            return $text;
        }
        $converted = @mb_convert_encoding($text, 'UTF-8', $charset);

        return $converted === false ? $text : $converted;
    }

    /** @param list<string> $uids */
    private function uidSequence(array $uids): string
    {
        $clean = [];
        foreach ($uids as $uid) {
            if (ctype_digit((string) $uid)) {
                $clean[] = (string) (int) $uid;
            }
        }

        return implode(',', array_unique($clean));
    }

    /**
     * Only the standard system flags. A caller-supplied keyword would otherwise
     * be concatenated into the IMAP command line.
     *
     * @param list<string> $flags
     * @return list<string>
     */
    private function allowedFlags(array $flags): array
    {
        $allowed = ['\\Seen', '\\Flagged', '\\Answered', '\\Deleted', '\\Draft'];
        $out = [];
        foreach ($flags as $flag) {
            foreach ($allowed as $candidate) {
                if (strcasecmp((string) $flag, $candidate) === 0) {
                    $out[] = $candidate;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $filters */
    private function searchCriteria(array $filters): string
    {
        $criteria = [];
        if (($filters['unread'] ?? false) === true) {
            $criteria[] = 'UNSEEN';
        }
        if (($filters['flagged'] ?? false) === true) {
            $criteria[] = 'FLAGGED';
        }
        if (isset($filters['from']) && is_string($filters['from']) && $filters['from'] !== '') {
            $criteria[] = 'FROM "' . $this->escapeSearch($filters['from']) . '"';
        }
        if (isset($filters['since']) && is_string($filters['since']) && $filters['since'] !== '') {
            $timestamp = strtotime($filters['since']);
            if ($timestamp !== false) {
                $criteria[] = 'SINCE "' . date('d-M-Y', $timestamp) . '"';
            }
        }

        return $criteria === [] ? 'ALL' : implode(' ', $criteria);
    }

    /** Quotes and control characters would break out of the quoted search string. */
    private function escapeSearch(string $term): string
    {
        $clean = str_replace(["\r", "\n", '"', '\\'], ' ', $term);

        return substr(trim($clean), 0, 200);
    }

    private function decodeFolderName(string $name): string
    {
        $decoded = @imap_utf7_decode($name);

        return is_string($decoded) && $decoded !== '' ? $decoded : $name;
    }

    /** Map a server folder onto the role the UI navigates by. */
    private function roleFor(string $name): string
    {
        $normalised = strtolower(trim($name, '/'));

        return match (true) {
            $normalised === 'inbox'                                    => 'inbox',
            str_contains($normalised, 'sent')                          => 'sent',
            str_contains($normalised, 'draft')                         => 'drafts',
            str_contains($normalised, 'junk') || str_contains($normalised, 'spam') => 'spam',
            str_contains($normalised, 'trash') || str_contains($normalised, 'deleted') => 'trash',
            str_contains($normalised, 'archive')                       => 'archive',
            default                                                    => 'custom',
        };
    }

    public function __destruct()
    {
        foreach ($this->connections as $connection) {
            @imap_close($connection);
        }
    }
}
