# Email API — v1 contract

Base URL: this product's own origin + `/api`, or the common Email backend.
See [EMAIL_ARCHITECTURE.md](EMAIL_ARCHITECTURE.md) for how a frontend resolves it.

## Envelopes

```jsonc
// one thing
{ "data": { … }, "meta": { … }, "correlation_id": "a1b2c3d4e5f6a7b8" }

// a page
{ "data": [ … ],
  "meta": { "limit": 40, "cursor": null, "next_cursor": "1198",
            "has_more": true, "total": null },
  "correlation_id": "…" }

// an error
{ "error": { "code": "mailbox_unavailable", "message": "…",
             "details": { "retryable": true } },
  "message": "…", "correlation_id": "…" }
```

`meta.total` is usually `null` and that is deliberate: counting a mailbox to
render one page is a full scan the user never asked for, and a wrong total is
worse than no total.

## Authentication

| Caller | Header |
|---|---|
| A person | `Authorization: Bearer <ses_key>` — validated at my.aicountly.com |
| A sibling product | `X-Service-Key: <key>` plus `X-Actor-Uuid: <uuid>` |

A service key authenticates a **product**. It never authorises a mailbox:
`MailboxAccess` runs identically for both kinds of caller, and a service caller
can neither approve an action nor confirm a commitment.

## Cursors

Cursor, not offset. `meta.next_cursor` is opaque (today, the lowest IMAP UID on
the page). A mailbox changes while it is being read, and offset paging shows
some messages twice and skips others as new mail arrives.

## Correlation

Every request may send `X-Correlation-Id`; every response carries one, in the
header and in the body. It is sanitised on the way in — it reaches logs and a
header, and an unfiltered value there is a log-injection bug.

## Rate limits

Per account, per hour unless stated: `ai` 120, `send` 200, `action` 60,
`search` 120 per 5 minutes. A 429 carries `X-RateLimit-Limit`,
`X-RateLimit-Remaining` and `X-RateLimit-Reset`, and
`GET /v1/settings` reports current usage so a limit is visible before it is hit.

The limiter **fails open** on a database error and logs it. A limiter that
takes the product down when its own table is unavailable has converted a cost
problem into an outage.

## Idempotency

`Idempotency-Key` on a write. The key is minted by the client **when the
composer opens**, not when Send is pressed, and the same value is presented on
every retry — that is what makes a double click, a retried request or a reloaded
tab a replay rather than a second message.

Server-side, the send job row carrying that key is written **before** the
transport is touched.

## Concurrency

A draft carries a `version`. A `PATCH` presenting the wrong one answers `409`
with `details.current` holding the server's copy, so the UI can show both.
Last-write-wins loses whichever tab was slower, silently.

## Routes

Resources are nested under their mailbox. A flat `/v1/threads/{id}` makes it
easy to write a handler that trusts the id, and one such handler is a
cross-tenant read.

