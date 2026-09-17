# Deploying Aicountly Email

## What goes where

```
web/          React app (Vite). Builds to web/dist.
server-php/   The Email API. Plain PHP, no build step — deployed as-is.
scripts/      Release stamping, live verification, the ownership audit.
```

| Workflow | Deploys | Destinations |
|---|---|---|
| Deploy to cPanel Production | one build → both frontends; the API once | https://email.aicountly.com and https://aicountly.io |
| Deploy to cPanel Sandbox | one build → one frontend; the API | https://email.gh.aicountly.com |
| Roll back a production release | the previous root `index.html` | one destination at a time |

**Deployment is manual.** `workflow_dispatch` is the only trigger that deploys;
merging never does. The production workflow also runs on `pull_request`, where
it builds and tests and stops before any deploy step.

## One build, two destinations

The same artifact is deployed to both. It has to be: the hostname selects the
presentation at runtime (`web/src/config/frontend.ts`), so a second build for
the second domain would be a second thing to keep in step for no benefit.

The **backend and its migrations deploy once**, with the business destination.
Running migrations per frontend would run them twice against one database.

Two hosts cannot be updated as one transaction. `fail-fast` is off, each
destination reports its own result, and the Report job says so explicitly. A
partial deployment is a real outcome, not a reporting bug.

## The release layout on the server

```
<document root>/
  index.html                     ← the live release. Replaced by ONE atomic rename.
  .htaccess                      ← SPA fallback, /releases/ exclusion, caching
  api/                           ← server-php, and its hand-written .env
  releases/
    <commit-sha>/                ← immutable. Never deleted by a deploy.
      assets/… apps/…
      index.html
      previous-index.html        ← what this release replaced. The rollback target.
```

Why it is shaped like this:

* **Assets are addressed by commit**, so two releases coexist and a browser that
  loaded the old `index.html` keeps working across a deploy.
* **Nothing is deleted.** No `--delete` against the document root, so a rollback
  always has assets to roll back to.
* **`index.html` is activated last**, by copying into
  `.index-<sha>.pending` and `mv`-ing it over the live file. `mv` within one
  filesystem is atomic, so no request ever sees a partial document.
* **`.htaccess` is excluded from the release directory** and sent to the
  document root separately. Inside `releases/` its SPA fallback would answer a
  missing asset with the application instead of a 404 — and the browser would
  then try to execute HTML as JavaScript.

### Migrating an existing document root

The first deploy under this scheme leaves the previous `assets/` and `apps/`
directories at the document root. They are harmless — nothing references them
once the new `index.html` is live — and can be removed by hand later. They are
deliberately not deleted automatically, because a deploy that deletes files it
did not put there is a deploy that will one day delete the wrong ones.

## Required GitHub configuration

### Environments

| Environment | Destination |
|---|---|
| `email-business` | https://email.aicountly.com |
| `email-personal` | https://aicountly.io |

Each supplies its own **secrets**:

| Secret | Notes |
|---|---|
| `SSH_HOST` | the cPanel host |
| `SSH_PORT` | optional, defaults to 22 |
| `SSH_USER` | the cPanel account username |
| `SSH_PRIVATE_KEY` | the whole OpenSSH key, BEGIN and END lines included |
| `SSH_REMOTE_ROOT` | the exact document root, e.g. `public_html` |

The **business** destination falls back to the repository-level `PROD_SSH_*`
secrets that already exist, so nothing has to be reconfigured for it. The
**personal** destination has no fallback, deliberately: inheriting the business
credentials would deploy the personal build over the business site.

A destination with **none** of its secrets set is skipped with a warning and
named in the summary. A destination with **some** of them set fails, because a
half-configured target is how a deploy lands somewhere unintended.

### Repository secrets and variables

| Name | Kind | Purpose |
|---|---|---|
| `PROD_SSH_HOST`, `PROD_SSH_PORT`, `PROD_SSH_USER`, `PROD_SSH_PRIVATE_KEY`, `PROD_SSH_REMOTE_ROOT` | secret | existing business credentials; still valid |
| `SANDBOX_SSH_*` | secret | the same five for sandbox |
| `PROD_PHP_BIN`, `SANDBOX_PHP_BIN` | secret | full path to a PHP 8.1+ with `pdo_pgsql`, if the default search fails |
| `PROD_API_BASE_URL`, `SANDBOX_API_BASE_URL` | variable | optional; points BOTH destinations at one backend |
| `PROD_GA4_MEASUREMENT_ID` | variable | optional GA4 id |

