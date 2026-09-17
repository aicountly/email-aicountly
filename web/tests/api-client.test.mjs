/**
 * How the API client classifies a failure, and what it puts in a URL.
 *
 * The classification decides which state view a user sees, and getting it wrong
 * means showing "something went wrong" for a feature that was simply never
 * configured. The URL test is about the session key: it must travel in a
 * header, because a query string reaches the access log, every proxy in
 * between, and the browser's history.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'

globalThis.window = { location: { origin: 'https://email.aicountly.com', hostname: 'email.aicountly.com' } }

const { ApiError, attachmentUrl, getScope, setScope } = await import('../src/services/api.ts')

test('a missing dependency is reported as not configured, not as an error', () => {
  const error = new ApiError(503, 'not_configured', 'No mail store is configured.', {
    admin_hint: 'Set MAIL_IMAP_HOST in api/.env.',
    retryable: false,
  })

  assert.equal(error.notConfigured, true)
  assert.equal(error.retryable, false)
  assert.equal(error.adminHint, 'Set MAIL_IMAP_HOST in api/.env.')
})

test('an unreachable service is retryable and a refusal is not', () => {
  assert.equal(new ApiError(503, 'mailbox_unavailable', 'no answer', { retryable: true }).retryable, true)
  assert.equal(new ApiError(0, 'network_error', 'no answer').retryable, true)
  assert.equal(new ApiError(504, 'timeout', 'no answer').retryable, true)

  // A 403 is a decision, not a hiccup. Offering Retry here teaches people to
  // press it twice.
  assert.equal(new ApiError(403, 'forbidden', 'not yours').retryable, false)
  assert.equal(new ApiError(422, 'validation_failed', 'bad input').retryable, false)
})

test('an explicit retryable flag from the backend wins over the status code', () => {
  assert.equal(new ApiError(503, 'x', 'y', { retryable: false }).retryable, false)
  assert.equal(new ApiError(409, 'x', 'y', { retryable: true }).retryable, true)
})

test('session expiry and rate limiting are distinguishable', () => {
  assert.equal(new ApiError(401, 'unauthorized', 'sign in').sessionExpired, true)
  assert.equal(new ApiError(429, 'rate_limited', 'slow down').rateLimited, true)
  assert.equal(new ApiError(429, 'rate_limited', 'slow down').sessionExpired, false)
})

test('an attachment URL carries no session key', () => {
  const url = attachmentUrl(42, '1201', '2.1', 'INBOX', true)

  assert.ok(url.startsWith('https://email.aicountly.com/api/v1/mailboxes/42/messages/1201/attachments/2.1'))
  assert.match(url, /folder=INBOX/)
  assert.match(url, /preview=1/)
  assert.doesNotMatch(url, /Bearer|ses_key|auth_token|token=/i)
})

test('an attachment URL escapes the parts that come from a message', () => {
  // A uid and a part id come out of a message, so they are attacker-influenced.
  const url = attachmentUrl(42, 'a/b?c', '../evil', 'INBOX', false)
  const { pathname } = new URL(url)

  // The separators are escaped, so each stays ONE path segment and cannot
  // traverse or start a new query. The literal dots are harmless inside a
  // segment, and the backend filters `..` segments again after decoding.
  assert.equal(pathname, '/api/v1/mailboxes/42/messages/a%2Fb%3Fc/attachments/..%2Fevil')
  assert.ok(!pathname.split('/').includes('..'))
})

test('the company scope is global and clearable', () => {
  setScope({ cmp_id: 7, fy_id: 2, bo_id: 0 })
  assert.deepEqual(getScope(), { cmp_id: 7, fy_id: 2, bo_id: 0 })

  // Switching company clears it before anything can fetch with the old one.
  setScope(null)
  assert.equal(getScope(), null)
})