```
GET    /api/health                                        liveness + readiness
POST   /api/global/{seskey|seskey/refresh|refresh_authtoken}   portal relay (allowlist)
GET    /api/session                                       the portal's view of the caller

GET    /api/v1/session                                    who is signed in
GET    /api/v1/capabilities                               what this account may do
GET    /api/v1/manage/companies                           the workspace switcher (live from Manage)

GET    /api/v1/mailboxes
GET    /api/v1/mailboxes/{id}/folders
GET    /api/v1/mailboxes/{id}/threads?folder=&cursor=&limit=&unread=&flagged=
GET    /api/v1/mailboxes/{id}/quota
GET    /api/v1/mailboxes/{id}/search?q=&folder=

GET    /api/v1/mailboxes/{id}/threads/{threadKey}?folder=
GET    /api/v1/mailboxes/{id}/messages/{uid}?folder=&load_remote_images=
GET    /api/v1/mailboxes/{id}/messages/{uid}/attachments/{partId}?folder=&preview=

PATCH  /api/v1/mailboxes/{id}/messages/{uid}              {action, folder, target_folder?}
POST   /api/v1/mailboxes/{id}/messages/bulk               {uids[], action, folder, target_folder?}

GET    /api/v1/mailboxes/{id}/drafts
POST   /api/v1/mailboxes/{id}/drafts
PATCH  /api/v1/mailboxes/{id}/drafts/{draftId}            {version, …}
DELETE /api/v1/mailboxes/{id}/drafts/{draftId}
POST   /api/v1/mailboxes/{id}/drafts/{draftId}/send       Idempotency-Key
POST   /api/v1/mailboxes/{id}/drafts/{draftId}/schedule   {scheduled_for, timezone} + Idempotency-Key
GET    /api/v1/mailboxes/{id}/sends/{jobId}
DELETE /api/v1/mailboxes/{id}/sends/{jobId}               undo-send / unschedule

GET    /api/v1/mailboxes/{id}/pulse/briefing
POST   /api/v1/mailboxes/{id}/pulse/thread-analysis       {thread_key, folder}
POST   /api/v1/mailboxes/{id}/pulse/classification        {thread_key, classification}
POST   /api/v1/mailboxes/{id}/pulse/dismiss               {thread_key}
POST   /api/v1/mailboxes/{id}/pulse/comparison            {service, record_id, email{…}}
GET    /api/v1/mailboxes/{id}/pulse/commitments
POST   /api/v1/mailboxes/{id}/pulse/commitments/{cid}     {state}
POST   /api/v1/mailboxes/{id}/pulse/reply-draft           {thread_key, folder, mode, tone?}

POST   /api/v1/mailboxes/{id}/pulse/actions/preview       {action, thread_key, proposal{…}}
POST   /api/v1/mailboxes/{id}/pulse/actions/{aid}/approve {preview_digest}
GET    /api/v1/mailboxes/{id}/pulse/actions/{aid}

GET    /api/v1/integrations
POST   /api/v1/integrations/{service}/check

GET    /api/v1/settings
PUT    /api/v1/settings
GET    /api/v1/mailboxes/{id}/signatures
POST   /api/v1/mailboxes/{id}/signatures
GET    /api/v1/mailboxes/{id}/blocklist
POST   /api/v1/mailboxes/{id}/blocklist
DELETE /api/v1/mailboxes/{id}/blocklist/{blockId}

GET    /api/v1/audit

GET    /api/v1/admin/mailboxes                            business entitlement
POST   /api/v1/admin/mailboxes/{id}/members
DELETE /api/v1/admin/mailboxes/{id}/members/{memberUuid}
GET    /api/v1/admin/domains
POST   /api/v1/admin/domains
POST   /api/v1/admin/domains/{domainId}/verify
```

**No GET writes.** Every state change is POST, PATCH, PUT or DELETE. A GET that
writes is a GET that a link preview, a prefetch or a crawler can trigger.

## Error codes

| Code | Status | Meaning |
|---|---|---|
| `unauthorized` | 401 | no or expired session |
| `forbidden` | 403 | authenticated, not permitted |
| `not_found` | 404 | no such resource **or** not yours — deliberately the same answer |
| `method_not_allowed` | 405 | right path, wrong verb |
| `conflict` | 409 | a draft conflict, or a stale action preview |
| `validation_failed` | 422 | with `details.field` |
| `rate_limited` | 429 | with the reset time |
| `integration_unsupported` | 501 | no verified contract for that operation |
| `not_configured` | 503 | a dependency was never set up; `retryable: false` |
| `mailbox_unavailable` | 503 | the mail store did not answer; `retryable: true` |
| `context_unavailable` | 503 | Manage did not answer, so tenancy cannot be confirmed |
| `database_unavailable` | 503 | Email's own database |

`not_configured` and `mailbox_unavailable` look alike and are not: the first is
a setup state with an admin hint, the second is an outage with a Retry button.

## Send outcomes

| `status` | Means |
|---|---|
| `queued` | a job exists; nothing is on the wire (undo window, or scheduled) |
| `submitting` | a worker has it |
| `accepted` | the outbound server took it and usually gave a queue id. **Not delivered.** |
| `deferred` | a temporary refusal; the same message will be retried |
| `failed` | a permanent refusal, with the reason |
| `uncertain` | the connection died after the body was written. A person decides. |
| `cancelled` | undone before it left |

There is no `delivered`. Nothing in this deployment observes delivery; when a
DSN feed is connected, `MAIL_DSN_WEBHOOK_ENABLED=1` turns the claim on and a new
status is added then, not before.
