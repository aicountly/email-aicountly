/**
 * Typed fetch wrapper for the Email API.
 *
 * What it encodes so pages do not have to:
 *
 *  - `Authorization: Bearer <ses_key>` from the portal session, minted on
 *    demand, with one silent retry on 401 (a key can be revoked before its
 *    local expiry).
 *  - A correlation id on every request, echoed back on the response, so one
 *    failed send can be found in the server log.
 *  - Idempotency keys on the writes that support them.
 *  - The fleet's envelopes: `{data}`, `{data, meta}`, and errors as ApiError.
 *
 * The API may be cross-origin — the personal frontend on aicountly.io calls the
 * same backend as the business one. Requests are sent WITHOUT credentials on
 * purpose: the session travels as a Bearer header, so the browser has no cookie
 * to attach and the CSRF surface that cookie auth would bring does not exist.
 */

import { getApiBaseUrl } from '../config.ts'
import { ensureSesKey } from '../auth/portal.ts'

export interface ListMeta {
  limit: number
  cursor: string | null
  next_cursor: string | null
  has_more: boolean
  total: number | null
  [key: string]: unknown
}

export interface PageResponse<T> {
  data: T[]
  meta: ListMeta
}

export interface ItemResponse<T> {
  data: T
  meta?: Record<string, unknown>
}

export type QueryValue = string | number | boolean | null | undefined
export type QueryParams = Record<string, QueryValue>

export class ApiError extends Error {
  // Written out rather than declared as constructor parameter properties:
  // Node's type-stripping test runner does not support those, and these
  // modules are covered by tests that run without a bundler.
  readonly status: number
  readonly code: string
  readonly details: Record<string, unknown>
  readonly correlationId: string | null

  constructor(
    status: number,
    code: string,
    message: string,
    details: Record<string, unknown> = {},
    correlationId: string | null = null,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.details = details
    this.correlationId = correlationId
  }

  /** True when pressing the same button again could reasonably work. */
  get retryable(): boolean {
    if (typeof this.details.retryable === 'boolean') return this.details.retryable
    return this.status === 0 || this.status === 503 || this.status === 502 || this.status === 504
  }

  /** A dependency is missing rather than broken — the UI shows a setup state, not an error. */
  get notConfigured(): boolean {
    return this.code === 'not_configured'
  }

  get rateLimited(): boolean {
    return this.status === 429
  }

  get sessionExpired(): boolean {
    return this.status === 401
  }

  get adminHint(): string | null {
    return typeof this.details.admin_hint === 'string' ? this.details.admin_hint : null
  }
}

/**
 * The company scope for business requests.
 *
 * Registered by the workspace switcher and cleared on every company change and
 * on sign-out — see SessionProvider. Nothing about the previous company may
 * survive the switch, which is why this lives in one place rather than being
 * threaded through calls.
 */
export interface CompanyScope {
  cmp_id: number
  fy_id: number
  bo_id: number
}

let scope: CompanyScope | null = null

export function setScope(next: CompanyScope | null): void {
  scope = next
}

export function getScope(): CompanyScope | null {
  return scope
}

function newCorrelationId(): string {
  const bytes = new Uint8Array(8)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

function buildUrl(path: string, params: QueryParams | undefined, scoped: boolean): string {
  const url = new URL(`${getApiBaseUrl()}/${path.replace(/^\//, '')}`, window.location.origin)

  if (scoped && scope) {
    url.searchParams.set('cmp_id', String(scope.cmp_id))
    url.searchParams.set('fy_id', String(scope.fy_id))
    url.searchParams.set('bo_id', String(scope.bo_id))
  }

  for (const [key, value] of Object.entries(params ?? {})) {
    if (value === null || value === undefined || value === '') continue
    url.searchParams.set(key, String(value))
  }

  return url.toString()
}

interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  params?: QueryParams
  body?: unknown
  /** Pass false for calls that take no company context. */
  scoped?: boolean
  /** Sent as Idempotency-Key. The SAME value must be presented on every retry. */
  idempotencyKey?: string
  signal?: AbortSignal
}

