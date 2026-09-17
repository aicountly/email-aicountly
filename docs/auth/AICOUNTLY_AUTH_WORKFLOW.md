# AICOUNTLY auth workflow (Email)

How Email signs a user in. This is the shared AICOUNTLY SaaS flow — the same
one Smart Books and the other products use — reduced to what a blank app needs.
The canonical implementation lives on **my.aicountly.com**; nothing here mints,
signs or stores a credential of its own.

## Tokens

| Token | Lifetime | Storage | Use |
|-------|----------|---------|-----|
| `auth_token` | Long-lived | `localStorage` + a `.aicountly.com` cookie | Mint / refresh a `ses_key` |
| `ses_key` | ~15 minutes | **Memory only** | `Authorization: Bearer` on product APIs |

`ses_key` must **never** be written to `localStorage` or `sessionStorage`. In
this app it lives in a module variable in `web/src/auth/tokens.ts` and dies with
the page.

The `auth_token` cookie is scoped to `.aicountly.com` on purpose:
`localStorage` is origin-scoped, so without the cookie a user arriving from
another AICOUNTLY product would have to sign in again.

## Login flow

1. User opens any configured Email host — `email.aicountly.com`,
   `aicountly.io`, or their sandbox equivalents.
2. No `auth_token` → redirect to
   `{portal}/login/authentication_jump/email?returnUrl={origin}/auth/callback`.
   The portal reuses an existing portal web session — this is what makes moving
   between AICOUNTLY products seamless. With no session it shows its login form.
3. Portal redirects back to `/auth/callback?auth_token=…`. The SPA history
   fallback in `web/public/.htaccess` serves the app at that path, and
   `AuthProvider` reads the token at boot and clears it from the URL.
4. App stores `auth_token`, then `POST /api/global/seskey` with
   `Bearer auth_token` → `ses_key`.
5. `GET /api/v1/capabilities` decides what this account may do. The hostname has
   already decided which layout to draw; this decides the privileges, and the
   two are not allowed to disagree.
6. Mailbox.

Logout clears both tokens and the shared cookie, tells the portal to invalidate
the `auth_token`, and navigates to `{portal}/login/logout` so the portal's own
session cookie goes too. Skipping that last step leaves the portal session
alive and the next visit signs the user straight back in.

## Host mapping

| Email host | Experience | Login redirect | Auth API | Product API |
|---|---|---|---|---|
| `email.aicountly.com` | business | `my.aicountly.com` | `my.aicountly.com` | same origin + `/api` |
| `aicountly.io` | personal | `my.aicountly.com` | `my.aicountly.com` | the common Email backend, cross-origin |
| `email.gh.aicountly.com` | business (sandbox) | `sandbox.aicountly.com` | `my.aicountly.com` | same origin + `/api` |
| `io.gh.aicountly.com` | personal (sandbox) | `sandbox.aicountly.com` | `my.aicountly.com` | the common Email backend, cross-origin |

## Two domains, one identity

`aicountly.io` is a different registrable domain from `aicountly.com`, and that
changes three things.

**The shared SSO cookie does not apply there.** `getSharedCookieDomain()`
returns `null` for any host outside `.aicountly.com`, so nothing is written and
nothing is read. This is correct, not a gap: a cookie scoped to one domain
cannot be read from another, and the personal frontend must not depend on a
third-party cookie that browsers are removing anyway. A user arriving at
`aicountly.io` signs in through the portal; a user arriving at
`email.aicountly.com` from another AICOUNTLY product lands already signed in.

**The portal must accept a second redirect URI.** The jump is
`{portal}/login/authentication_jump/email?returnUrl={origin}/auth/callback`, and
`{origin}` is now one of four. Each must be registered at my.aicountly.com:

```
https://email.aicountly.com/auth/callback
https://aicountly.io/auth/callback
https://email.gh.aicountly.com/auth/callback
https://io.gh.aicountly.com/auth/callback
```

An unregistered redirect URI is the single most likely cause of a sign-in that
loops or returns an `auth_error`.

