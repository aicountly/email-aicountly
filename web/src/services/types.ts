/**
 * The shapes the Email API returns.
 *
 * Written from server-php/src/Controllers — if one of these drifts from the
 * controller that produces it, the controller is right.
 */

export type MailboxPermission = 'read' | 'send_as' | 'send_on_behalf' | 'manage'

export interface Mailbox {
  mailbox_id: number
  address: string
  display_name: string
  kind: 'personal' | 'shared'
  cmp_id: number | null
  role: 'owner' | 'delegate' | 'member'
  permissions?: MailboxPermission[]
  status?: string
}

export interface Feature {
  label: string
  enabled: boolean
  reason: string | null
}

export interface NavigationItem {
  key: string
  label: string
  role: string
}

export interface AdapterStatus {
  configured: boolean
  driver?: string
  reason?: string | null
  admin_hint?: string | null
  host?: string
}

export interface Capabilities {
  account: {
    account_id: number
    account_type: 'business' | 'personal'
    status: string
    plan_key: string
    storage_quota_bytes: number
    ai_opt_out: boolean
  }
  features: Record<string, Feature>
  mailboxes: Mailbox[]
  mail: {
    store: AdapterStatus
    transport: AdapterStatus
    delivery_confirmation: { available: boolean; reason: string | null }
    malware_scanning: { available: boolean; reason: string | null }
  }
  ai: { available: boolean; model: string | null; reason: string | null; admin_hint: string | null; opted_out: boolean }
  usage: Record<string, { used: number; max: number; resets_at: string }>
  navigation: {
    business: NavigationItem[]
    personal: NavigationItem[]
    entitled: 'business' | 'personal'
    administration: boolean
  }
}

export interface Address {
  name: string
  address: string
}

export interface ThreadSummary {
  uid: string
  thread_key: string
  message_id: string
  subject: string
  from: Address[]
  to: Address[]
  date: string | null
  seen: boolean
  flagged: boolean
  answered: boolean
  size: number | null
  has_attachments: boolean
}

export interface Attachment {
  part_id: string
  filename: string
  mime_type: string
  size: number
  inline: boolean
  previewable: boolean
  scanned: boolean
  scan_note: string | null
}

export interface Message {
  uid: string
  folder: string | null
  message_id: string
  thread_key: string
  subject: string
  from: Address[]
  to: Address[]
  cc: Address[]
  reply_to: Address[]
  date: string | null
  text: string
  html: string
  render: {
    sanitized: boolean
    blocked_remote_images: number
    removed_elements: number
    remote_images_allowed: boolean
  }
  attachments: Attachment[]
  seen: boolean
  flagged: boolean
  answered: boolean
  authentication: { header: string | null; caveat: string }
}

export interface Folder {
  id: string
  name: string
  role: string
  delimiter: string
  total: number | null
  unread: number | null
}

export interface Draft {
  draft_id: number
  to: Address[]
  cc: Address[]
  bcc: Address[]
  subject: string
  body_text: string
  body_html: string
  in_reply_to: string
  thread_key: string
  origin: 'user' | 'ai_suggested'
  version: number
  updated_at: string
  status: 'draft_saved'
  invalid_recipients?: string[]
}

/**
 * What the composer sends when it saves a draft.
 *
 * Addresses go up as the raw text the user typed — `AddressValidator` on the
 * server parses both a comma-separated string and a list, and it is the one
 * that decides what a valid address is. Parsing in the browser as well would
 * give two answers to that question.
 */
export interface DraftInput {
  to?: string | Address[]
  cc?: string | Address[]
  bcc?: string | Address[]
  subject?: string
  body_text?: string
  body_html?: string
  in_reply_to?: string
  thread_key?: string
  origin?: string
}

export type SendStatus =
  | 'queued'
  | 'submitting'
  | 'accepted'
  | 'failed'
  | 'deferred'
  | 'uncertain'
  | 'cancelled'

export interface SendJob {
  job_id: number
  operation_id: string
  status: SendStatus
  label: string
  explanation: string | null
  queue_id: string | null
  attempts: number
  scheduled_for: string | null
  timezone: string | null
  release_after: string | null
  can_cancel: boolean
  can_retry: boolean
  needs_decision: boolean
  undo_seconds?: number
  invalid_recipients?: string[]
}

