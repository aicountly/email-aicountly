# What Email owns, and what it must never keep

Aicountly Email holds no other product's data. Every business figure on every
screen is read from the product that owns it, on the request that draws it.

This file is the rule, the reasoning, and the enforcement.

## The rule

> **NO CROSS-APPLICATION DATABASE SYNCHRONISATION.**
> Business data owned by another AICOUNTLY product is READ LIVE over its API and
> never copied into Email's database.

Forbidden, without exception:

* a cron that copies another product's records into Email
* an ETL job, a replicated table, or a "cache" table of somebody else's records
* direct SQL into another application's database
* a foreign data wrapper, `dblink`, or a second connection
* change-data-capture or logical replication
* a webhook handler that maintains a local mirror
* a local substitute for Contacts, Calendar, Inventory or Books
* persisting a fetched business payload in a vector store to get around the above

## What Email does own

| Email owns | Table |
|---|---|
| The Email account behind a portal identity, and its entitlements | `email_accounts` |
| Mailboxes and their configuration | `email_mailboxes` |
| Who may open a mailbox, and to do what | `email_mailbox_members` |
| Custom domains and the DNS state Email **read** | `email_domains` |
| Labels and their folder mappings | `email_labels`, `email_message_labels` |
| References to messages in the mail store, plus the search index | `email_message_refs` |
| Drafts and their attachments, until they are sent | `email_drafts`, `email_draft_attachments` |
| Scheduled sends and their outcomes | `email_send_jobs` |
| Sender and domain blocking | `email_blocklist` |
| Signatures and preferences | `email_signatures`, `email_preferences` |
| AI summaries and extracted candidate commitments | `email_thread_insights`, `email_commitments` |
| Action previews, approvals and receipts | `email_action_requests` |
| Per-account usage counters | `email_rate_counters` |
| Audit events | `email_audit_log` |
| The last health verdict per integration | `email_integration_health` |

Email messages and their original attachments are **Email content**. Receiving
an invoice as an attachment does not make Email an accounting system, and does
not authorise building one from it.

## Where message bodies and attachments live

**The mail store is authoritative.** IMAP today, behind
`server-php/src/Mail/MailStore.php`; JMAP or a provider API can replace it
without changing a caller.

Email's database holds:

* a **reference** to each message — mailbox, folder, remote UID, message id,
  thread key;
* the small amount of **derived text** the list, the search index and the AI
  summaries need — subject, sender, a snippet.

There is no body column and no attachment column in the schema. A draft's
attachment is the one exception, and it is Email's own content until the draft
is sent — at which point the row is deleted, because the attachment now lives in
the sent message in the mail store and a second copy would be a second answer.

## Who owns what, across the fleet

| Question | Owner | How Email gets it |
|---|---|---|
| Who is signed in? | **my.aicountly.com** | `Authorization: Bearer <ses_key>`, validated at the portal |
| Which company, branch, financial year? | **Manage** | `GET companyinfo`, `GET companies`, live |
| Who is this person? | **Contacts** | `GET /api/v1/contacts`, live |
| When is this meeting? | **Calendar** | Calendar creates the event; Email keeps its uuid |
| Where is this file? | **Drive** | Drive stores it; Email keeps its reference |
| What is this worth? | **Books** | amounts, taxes, totals, debtor and creditor effects |
| Is it in stock? | **Inventory** | items, quantities, valuation, COGS |
| What did we order? | **Purchase** | requisitions, RFQs, purchase orders, vendor bills |
| What did we sell? | **Sales** | quotations and sales orders |
| Has it been paid? | **Pay** | payment orchestration and its providers |
| Who is at reception? | **Lobby** | visits |
| What did we agree to book? | **Appointments** | bookings (Calendar owns the events) |

## The rules for fetched data

1. **Fetch live, on the request that needs it.** `ApiClient` memoises a GET for
   the duration of one request and nothing longer.
2. **Enforce the actor, the tenant and the resource.** Email calls the other
   product **as the signed-in user**, so that product's own permissions apply.
3. **Do not persist the payload.** The comparison endpoint returns
   `meta.persisted: false` and means it.
4. **Do not embed it in a long-lived AI summary.** A summary quoting a purchase
   order is wrong the moment the purchase order changes, and nothing would
   notice.
5. **Re-fetch when a comparison is reopened.** The stored insight holds message
   references; the business side is read again.
6. **Show the source and the retrieval time.** Every comparison card carries
   `Source: purchases · PO-1048 · read live at 09:43`.
7. **Clear transient data on logout and on company switch.**
   `SessionProvider.switchCompany` clears `sessionStorage` *before* registering
   the new scope, and bumps `scopeEpoch` so every company-derived query refetches
   instead of painting the previous tenant's answer.
8. **Never put a private API response in a service-worker cache.** Email
   registers no service worker, and the release verifier checks that none has
   appeared on the origin.

## What an audit row may contain

Ids, operation names, outcomes, timestamps, the actor and the correlation id.
`Audit::scrub()` strips anything that looks like content or a credential before
the row is written — not as a licence to pass content in, but so one careless
call site cannot turn the audit table into a mail archive.

## The one scheduled job

`server-php/bin/dispatch-scheduled.php` puts due scheduled sends on the wire and
retries deferred ones. It touches **Email's own send jobs and nothing else**: it
reads no other product, writes no other product, and copies nothing between
databases. That is the line between a scheduler and the synchronisation this
architecture forbids.

An **uncertain** send is never retried by it. That decision belongs to a person.

## Enforcement

`scripts/audit-data-ownership.py` runs in CI on every build and fails it on:

1. a table that is not prefixed `email_`, or one that mirrors another product's
   domain;
2. a column that would hold a fetched payload (`*_cache`, `cached_*`,
   `external_payload`, …);
3. a second database connection or a DSN pointing at another product;
4. a scheduled job that walks another product's data;
5. a secret or a development fixture in the built bundle.

If one of those fires and it is a false positive, the fix is to make the code
obviously compliant — not to widen the audit.
