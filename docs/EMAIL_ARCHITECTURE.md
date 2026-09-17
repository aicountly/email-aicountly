# Aicountly Email — architecture

**Aicountly Email** — *every conversation moves business forward.*
Aicountly Interactive Services Private Limited.

## Two destinations, one application

| Destination | Experience | API |
|---|---|---|
| https://email.aicountly.com | Business | same origin + `/api` |
| https://aicountly.io | Personal | the common Email backend, cross-origin |
| https://email.gh.aicountly.com | Business (sandbox) | same origin + `/api` |

These are **not two products**. One repository, one React application, one
backend, one database. The hostname selects a **presentation**; it grants
nothing.

```
  hostname  ──▶  web/src/config/frontend.ts  ──▶  business | personal layout
  backend   ──▶  GET /api/v1/capabilities    ──▶  what this account may do
```

`capabilities` is the only thing the UI gates real privileges on. A business
user who opens aicountly.io keeps their shared mailboxes and gets the personal
layout; a personal user who opens email.aicountly.com gets the business layout
with the business features absent and the reason shown. Both cases are tested.

An unrecognised hostname renders an error screen and never starts the
application. Host matching is a table, never a pattern:
`email.aicountly.com.attacker.test` is not a production host.

**A mailbox address is not an account type.** A business account may send from
`@aicountly.io` or from its own custom domain. Nothing infers anything from the
suffix.

## Layout

```
design/           the approved visual reference (never deployed)
web/              React 19 + Vite 8 + TypeScript. Builds to web/dist.
server-php/       the Email API. Plain PHP, no composer, no build step.
scripts/          release stamping, live verification, the ownership audit
docs/             this file and its siblings
```

## The frontend

```
web/src/
  config/frontend.ts     the host table, the mode, the API base URL
  config/buildEnv.ts     reads import.meta.env without assuming a bundler
  services/api.ts        Bearer ses_key, one 401 retry, correlation ids
  services/email.ts      every endpoint the app calls, in one place
  state/SessionProvider  capabilities, mailboxes, company scope, scopeEpoch
  shell/                 rail, navigation, top bar, briefing, Pulse
  components/            list, reading pane, composer, dialogs, state views
  styles/email.css       the same tokens as design/email-workspace.css
```

Panes by width, and the breakpoints match the reference stylesheet:

| Width | Layout |
|---|---|
| ≥ 1250px | navigation · list · reading pane · Pulse |
| 950–1250px | navigation · list · reading pane, Pulse as a right drawer |
| 640–950px | list **or** reading pane, navigation behind a trigger |
| < 640px | one pane, Back navigation, full-screen compose |

## The backend

Plain PHP on the pattern every new AICOUNTLY product uses — no composer, no
framework, deployed by rsync.

```
server-php/
  index.php              health, the portal auth relay, then the v1 router
  src/Router.php         {segment} patterns, 405 vs 404
  src/Auth.php           WHO is calling (portal ses_key, or a service key)
  src/Account.php        WHAT this account is entitled to
  src/MailboxAccess.php  WHICH mailbox they may open, and to do what
  src/Context.php        the company scope, verified against Manage
  src/Mail/              MailStore + MailTransport, and their adapters
  src/Pulse/             classification, commitments, comparison, deadlines
  src/Ai/AiClient.php    the one place a model is called
  src/Actions/           the action allowlist, preview, approval, execution
  src/Integrations/      the registry of sibling products
  src/Clients/           typed live clients for the products with contracts
```

Three separate questions, three separate classes: `Auth` says who, `Account`
says what they are entitled to, `MailboxAccess` says which mailbox. Collapsing
them is how a route ends up trusting an id from a URL.

Routes are nested under their mailbox — `/v1/mailboxes/{id}/threads/{key}` —
so the mailbox check is on the path to every resource.

## Mail

`MailStore` and `MailTransport` are interfaces. The IMAP and SMTP
implementations are real; the `Unconfigured*` ones refuse honestly and are what
a deployment with no mail infrastructure gets.

An unconfigured store returns **no messages and an error**, never an empty
inbox. The difference is the difference between a product that says it is not
finished and one that appears to have lost your mail.

Sending distinguishes four outcomes, and `delivered` is not one of them:

```
draft saved → queued → accepted by the outbound server
                    ↘  deferred (retry the same message)
                    ↘  failed (permanent refusal)
                    ↘  uncertain (the connection died after DATA)
```

An **uncertain** outcome stops and asks. Automatically retrying it sends the
same invoice twice; automatically failing it loses a message that was sent.

## Pulse

| Feature | How |
|---|---|
| Briefing | counts of rows that exist, each one linkable. No time-saved metric, no money total. |
| Decision inbox | rules first, model second, from a fixed class list, with the reason and the source. |
| Commitment radar | proposals stay proposals. Only a person moves one to confirmed. |
| Email vs record | the record is read live; the arithmetic is `Pulse/Comparison.php` and is not a model. |
| Reply assistant | produces a **draft**, marked `ai_suggested`, sent only by a person. |
| Payment alert | reports what changed and what the MTA concluded. Never says "safe". |
| Voice | user-triggered playback with a transcript. No autoplay, no microphone. |

The model has no tools, no database, no URLs and no permissions. Its answer is
validated against a schema. An action it suggests still has to be looked up in
`ActionCatalog`, previewed, and approved by a person.

## Deployment

One build, stamped with the commit, deployed to both destinations
independently. Assets live under `releases/<sha>/` and are never deleted, so an
open browser session survives a deploy and a rollback has something to restore.
The root `index.html` is activated last, with one atomic rename.

The backend and its migrations deploy **once**, with the business destination.

See [DEPLOYMENT.md](DEPLOYMENT.md).
