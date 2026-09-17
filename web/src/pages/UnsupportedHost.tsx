import { ShieldAlert } from 'lucide-react'
import { PRODUCT, PRODUCTION_ORIGINS } from '../config/frontend.ts'

/**
 * What a hostname nobody configured gets.
 *
 * Failing closed is the point. This bundle is a mail client with a live session
 * to somebody's inbox; serving it from a domain that was never reviewed is
 * either a misconfiguration or somebody rehosting it, and in both cases the
 * right answer is to render nothing and say where the real product lives.
 */
export function UnsupportedHost({ hostname, reason }: { hostname: string; reason: string | null }) {
  return (
    <main className="host-error">
      <div className="host-error__panel">
        <ShieldAlert size={28} aria-hidden />
        <h1>{PRODUCT.name} is not served from this address</h1>
        <p className="muted">
          {reason ?? 'This hostname is not configured for Aicountly Email.'} The application will not start here.
        </p>
        <p className="muted">
          You reached <code>{hostname || 'an unknown host'}</code>.
        </p>

        <div>
          <h2>Where to go instead</h2>
          <ul className="muted" style={{ paddingLeft: 18 }}>
            <li>
              Business — <a href={PRODUCTION_ORIGINS.business}>{PRODUCTION_ORIGINS.business}</a>
            </li>
            <li>
              Personal — <a href={PRODUCTION_ORIGINS.personal}>{PRODUCTION_ORIGINS.personal}</a>
            </li>
          </ul>
        </div>

        <p className="source-note">
          If you are an administrator and this host is meant to serve Email, add it to the reviewed host list in
          <code> web/src/config/frontend.ts</code> and rebuild. Hosts are never matched by pattern.
        </p>
      </div>
    </main>
  )
}
