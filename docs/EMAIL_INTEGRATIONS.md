# What Email asks other products for

Email owns no business data. This file is the complete list of what it asks for,
which of those asks are backed by a **verified contract**, and which are an
adapter boundary waiting for one.

**A registry entry is not a promise.** Every capability carries `verified`, and
only a verified one is offered. `verified` means the endpoint and its shape were
read out of that product's own code or contract document — not inferred from a
pattern, and not guessed from the product's name. An unverified capability
renders as *Unsupported* with the reason, and its button is disabled.

## The six states

| State | Means |
|---|---|
| `connected` | configured, entitled, and the last probe succeeded |
| `not_connected` | configured, but the last probe failed |
| `not_configured` | no base URL for this deployment |
| `permission_required` | the account is not entitled, or the user is not |
| `temporarily_unavailable` | configured and entitled, and it timed out |
| `unsupported` | Email has no verified contract for what was asked |

None of them blocks email. A dead integration greys out one panel; the inbox
keeps working.

Listing the registry **probes nothing**. Each product runs a small PHP-FPM pool,
and a settings screen that opens twenty synchronous HTTP calls is how a pool
deadlocks. The listed state comes from the last recorded probe;
`POST /api/v1/integrations/{service}/check` runs exactly one, on demand.

## Verified today

| Product | Capability | Operation | Used by |
|---|---|---|---|
| Manage | `company.list` | `GET /api/companies` | the workspace switcher |
| Manage | `company.read` | `GET /api/companyinfo` | the tenant check on every scoped request |
| Purchase | `purchase_order.read` | `GET /api/v1/purchase-orders/{id}` | email vs business record |
| Purchase | `purchase_order.list` | `GET /api/v1/purchase-orders` | finding the PO an email names |
| Books | `register.list` | `GET /api/registers` | the invoice behind a payment chase |
| Books | `bill_by_bill` | `GET /api/reports/bill-by-bill` | what is outstanding |
| Books | `account.list` | `GET /api/masters/accounts` | the party picker |
| Inventory | `availability.check` | `GET /api/v1/availability` | "is it in stock" next to an enquiry |
| Inventory | `item.search` | `GET /api/v1/items/search` | resolving an item an email names |
| Calendar | `event.create` | `POST /api/calendar/events` | a commitment becoming a diary entry |
| Calendar | `conflict.check` | `POST /api/calendar/conflict-check` | before proposing a time |
| Calendar | `free_busy.read` | `GET /api/calendar/free-busy` | before proposing a time |
| Contacts | `contact.search` | `GET /api/v1/contacts` | who this sender is |
| Contacts | `contact.read` | `GET /api/v1/contacts/{id}` | who this sender is |

Sources: `purchases-aicountly/server-php/src/Routes.php`,
`appointments-aicountly/docs/CALENDAR_API_INTEGRATION.md`,
`billing-aicountly/docs/BILLING_API_DEPENDENCIES.md`,
`purchases-aicountly/server-php/src/Clients/*.php`.

## Adapter in place, contract not agreed

These render as *Unsupported* and the action is disabled with the reason. The
preview still builds — it is useful to see exactly what would be sent — and the
approve step refuses rather than sending a payload Email would be guessing at.

| Product | Capability | Outstanding |
|---|---|---|
| Drive | `file.save` | no agreed request shape for saving an attachment |
| Sales | `sales_order.read`, `quotation.create` | no published v1 contract read into this repository |
| Contacts | `contact.link` | no agreed endpoint for linking an address to a contact |
| Pay | `payment_link.request` | no agreed contract; Pay's own approvals govern it when there is one |
| Purchase | `purchase_order.revise` | Purchase's own workflow, with its own approvals. Email links to it rather than writing. |
| Helpdesk, Connect, Lobby, CRM, Notes, Contracts, HRMS, Auditor, Financial Reporting, Secretarial, Vault, Billing, POS | — | registered so the adapter boundary exists; no capabilities advertised |
| Voice | `briefing.speak` | no AICOUNTLY voice service configured. The briefing falls back to the device's own speech, labelled as such. |

## How Email authenticates outbound

* **As the signed-in user** (`Authorization: Bearer <ses_key>`) for every read a
  screen makes, so that product's own permissions apply rather than Email
  re-implementing them.
* **As a product** (`X-Service-Key` + `X-Actor-Uuid`) only for a write where
  Email acts as a product — today, creating a Calendar event.

Every outbound call carries `X-Saas-Origin: email`, the fleet-wide re-entry
guard. If the product being called is the one whose request Email is serving,
the call is suppressed: its worker is already blocked on Email's response, and
calling back parks a second one. See
`books-react-app/docs/CROSS_SERVICE_CALL_RULES.md`.

## Timeouts

| Kind | Connect | Total |
|---|---|---|
| optional — a screen degrades | 2s | 6s |
| required — a write somebody is waiting on | 3s | 20s |

Both have a connect bound, because a product that is up but not accepting — every
child blocked on another pool — is held only by `CURLOPT_CONNECTTIMEOUT`.

## Nothing fetched is stored

Read live, on the request that needs it. Memoised for that request and no
longer. Not written to a table, not embedded in a stored AI summary, and
re-read when a comparison is reopened. See
[EMAIL_DATA_OWNERSHIP.md](EMAIL_DATA_OWNERSHIP.md), which CI enforces.
