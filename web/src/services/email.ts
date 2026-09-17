/**
 * Every Email API call the app makes, in one place.
 *
 * Pages call these; they never build a URL. That is what keeps the mailbox id
 * in the path (the backend's authorisation boundary) rather than in a header or
 * a body where a page could forget it.
 */

import { api } from './api.ts'
import type { PageResponse } from './api.ts'
import type {
  ActionPreview,
  Briefing,
  Capabilities,
  CatalogAction,
  Commitment,
  ComparisonResult,
  Draft,
  DraftInput,
  Folder,
  IntegrationService,
  Message,
  SendJob,
  ThreadAnalysis,
  ThreadSummary,
} from './types.ts'

const base = (mailboxId: number) => `v1/mailboxes/${mailboxId}`

export const emailApi = {
  // --- Session -----------------------------------------------------------
  session: () => api.unscoped<{ authenticated: boolean; uuid: string; display_name: string; portal_email: string }>('v1/session'),
  capabilities: (signal?: AbortSignal) => api.unscoped<Capabilities>('v1/capabilities', undefined, signal),
  companies: (signal?: AbortSignal) => api.unscoped<unknown[]>('v1/manage/companies', undefined, signal),

  // --- Mailboxes ----------------------------------------------------------
  folders: (mailboxId: number, signal?: AbortSignal) =>
    api.get<{ folders: Folder[]; labels: Array<{ label_id: number; name: string; colour: string }> }>(
      `${base(mailboxId)}/folders`,
      undefined,
      signal,
    ),

  threads: (
    mailboxId: number,
    params: { folder: string; cursor?: string | null; limit?: number; unread?: boolean; flagged?: boolean },
    signal?: AbortSignal,
  ): Promise<PageResponse<ThreadSummary>> =>
    api.page<ThreadSummary>(
      `${base(mailboxId)}/threads`,
      {
        folder: params.folder,
        cursor: params.cursor ?? undefined,
        limit: params.limit,
        unread: params.unread,
        flagged: params.flagged,
      },
      signal,
    ),

  thread: (mailboxId: number, threadKey: string, folder: string, signal?: AbortSignal) =>
    api.get<{ thread_key: string; folder: string; messages: Message[] }>(
      `${base(mailboxId)}/threads/${encodeURIComponent(threadKey)}`,
      { folder },
      signal,
    ),

  message: (mailboxId: number, uid: string, folder: string, loadRemoteImages = false, signal?: AbortSignal) =>
    api.get<Message>(`${base(mailboxId)}/messages/${encodeURIComponent(uid)}`, {
      folder,
      load_remote_images: loadRemoteImages || undefined,
    }, signal),

  quota: (mailboxId: number, signal?: AbortSignal) =>
    api.get<{ used_bytes: number | null; quota_bytes: number | null; known: boolean; note: string | null }>(
      `${base(mailboxId)}/quota`,
      undefined,
      signal,
    ),

  search: (mailboxId: number, query: string, folder: string, signal?: AbortSignal) =>
    api.get<{ indexed: ThreadSummary[]; store: ThreadSummary[] }>(
      `${base(mailboxId)}/search`,
      { q: query, folder },
      signal,
    ),

  // --- Message actions -----------------------------------------------------
  updateMessage: (mailboxId: number, uid: string, action: string, folder: string, targetFolder?: string) =>
    api.patch<{ action: string; requested: number; changed: number; complete: boolean; note: string | null }>(
      `${base(mailboxId)}/messages/${encodeURIComponent(uid)}`,
      { action, folder, target_folder: targetFolder },
    ),

  bulk: (mailboxId: number, uids: string[], action: string, folder: string, targetFolder?: string) =>
    api.post<{ action: string; requested: number; changed: number; complete: boolean; note: string | null }>(
      `${base(mailboxId)}/messages/bulk`,
      { uids, action, folder, target_folder: targetFolder },
    ),

  // --- Drafts and sending ---------------------------------------------------
  drafts: (mailboxId: number, signal?: AbortSignal) =>
    api.get<Draft[]>(`${base(mailboxId)}/drafts`, undefined, signal),

  createDraft: (mailboxId: number, draft: DraftInput) => api.post<Draft>(`${base(mailboxId)}/drafts`, draft),

  saveDraft: (mailboxId: number, draftId: number, draft: DraftInput & { version: number }) =>
    api.patch<Draft>(`${base(mailboxId)}/drafts/${draftId}`, draft),

  deleteDraft: (mailboxId: number, draftId: number) =>
    api.del<{ deleted: boolean; draft_id: number }>(`${base(mailboxId)}/drafts/${draftId}`),

  /**
   * Send.
   *
   * `operationId` is the idempotency key and MUST be the same value on every
   * retry of the same compose — that is what turns a double click, a retried
   * request or a reloaded tab into a replay instead of a second message.
   */
  send: (mailboxId: number, draftId: number, operationId: string) =>
    api.post<SendJob>(`${base(mailboxId)}/drafts/${draftId}/send`, {}, { idempotencyKey: operationId }),

  schedule: (mailboxId: number, draftId: number, scheduledFor: string, timezone: string, operationId: string) =>
    api.post<SendJob>(
      `${base(mailboxId)}/drafts/${draftId}/schedule`,
      { scheduled_for: scheduledFor, timezone },
      { idempotencyKey: operationId },
    ),

  sendStatus: (mailboxId: number, jobId: number, signal?: AbortSignal) =>
    api.get<SendJob>(`${base(mailboxId)}/sends/${jobId}`, undefined, signal),

  /** Undo-send and unscheduling are the same operation. */
  cancelSend: (mailboxId: number, jobId: number) => api.del<SendJob>(`${base(mailboxId)}/sends/${jobId}`),

  // --- Pulse ----------------------------------------------------------------
  briefing: (mailboxId: number, signal?: AbortSignal) =>
    api.get<Briefing>(`${base(mailboxId)}/pulse/briefing`, undefined, signal),

  analyseThread: (mailboxId: number, threadKey: string, folder: string) =>
    api.post<ThreadAnalysis>(`${base(mailboxId)}/pulse/thread-analysis`, { thread_key: threadKey, folder }),

  correctClassification: (mailboxId: number, threadKey: string, classification: string) =>
    api.post<{ thread_key: string; classification: string; corrected: boolean }>(
      `${base(mailboxId)}/pulse/classification`,
      { thread_key: threadKey, classification },
    ),

  dismissInsight: (mailboxId: number, threadKey: string) =>
    api.post<{ thread_key: string; dismissed: boolean }>(`${base(mailboxId)}/pulse/dismiss`, { thread_key: threadKey }),

  compare: (
    mailboxId: number,
    payload: { service: string; record_id: string; email: Record<string, unknown>; message_id?: string; uid?: string },
  ) => api.post<ComparisonResult>(`${base(mailboxId)}/pulse/comparison`, payload),

  commitments: (mailboxId: number, signal?: AbortSignal) =>
    api.get<Commitment[]>(`${base(mailboxId)}/pulse/commitments`, undefined, signal),

  setCommitmentState: (mailboxId: number, commitmentId: number, state: string) =>
    api.post<Commitment>(`${base(mailboxId)}/pulse/commitments/${commitmentId}`, { state }),

  replyDraft: (
    mailboxId: number,
    payload: { thread_key: string; folder: string; mode: string; tone?: string; current_text?: string },
  ) =>
    api.post<{ mode: string; tone: string; text: string; is_draft: true; origin: string; disclaimer: string }>(
      `${base(mailboxId)}/pulse/reply-draft`,
      payload,
    ),

  // --- Actions ---------------------------------------------------------------
  previewAction: (mailboxId: number, action: string, threadKey: string, proposal: Record<string, unknown>) =>
    api.post<ActionPreview>(`${base(mailboxId)}/pulse/actions/preview`, { action, thread_key: threadKey, proposal }),

  approveAction: (mailboxId: number, actionId: number, previewDigest: string) =>
    api.post<ActionPreview>(`${base(mailboxId)}/pulse/actions/${actionId}/approve`, { preview_digest: previewDigest }),

  action: (mailboxId: number, actionId: number, signal?: AbortSignal) =>
    api.get<ActionPreview>(`${base(mailboxId)}/pulse/actions/${actionId}`, undefined, signal),

  // --- Integrations, settings, audit -------------------------------------------
  integrations: (signal?: AbortSignal) =>
    api.unscoped<{ services: IntegrationService[]; actions: CatalogAction[] }>('v1/integrations', undefined, signal),

  checkIntegration: (service: string) =>
    api.post<{ service: string; state: string; http_status: number | null; checked_at: string }>(
      `v1/integrations/${service}/check`,
    ),

  settings: (signal?: AbortSignal) =>
    api.unscoped<{
      account: Capabilities['account']
      timezone: string
      undo_seconds: number
      density: string
      remote_images: string
      preferences: Record<string, unknown>
      usage: Capabilities['usage']
    }>('v1/settings', undefined, signal),

  saveSettings: (payload: Record<string, unknown>) => api.put<Record<string, unknown>>('v1/settings', payload),

  audit: (before?: number, signal?: AbortSignal) =>
    api.page<{
      audit_id: number
      action: string
      entity_type: string
      entity_id: string | null
      outcome: string
      detail: Record<string, unknown>
      correlation_id: string
      created_at: string
    }>('v1/audit', { before }, signal),
}

/**
 * A stable id for one compose.
 *
 * Minted when the composer opens, not when Send is pressed: a retry has to
 * present the same value, and one generated at the call site is a new value
 * every time — which is exactly the duplicate this is here to prevent.
 */
export function newSendOperationId(): string {
  const bytes = new Uint8Array(12)
  crypto.getRandomValues(bytes)
  return 'op-' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}