export interface Classification {
  classification: string
  label: string
  reason: string
  generator: 'rules' | 'model'
  ai_generated: boolean
  source: { uid: string | null; message_id: string | null; from: string | null; date: string | null }
  deadline: string | null
  deadline_note: string
}

export interface Commitment {
  commitment_id: number
  thread_key: string
  what: string
  promised_by: string
  promised_to: string
  proposed_date: string | null
  timezone: string
  direction: 'incoming' | 'outgoing'
  state: string
  state_label: string
  user_confirmed: boolean
  agreed: boolean
  source_quote: string
  source_uid: string | null
}

export interface Briefing {
  greeting: string
  counts: Record<string, { value: number; source: string }>
  decisions: Array<{
    thread_key: string
    classification: string
    label: string
    reason: string
    summary: string
    deadline: string | null
    deadline_note: string | null
    sources: unknown
    ai_generated: boolean
  }>
  commitments: Commitment[]
  awaiting_replies: Array<{ thread_key: string; subject: string; from_address: string; internal_date: string }>
  ai: Capabilities['ai']
  generated_at: string
  disclaimer: string
}

export interface ComparisonDifference {
  field: string
  label: string
  kind: 'amount' | 'date'
  record: string
  email: string
  delta?: string
  delta_percent?: string | null
  delta_days?: number
  direction: string
  changed: boolean
  currency?: string | null
}

export interface ComparisonResult {
  comparable: boolean
  requires_review: boolean
  review_reason: string | null
  blockers: Array<{ field: string; label: string; reason: string; email: string | null; record: string | null }>
  differences: ComparisonDifference[]
  unchanged: ComparisonDifference[]
  side_by_side?: Array<{ field: string; label: string; record: string | null; email: string | null }>
  source: {
    record: { service: string; id: string; fetched_at: string; cmp_id: number }
    email: { message_id: string | null; uid: string | null }
  }
  computed_by: 'deterministic'
}

export interface PaymentCheck {
  has_payment_details: boolean
  changed: boolean
  changes: Array<{
    field: string
    previous: string
    current: string
    source: { previous_from: string; current_from: string }
  }>
  fields_seen?: string[]
  authentication: {
    header_present: boolean
    spf: string | null
    dkim: string | null
    dmarc: string | null
    caveat: string
  }
  advice: string | null
  verification_action: string | null
}

export interface ThreadAnalysis {
  ok: boolean
  thread_key: string
  classification: Classification
  summary: {
    text: string
    generator: string
    available: boolean
    reason: string | null
    sources: Array<{ uid: string | null; message_id: string | null; from: string | null; date: string | null }>
    uncertainty: string | null
  }
  commitments: Commitment[]
  payment_check: PaymentCheck
  message_count: number
  ai: Capabilities['ai']
}

export interface ActionPreview {
  action_id: number
  status: string
  target_service: string
  target_label: string
  operation: string
  operation_label: string
  summary: string
  proposal: Record<string, unknown>
  reversible: boolean
  reverse_note: string | null
  required_permissions: string[]
  preview_digest: string
  executable: boolean
  not_executable_reason: string | null
  external_ref: Record<string, unknown>
  error: string | null
  approved_at: string | null
  executed_at: string | null
  correlation_id: string
  atomicity_note: string
}

export interface IntegrationService {
  service: string
  label: string
  owns: string
  api_base_url: string | null
  entitlement: string
  entitled: boolean
  timeout_policy: string
  state:
    | 'connected'
    | 'not_connected'
    | 'not_configured'
    | 'permission_required'
    | 'temporarily_unavailable'
    | 'unsupported'
  state_reason: string | null
  capabilities: Array<{ key: string; operation: string; writes: boolean; available: boolean; reason: string | null }>
  last_checked_at: string | null
}

export interface CatalogAction {
  key: string
  label: string
  service: string
  summary: string
  reversible: boolean
  permissions: string[]
  available: boolean
  reason: string | null
}