async function send<T>(path: string, options: RequestOptions, sesKey: string): Promise<T> {
  const scoped = options.scoped === true
  const method = options.method ?? 'GET'

  const headers: Record<string, string> = {
    Accept: 'application/json',
    Authorization: `Bearer ${sesKey}`,
    'X-Correlation-Id': newCorrelationId(),
  }

  if (options.idempotencyKey) {
    headers['Idempotency-Key'] = options.idempotencyKey
  }

  let body: string | undefined
  if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    // Context travels in the body as well: a POST that carries it only in the
    // query string works until somebody reads the body first, and then fails in
    // a way that looks like the company was never chosen.
    const payload =
      scoped && scope && typeof options.body === 'object' && options.body !== null && !Array.isArray(options.body)
        ? { ...scope, ...(options.body as Record<string, unknown>) }
        : options.body
    body = JSON.stringify(payload)
  }

  const response = await fetch(buildUrl(path, options.params, scoped), {
    method,
    headers,
    body,
    signal: options.signal,
    // No cookies, by design. See the note at the top of this file.
    credentials: 'omit',
    mode: 'cors',
  })

  const correlationId = response.headers.get('X-Correlation-Id')
  const text = await response.text()
  let parsed: unknown = null
  if (text) {
    try {
      parsed = JSON.parse(text)
    } catch {
      parsed = null
    }
  }

  if (!response.ok) {
    const envelope = parsed as
      | { error?: { code?: string; message?: string; details?: Record<string, unknown> }; message?: string }
      | null
    throw new ApiError(
      response.status,
      envelope?.error?.code ?? 'error',
      envelope?.error?.message ?? envelope?.message ?? `Request failed (${response.status})`,
      envelope?.error?.details ?? {},
      correlationId,
    )
  }

  return parsed as T
}

async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
  if (typeof navigator !== 'undefined' && navigator.onLine === false) {
    // Said plainly rather than surfacing as a generic network error, so the UI
    // can show the offline state instead of "something went wrong".
    throw new ApiError(0, 'offline', 'You are offline. Email will retry when the connection is back.', { retryable: true })
  }

  const sesKey = await ensureSesKey()

  try {
    return await send<T>(path, options, sesKey)
  } catch (error) {
    // Exactly one retry, and only for 401: a key can be revoked server-side
    // before it expires locally, and making the user sign in again for that is
    // a bad trade. Anything else is the caller's to handle.
    if (error instanceof ApiError && error.status === 401) {
      const freshKey = await ensureSesKey(true)
      return await send<T>(path, options, freshKey)
    }
    if (error instanceof TypeError) {
      throw new ApiError(0, 'network_error', 'Could not reach the Email service.', { retryable: true })
    }
    throw error
  }
}

export const api = {
  get: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'GET', params, scoped: true, signal }),

  page: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<PageResponse<T>>(path, { method: 'GET', params, scoped: true, signal }),

  post: <T>(path: string, body?: unknown, options: { params?: QueryParams; idempotencyKey?: string } = {}) =>
    request<ItemResponse<T>>(path, {
      method: 'POST',
      body: body ?? {},
      params: options.params,
      idempotencyKey: options.idempotencyKey,
      scoped: true,
    }),

  patch: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'PATCH', body: body ?? {}, params, scoped: true }),

  put: <T>(path: string, body?: unknown, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'PUT', body: body ?? {}, params, scoped: true }),

  del: <T>(path: string, params?: QueryParams) =>
    request<ItemResponse<T>>(path, { method: 'DELETE', params, scoped: true }),

  /** Context-free: session, capabilities, integrations, the company switcher. */
  unscoped: <T>(path: string, params?: QueryParams, signal?: AbortSignal) =>
    request<ItemResponse<T>>(path, { method: 'GET', params, scoped: false, signal }),
}

/**
 * A URL for an attachment, carrying the scope but not the session key.
 *
 * The key is never put in a query string: it would land in the server's access
 * log, in any proxy in between, and in the browser's history. The download is
 * made with fetch and the header instead.
 */
export function attachmentUrl(mailboxId: number, uid: string, partId: string, folder: string, preview: boolean): string {
  const url = new URL(
    `${getApiBaseUrl()}/v1/mailboxes/${mailboxId}/messages/${encodeURIComponent(uid)}/attachments/${encodeURIComponent(partId)}`,
    window.location.origin,
  )
  url.searchParams.set('folder', folder)
  if (preview) url.searchParams.set('preview', '1')

  return url.toString()
}

/** Fetch an attachment as a blob, with the Authorization header the URL cannot carry. */
export async function fetchAttachment(
  mailboxId: number,
  uid: string,
  partId: string,
  folder: string,
  preview = false,
): Promise<{ blob: Blob; filename: string }> {
  const sesKey = await ensureSesKey()
  const response = await fetch(attachmentUrl(mailboxId, uid, partId, folder, preview), {
    headers: { Authorization: `Bearer ${sesKey}`, 'X-Correlation-Id': newCorrelationId() },
    credentials: 'omit',
    mode: 'cors',
  })

  if (!response.ok) {
    throw new ApiError(response.status, 'attachment_unavailable', 'That attachment could not be downloaded.')
  }

  const disposition = response.headers.get('Content-Disposition') ?? ''
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(disposition)

  return {
    blob: await response.blob(),
    filename: match ? decodeURIComponent(match[1]) : 'attachment',
  }
}
