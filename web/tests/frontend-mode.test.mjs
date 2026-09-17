/**
 * The host table.
 *
 * These are the highest-consequence pure functions in the frontend: they decide
 * which experience a browser gets and which backend it talks to. Everything
 * here runs without a bundler and without a browser, which is why the modules
 * read their build-time values through config/buildEnv.ts.
 */

import { test } from 'node:test'
import assert from 'node:assert/strict'

import {
  describeHost,
  isCrossOriginApi,
  normalizeHostname,
  PRODUCTION_ORIGINS,
  resolveEmailApiBaseUrl,
  resolveFrontendMode,
} from '../src/config/frontend.ts'

test('each production hostname selects its own experience', () => {
  assert.equal(resolveFrontendMode('email.aicountly.com'), 'business')
  assert.equal(resolveFrontendMode('aicountly.io'), 'personal')
})

test('hostname matching ignores case and a trailing dot', () => {
  // A browser treats "aicountly.io." as the same origin; a lookup table does
  // not, and that difference is one way a host check gets bypassed.
  assert.equal(resolveFrontendMode('AICOUNTLY.IO'), 'personal')
  assert.equal(resolveFrontendMode('aicountly.io.'), 'personal')
  assert.equal(resolveFrontendMode('Email.Aicountly.Com.'), 'business')
})

test('an unknown production hostname fails closed', () => {
  // Not "defaults to business". An unreviewed host is either a
  // misconfiguration or somebody rehosting the bundle.
  assert.throws(() => resolveFrontendMode('mail.example.com'), /not configured/i)
  assert.throws(() => resolveFrontendMode('aicountly.io.evil.test'), /not configured/i)
  assert.throws(() => resolveFrontendMode(''), /not configured/i)
})

test('a lookalike of a production host is not a production host', () => {
  assert.throws(() => resolveFrontendMode('email.aicountly.com.attacker.test'), /not configured/i)
  assert.throws(() => resolveFrontendMode('xemail.aicountly.com'), /not configured/i)
  assert.throws(() => resolveFrontendMode('aicountly.io.co'), /not configured/i)
})

test('the reviewed sandbox zone is configured, and nothing else in it is', () => {
  assert.equal(resolveFrontendMode('email.gh.aicountly.com'), 'business')
  assert.equal(resolveFrontendMode('io.gh.aicountly.com'), 'personal')
  assert.throws(() => resolveFrontendMode('anything.gh.aicountly.com'), /not configured/i)
})

test('describeHost reports instead of throwing, so the shell can render a screen', () => {
  assert.deepEqual(describeHost('aicountly.io'), {
    supported: true,
    hostname: 'aicountly.io',
    mode: 'personal',
    reason: null,
  })

  const unknown = describeHost('mail.example.com')
  assert.equal(unknown.supported, false)
  assert.equal(unknown.mode, null)
  assert.match(unknown.reason, /not configured/i)
})

test('normalizeHostname keeps IPv6 literals intact', () => {
  assert.equal(normalizeHostname('[::1]'), '[::1]')
  assert.equal(normalizeHostname('[::1]:5173'), '[::1]')
  assert.equal(normalizeHostname('example.com:443'), 'example.com')
})

test('the business host resolves its API same-origin', () => {
  globalThis.window = { location: { origin: 'https://email.aicountly.com', hostname: 'email.aicountly.com' } }

  assert.equal(
    resolveEmailApiBaseUrl('email.aicountly.com', 'business'),
    'https://email.aicountly.com/api',
  )
  assert.equal(isCrossOriginApi('https://email.aicountly.com/api'), false)
})

test('the personal host resolves the common backend, cross-origin', () => {
  globalThis.window = { location: { origin: 'https://aicountly.io', hostname: 'aicountly.io' } }

  const base = resolveEmailApiBaseUrl('aicountly.io', 'personal')
  assert.equal(base, 'https://email.apis.aicountly.com')
  // The two frontends are on different registrable domains by design, so the
  // personal one is genuinely cross-origin with the API and the CORS allowlist
  // is load-bearing.
  assert.equal(isCrossOriginApi(base), true)
})

test('both production destinations are named in one place', () => {
  assert.equal(PRODUCTION_ORIGINS.business, 'https://email.aicountly.com')
  assert.equal(PRODUCTION_ORIGINS.personal, 'https://aicountly.io')
  // The release verifier refuses any origin outside this set.
  assert.equal(new URL(PRODUCTION_ORIGINS.business).protocol, 'https:')
  assert.equal(new URL(PRODUCTION_ORIGINS.personal).protocol, 'https:')
})
