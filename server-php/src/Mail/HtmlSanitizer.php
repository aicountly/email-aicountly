<?php

declare(strict_types=1);

namespace Aicountly\Api\Mail;

/**
 * Allowlist sanitiser for incoming email HTML.
 *
 * Runs on the SERVER, before the body ever reaches a browser, and it is only
 * half the defence. The other half is that the frontend renders the result in a
 * sandboxed iframe with its own CSP (SecureMessageFrame.tsx), so even a bypass
 * here lands in a document that cannot run script, cannot reach the app's DOM,
 * cannot read its storage and cannot submit a form.
 *
 * The rules:
 *
 *  - ALLOWLIST, never a blocklist. Anything not named below is removed. A
 *    blocklist is a list of the attacks somebody thought of.
 *  - Every attribute is checked too, and any `on*` handler is dropped whatever
 *    the element.
 *  - URLs may only be http, https, mailto or cid. `javascript:`, `data:` and
 *    `vbscript:` are removed, including the whitespace- and entity-obfuscated
 *    spellings.
 *  - <style> and <link> go entirely. Email CSS cannot be allowed to style the
 *    application, and inside the iframe there is nothing of ours to style.
 *  - Remote images are rewritten to a placeholder unless the caller opts in,
 *    because loading one tells the sender the message was opened, and with it
 *    the reader's IP.
 *  - <form>, <iframe>, <object>, <embed>, <script>, <base> and <meta> are
 *    removed with their content.
 */
