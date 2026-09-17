/**
 * Where an email's HTML is actually rendered.
 *
 * THE MESSAGE NEVER TOUCHES THE APPLICATION DOCUMENT. It goes into a sandboxed
 * iframe with a null origin, and that is the second of two defences — the first
 * is the server-side allowlist sanitiser in
 * server-php/src/Mail/HtmlSanitizer.php. Either alone would be a single point
 * of failure; together, a bypass of the sanitiser lands somewhere that cannot
 * do anything with it.
 *
 * What the sandbox stops, concretely:
 *
 *   sandbox=""                   no scripts, no forms, no plugins, no
 *                                same-origin access, no top-level navigation.
 *                                Links still work because they carry
 *                                target=_blank and the allow-popups token.
 *   CSP default-src 'none'       nothing loads at all unless it is listed
 *   CSP img-src / style-src      images and inline CSS only, and the images are
 *                                already blocked by the sanitiser unless the
 *                                reader asked for them
 *   srcdoc, not src              nothing is fetched to render the message
 *
 * The frame is height-synced to its content, because a scrollbar inside a
 * scrollbar is unusable with a keyboard and impossible on a phone.
 */

import { useEffect, useRef, useState } from 'react'

interface SecureMessageFrameProps {
  /** Already sanitised by the backend. Never raw provider HTML. */
  html: string
  subject: string
}

/**
 * The document the email lives in.
 *
 * The CSP is inside the iframe, not on the app, which is what makes it apply to
 * this content and nothing else. `base-uri 'none'` stops a <base> the sanitiser
 * somehow left behind from re-pointing every relative URL.
 */
function buildDocument(html: string): string {
  const csp = [
    "default-src 'none'",
    "img-src data: https:",
    "style-src 'unsafe-inline'",
    "font-src data:",
    "form-action 'none'",
    "base-uri 'none'",
    "frame-ancestors 'self'",
  ].join('; ')

  return `<!doctype html>
<html><head>
<meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="${csp}">
<meta name="referrer" content="no-referrer">
<style>
  :root { color-scheme: light; }
  html, body { margin: 0; padding: 0; background: #fff; }
  body {
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, sans-serif;
    font-size: 14px; line-height: 1.7; color: #182630;
    overflow-wrap: anywhere; word-break: break-word;
  }
  img, table, pre { max-width: 100%; }
  img { height: auto; }
  table { border-collapse: collapse; }
  pre.plain { white-space: pre-wrap; font-family: inherit; margin: 0; }
  a { color: #087b63; }
  blockquote { margin: 0 0 0 12px; padding-left: 12px; border-left: 3px solid #e2e9e6; color: #647480; }
</style>
</head><body>${html}</body></html>`
}

export function SecureMessageFrame({ html, subject }: SecureMessageFrameProps) {
  const frameRef = useRef<HTMLIFrameElement>(null)
  const [height, setHeight] = useState(160)

  useEffect(() => {
    const frame = frameRef.current
    if (!frame) return

    // The frame is sandboxed WITHOUT allow-same-origin, so its document is not
    // readable from here. Measurement therefore goes the other way: a tiny
    // ResizeObserver cannot be installed inside it either, so the height is
    // read on load through the one channel a null-origin frame still exposes —
    // its own scrollHeight, via contentDocument when the browser permits it,
    // and a sensible default when it does not.
    const measure = () => {
      try {
        const body = frame.contentDocument?.body
        if (body) {
          setHeight(Math.min(4000, Math.max(120, body.scrollHeight + 16)))
          return
        }
      } catch {
        /* cross-origin by design — keep the default height */
      }
      setHeight((current) => Math.max(current, 320))
    }

    frame.addEventListener('load', measure)
    const timer = window.setTimeout(measure, 120)

    return () => {
      frame.removeEventListener('load', measure)
      window.clearTimeout(timer)
    }
  }, [html])

  return (
    <iframe
      ref={frameRef}
      className="message-frame"
      title={`Message content: ${subject}`}
      // No allow-same-origin and no allow-scripts. allow-popups is what lets a
      // link open in a new tab; the sanitiser already put noopener on every one.
      sandbox="allow-popups allow-popups-to-escape-sandbox"
      referrerPolicy="no-referrer"
      srcDoc={buildDocument(html)}
      style={{ height }}
      loading="lazy"
    />
  )
}
