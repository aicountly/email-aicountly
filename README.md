# Aicountly Email

**Every conversation moves business forward.**

A mail client that knows what the rest of your business already knows. One React
application, one PHP API, one PostgreSQL schema, served to two destinations:

| | Experience | |
|---|---|---|
| https://email.aicountly.com | Business | shared mailboxes, delegation, company context, product actions |
| https://aicountly.io | Personal | summaries, drafting, reminders, personal Calendar and Contacts |
| https://email.gh.aicountly.com | Business (sandbox) | |

Aicountly Interactive Services Private Limited.

## These are not two products

The hostname selects a **presentation**. It grants nothing.

```
hostname  ──▶  web/src/config/frontend.ts   ──▶  which layout
backend   ──▶  GET /api/v1/capabilities     ──▶  what this account may actually do
```

A business user who opens aicountly.io keeps their shared mailboxes and sees the
personal layout. A personal user who opens email.aicountly.com sees the business
layout with the business features absent and the reason given. Both are tested.

A hostname nobody configured renders an error screen and never starts the
application.

**A mailbox address is not an account type.** A business account may send from
`@aicountly.io` or from its own custom domain.

## The rule this product is built around

> Business data owned by another AICOUNTLY product is **read live** over its API
> and never copied into Email's database.

No synchronisation cron, no ETL, no mirror tables, no foreign database
connection, no local substitute for Contacts or Calendar or Books. Every
business figure on screen carries its source and the time it was read, and is
re-read when the screen is reopened.

`scripts/audit-data-ownership.py` runs in CI and fails the build if that ever
stops being true. See [docs/EMAIL_DATA_OWNERSHIP.md](docs/EMAIL_DATA_OWNERSHIP.md).

## What Pulse does, and what it refuses to do

| Does | Refuses to |
|---|---|
| Counts what needs you, each count linking to the rows behind it | Invent a "time saved" figure, or total money from email text |
| Classifies a thread and says **why**, quoting the phrase | Guess a deadline. "No deadline found." is a first-class answer |
| Extracts promises as **proposals** | Turn an incoming proposal into an agreement. Only a person does that |
| Compares an email against a live business record | Subtract two figures that are not on the same basis. It says "Comparison requires review" and names what differs |
| Drafts, shortens, rewrites, translates | Send anything. A generated draft stays a draft |
| Reports SPF, DKIM and DMARC | Call a sender safe. A compromised account passes all three |
| Previews an action, then executes it on approval | Act on an email that tells it to |
| Reads the briefing aloud on request | Autoplay, or open a microphone |

Arithmetic is `server-php/src/Pulse/Comparison.php` — ordinary code, the same
answer every time, pinned by tests. The model writes prose and picks from a
fixed list; it has no tools, no database and no permissions.

## Sending says exactly what happened

```
draft saved → queued → accepted by the outbound server
                    ↘  deferred   (the same message will be retried)
                    ↘  failed     (a permanent refusal, with the reason)
                    ↘  uncertain  (the connection died after the body was written)
```

There is no "delivered". Nothing in this deployment observes delivery, so
claiming it would be a lie the user acts on.

An **uncertain** outcome stops and asks. Retrying it automatically sends the
same invoice twice; failing it automatically loses a message that was sent.

## Layout

```
design/           the approved visual reference — never deployed
web/              React 19 + Vite 8 + TypeScript → web/dist
server-php/       the Email API. Plain PHP, no composer, no build step
scripts/          release stamping, live verification, the ownership audit
docs/             architecture, ownership, the API contract, integrations, security
```

## Getting started

Node.js 22 or newer, PHP 8.1 or newer.

```bash
cd web
npm install
cp ../.env.example ../.env
npm run dev                 # http://localhost:5173
```

```bash
cd server-php
cp .env.example .env        # set APP_ENV=local
php -S localhost:8000
```

| Command | Purpose |
|---|---|
| `npm run dev` | Vite dev server |
| `npm run build` | Type-check, then build |
| `npm run typecheck` | Type-check only |
| `npm run test` | Frontend tests — no browser needed |
| `php server-php/tests/run.php` | Backend tests — no database needed |
| `php server-php/bin/migrate.php --status` | What migrations are pending |
| `python3 scripts/audit-data-ownership.py` | The cross-app replication audit |

Open `design/email-workspace.html` in a browser to see the approved design. It
is a scaffold, not the application: every value in it is illustrative, and the
CI audit fails the build if one of them reaches the bundle.

## Environment variables

Two files, and they work in opposite ways.

| File | Read | Used by |
|---|---|---|
| `.env.example` | **Build time**, inlined into the bundle | `web/` |
| `server-php/.env.example` | **Runtime**, on every request | `server-php/` |

Only `VITE_`-prefixed variables reach the browser, and Vite inlines them at
build time — **treat every one of them as public.** Mail-server passwords, the
model key, service keys and the database password all live in
`server-php/.env`, which is created once by hand on the server and is never
uploaded and never deleted by a deploy.

## Deployment

Manual only. **Actions → pick a workflow → Run workflow.**

One build is stamped with the commit and deployed to both destinations
independently; assets live under `releases/<sha>/` and are never deleted, so an
open browser session survives a deploy and a rollback has something to restore.
The backend and its migrations deploy once.

See [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) for the required environments,
secrets, hosting prerequisites and the rollback procedure.

## Documentation

| | |
|---|---|
| [EMAIL_ARCHITECTURE.md](docs/EMAIL_ARCHITECTURE.md) | how the pieces fit |
| [EMAIL_DATA_OWNERSHIP.md](docs/EMAIL_DATA_OWNERSHIP.md) | what Email owns, and what it must never keep |
| [EMAIL_API_CONTRACT.md](docs/EMAIL_API_CONTRACT.md) | the v1 contract |
| [EMAIL_INTEGRATIONS.md](docs/EMAIL_INTEGRATIONS.md) | what is contracted, and what is waiting for one |
| [EMAIL_SECURITY.md](docs/EMAIL_SECURITY.md) | what is defended, and what is not claimed |
| [DEPLOYMENT.md](docs/DEPLOYMENT.md) | environments, prerequisites, rollback |
| [auth/AICOUNTLY_AUTH_WORKFLOW.md](docs/auth/AICOUNTLY_AUTH_WORKFLOW.md) | the portal sign-in flow |