final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'a', 'abbr', 'b', 'blockquote', 'br', 'caption', 'cite', 'code', 'col', 'colgroup',
        'dd', 'del', 'div', 'dl', 'dt', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3', 'h4',
        'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'ol', 'p', 'pre', 'q', 's', 'small', 'span',
        'strong', 'sub', 'sup', 'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'tr', 'u', 'ul',
    ];

    /** Attributes allowed on any element. */
    private const GLOBAL_ATTRS = ['title', 'dir', 'lang'];

    /** @var array<string, list<string>> */
    private const TAG_ATTRS = [
        'a'     => ['href', 'name'],
        'img'   => ['src', 'alt', 'width', 'height'],
        'td'    => ['colspan', 'rowspan', 'align', 'valign'],
        'th'    => ['colspan', 'rowspan', 'align', 'valign', 'scope'],
        'table' => ['border', 'cellpadding', 'cellspacing', 'align', 'width'],
        'col'   => ['span', 'width'],
        'ol'    => ['start', 'type'],
    ];

    /** Elements whose entire subtree is dropped, not just the tag. */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form',
                                        'noscript', 'template', 'base', 'meta', 'link', 'frame',
                                        'frameset', 'applet', 'svg', 'math'];

    private const SAFE_SCHEMES = ['http', 'https', 'mailto', 'cid', 'tel'];

    /**
     * @param bool $allowRemoteImages honour the account's remote-image policy; false blocks them
     * @return array{html:string, blocked_remote_images:int, removed_elements:int}
     */
    public static function sanitize(string $html, bool $allowRemoteImages = false): array
    {
        if (trim($html) === '') {
            return ['html' => '', 'blocked_remote_images' => 0, 'removed_elements' => 0];
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);

        // LIBXML_NONET: never let the parser fetch an external entity. Without it
        // a crafted DOCTYPE turns opening a message into a request from this
        // server to wherever the sender chose.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            // Unparseable HTML is shown as text rather than guessed at.
            return [
                'html' => '<pre>' . htmlspecialchars(mb_substr($html, 0, 20000), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>',
                'blocked_remote_images' => 0,
                'removed_elements' => 0,
            ];
        }

        $state = ['blocked' => 0, 'removed' => 0];
        self::clean($document, $document, $allowRemoteImages, $state);

        $out = '';
        foreach (iterator_to_array($document->childNodes) as $child) {
            if ($child instanceof \DOMDocumentType || $child instanceof \DOMProcessingInstruction) {
                continue;
            }
            $out .= $document->saveHTML($child);
        }

        return [
            'html' => trim($out),
            'blocked_remote_images' => $state['blocked'],
            'removed_elements' => $state['removed'],
        ];
    }

    /** @param array{blocked:int, removed:int} $state */
    private static function clean(\DOMDocument $document, \DOMNode $node, bool $allowRemoteImages, array &$state): void
    {
        // Snapshot: removing a node while iterating a live NodeList skips its
        // sibling, which is how a sanitiser leaves every second <script> behind.
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if ($child instanceof \DOMText) {
                continue;
            }
            if (!$child instanceof \DOMElement) {
                $node->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::STRIP_WITH_CONTENT, true)) {
                $node->removeChild($child);
                $state['removed']++;
                continue;
            }

            self::clean($document, $child, $allowRemoteImages, $state);

            if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                // Unknown wrapper: keep what it contained, drop the element. A
                // <center> around the whole message should not delete the message.
                while ($child->firstChild !== null) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                $state['removed']++;
                continue;
            }

            self::cleanAttributes($child, $tag, $allowRemoteImages, $state);
        }
    }

    /** @param array{blocked:int, removed:int} $state */
    private static function cleanAttributes(\DOMElement $element, string $tag, bool $allowRemoteImages, array &$state): void
    {
        $allowed = array_merge(self::GLOBAL_ATTRS, self::TAG_ATTRS[$tag] ?? []);

        foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
            $name = strtolower($attribute->nodeName);

            // Every event handler, on every element, whatever it is called.
            if (str_starts_with($name, 'on') || !in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->nodeName);
                continue;
            }

            if ($name === 'href' || $name === 'src') {
                $url = self::safeUrl((string) $attribute->nodeValue);
                if ($url === null) {
                    $element->removeAttribute($attribute->nodeName);
                    $state['removed']++;
                    continue;
                }
                if ($name === 'src' && !$allowRemoteImages && self::isRemote($url)) {
                    // Keep the address so "load images" can restore it, but do
                    // not let the browser request it yet.
                    $element->removeAttribute('src');
                    $element->setAttribute('data-blocked-src', $url);
                    $state['blocked']++;
                    continue;
                }
                $element->setAttribute($attribute->nodeName, $url);
            }
        }

        if ($tag === 'a') {
            // The message renders in a sandboxed iframe, so a link must open in
            // a new context, and noopener/noreferrer stop the opened page from
            // reaching back or learning where it came from.
            $element->setAttribute('target', '_blank');
            $element->setAttribute('rel', 'noopener noreferrer nofollow');
        }
    }

    /**
     * The URL, or null when its scheme is not allowed.
     *
     * Control characters and entities are stripped before the scheme is read:
     * `java\0script:` and `java&#09;script:` are both `javascript:` to a
     * browser and both pass a naive prefix check.
     */
    private static function safeUrl(string $raw): ?string
    {
        $value = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/[\x00-\x20\x7F]/', '', $value) ?? $value;
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Relative and anchor URLs have no scheme and cannot escape the iframe.
        if (str_starts_with($value, '#') || str_starts_with($value, '/')) {
            return $value;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $value, $m) !== 1) {
            return $value;
        }

        return in_array(strtolower($m[1]), self::SAFE_SCHEMES, true) ? $value : null;
    }

    private static function isRemote(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * Plain text to display HTML, for messages that carry no HTML part.
     *
     * Escaped first, then linkified — the other order is how a sanitiser
     * produces the XSS it was written to prevent.
     */
    public static function fromPlainText(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $linked = preg_replace(
            '#(https?://[^\s<]+)#i',
            '<a href="$1" target="_blank" rel="noopener noreferrer nofollow">$1</a>',
            $escaped,
        ) ?? $escaped;

        return '<pre class="plain">' . $linked . '</pre>';
    }

    /** A short preview for the list, with no markup at all. */
    public static function snippet(string $html, string $text, int $length = 160): string
    {
        $source = trim($text) !== '' ? $text : strip_tags($html);
        $collapsed = trim(preg_replace('/\s+/u', ' ', $source) ?? $source);

        return mb_substr($collapsed, 0, $length);
    }
}