## Hosting prerequisites

On each frontend document root:

1. **Apache with `mod_rewrite` and `mod_headers`.** The shipped `.htaccess`
   provides the SPA fallback, the `/releases/` exclusion, no-cache on
   `index.html` and immutable caching on hashed assets. If the server carries
   hand-written rules as well, merge them into `web/public/.htaccess` — the copy
   on the server is replaced on every deploy.
2. **SSH with key authentication.** cPanel imports and authorises keys as two
   separate actions: after importing, SSH Access → Manage SSH Keys → Manage →
   Authorize. An imported-but-unauthorised key fails as "Permission denied
   (publickey)".
3. **TLS.** The verifier does not disable certificate validation.

On the API host, additionally:

4. **PHP 8.1+** with `pdo_pgsql`, `curl`, `openssl`, `mbstring`, `dom` and
   `imap`. Without `imap` the mail store reports itself unconfigured and says
   so, which is honest but not a working mailbox.
5. **PostgreSQL**, reachable with the credentials in `api/.env`.
6. **`api/.env`**, created once by hand from `api/.env.example`. It is never
   uploaded and never deleted.
7. **A cron entry** for scheduled sends:
   ```
   * * * * * /usr/local/bin/php /home/<user>/public_html/api/bin/dispatch-scheduled.php >/dev/null 2>&1
   ```

## First deploy, in order

1. Create the `email-business` and `email-personal` environments and set their
   secrets (business may rely on the existing `PROD_SSH_*`).
2. **Actions → Deploy to cPanel Production → Run workflow → targets: business.**
3. On the server: `cp api/.env.example api/.env`, fill it in, re-run so the
   migrations apply.
4. Check `https://email.aicountly.com/api/health` — `usable: true` means the
   database and the mail store are both ready.
5. Configure the `email-personal` environment.
6. **Run workflow → targets: both.**

## Verification

`scripts/verify-release.py` runs against each live origin after its deploy and
fails the job on any of:

* the release marker in the served document does not match the commit
* a referenced script or stylesheet does not load, or loads with the wrong
  content type
* a deep link (`/auth/callback`) does not serve the application
* a missing file under `/releases/` does **not** return 404
* `index.html` is cached
* a development fixture marker is in the served document
* a stale service worker is present at `/sw.js`

A release marker on its own is not a smoke test, which is why the rest are
there.

## Rolling back

**Actions → Roll back a production release → Run workflow**, naming the
destination and the release currently live.

It refuses if that destination has moved on since — if somebody has deployed in
the meantime, restoring an older index would undo their release without either
of you noticing. `force: true` overrides that, deliberately awkwardly.

It restores `releases/<sha>/previous-index.html` with one atomic rename, keeps a
copy of the index it rolled away from so the rollback is itself reversible, and
**deletes nothing**.

It does **not** roll back the API or the database. A bad migration needs a
forward fix; putting an old `index.html` back would leave new data under old
code.

## Why rsync over SSH rather than SFTP

The reference design for this work assumed SFTP-only access. This hosting
demonstrably has an SSH shell — the existing workflow has run remote `bash` for
migrations since before this change — so rsync is the better tool: it transfers
only what changed, it can verify what it sent, and `mv` gives a genuinely atomic
activation that an SFTP client cannot always guarantee.

If a destination is ever SFTP-only, the activation step is the only part that
needs replacing: upload to `.index-<sha>.pending` and `posix_rename` it onto
`index.html`. Everything else in the layout already works over SFTP.

## Local development

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev          # http://localhost:5173, business experience
```

`VITE_DEV_FRONTEND_MODE=personal` renders the personal experience on localhost.
Point `VITE_API_BASE_URL` at the deployed sandbox API and add
`http://localhost:5173` to `CORS_ALLOWED_ORIGINS` in that server's `api/.env`.

```bash
cd server-php
cp .env.example .env      # set APP_ENV=local
php -S localhost:8000
php bin/migrate.php --status
php tests/run.php
```

| Script | Purpose |
|---|---|
| `npm run dev` | Vite dev server |
| `npm run build` | Type-check, then build to `web/dist/` |
| `npm run typecheck` | Type-check only |
| `npm run test` | The frontend test suite (no browser needed) |
| `php server-php/tests/run.php` | The backend test suite (no database needed) |
| `python3 scripts/audit-data-ownership.py` | The cross-app replication audit |