**The product key does not come from the hostname on `.io`.**
`resolveProductKeyFromHost()` reads `email` out of `email.aicountly.com` and
`email.gh.aicountly.com`; `aicountly.io` matches no pattern and falls back to
`PRODUCT_KEY`, which is `email`. That fallback is load-bearing for the personal
frontend — do not remove it.

## Calling the API cross-origin

The business frontend is same-origin with its API. The personal one is not, so
its requests are genuine CORS requests.

* The allowlist in `server-php/index.php` is **exact and hard-coded**. An
  unlisted origin receives no CORS headers at all, and the browser blocks the
  response.
* `Access-Control-Allow-Credentials` is deliberately **not** sent. The session
  travels as `Authorization: Bearer <ses_key>`, so the browser has no cookie to
  attach — which also means there is no CSRF surface to defend.
* `Vary: Origin` is always sent, so a proxy cannot serve one origin's response
  to another.
* The `ses_key` lives in a module variable and dies with the page. It is never
  written to `localStorage`, `sessionStorage` or a URL.

## Sandbox and production share an auth API

**`sandbox.aicountly.com` is for the login redirect only.** `seskey`,
`seskey/refresh` and `validatesession` always answer on `my.aicountly.com`, in
sandbox as well as production. Pointing a sandbox build at
`sandbox.aicountly.com` for those calls is the usual way to break sandbox
sign-in.

One build serves every environment and both experiences:
`resolveProductKeyFromHost()` reads `email` out of a `*.aicountly.com` hostname
and falls back to it elsewhere, `isSandboxHost()` picks the portal, and
`resolveFrontendMode()` picks the layout.

## Why the calls go through this product's own API

The browser calls `/api/global/seskey` on its **own origin**, and `server-php`
relays that to `my.aicountly.com` server-to-server.

A brand-new product domain is not in the portal's CORS allowlist on day one, so
a direct browser call would fail with nothing but a CORS message to show for it.
The relay sidesteps that entirely. The app still falls back to calling the
portal directly if the relay is missing — useful before the API is deployed.

The relay is an **allowlist** (`RELAYED_PATHS` in `server-php/index.php`):
`seskey`, `seskey/refresh`, `refresh_authtoken`. Forwarding arbitrary paths
would turn this host into an open proxy for the portal's whole auth surface,
with the portal seeing this server's IP instead of the caller's.

## This product's backend

- Does **not** issue `auth_token` or `ses_key`.
- Does **not** implement `/api/seskey`, `/api/seskey/refresh` or `/api/logout` —
  it only relays the first two.
- Validates a caller by `POST https://my.aicountly.com/api/validatesession` with
  the Bearer `ses_key`; `status: 1` means the session is live. A transport
  failure counts as *not* authenticated, so a portal outage denies access rather
  than granting it.

`GET /api/session` is the one protected endpoint, and exists so the flow can be
verified end to end in each environment.

## Verifying an environment

```bash
# 1. The API is up and says which environment it is
curl https://email.gh.aicountly.com/api/health

# 2. The relay reaches the portal (401 without a token is the correct answer —
#    a 404 means the API is not deployed, a 504 means it cannot reach the portal)
curl -i -X POST https://email.gh.aicountly.com/api/global/seskey

# 3. Unrelayed paths are refused
curl -i -X POST https://email.gh.aicountly.com/api/global/login   # expect 404
```

```bash
# 4. The personal frontend's cross-origin preflight is accepted
curl -i -X OPTIONS https://email.apis.aicountly.com/api/v1/capabilities \
  -H 'Origin: https://aicountly.io' \
  -H 'Access-Control-Request-Method: GET' \
  -H 'Access-Control-Request-Headers: authorization'   # expect 204 + an exact
                                                       # Access-Control-Allow-Origin

# 5. An origin nobody configured gets no CORS headers at all
curl -i -X OPTIONS https://email.apis.aicountly.com/api/v1/capabilities \
  -H 'Origin: https://evil.test' -H 'Access-Control-Request-Method: GET'
```

In the browser: open the site, expect a jump to the portal, sign in, expect to
land in the mailbox. Then press **Log out** and confirm that reopening the site
does **not** sign you straight back in. Repeat on the other destination — the
two are separate origins and a redirect URI registered for one is not
registered for the other.
