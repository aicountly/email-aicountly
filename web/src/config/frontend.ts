/**
 * Which experience this hostname renders, and which Email API it talks to.
 *
 * ONE build serves both destinations. The hostname selects PRESENTATION only:
 *
 *   email.aicountly.com   business experience
 *   aicountly.io          personal experience
 *
 * It grants nothing. What the signed-in account may actually do arrives from
 * the backend (`GET /api/v1/capabilities`) and is the only thing the UI is
 * allowed to gate real privileges on. A business user who opens aicountly.io
 * keeps their account; a personal user who opens email.aicountly.com gains
 * nothing by doing so.
 *
 * Mailbox address and frontend hostname are also separate concepts: a business
 * account may send from @aicountly.io or from its own custom domain, so the
 * address suffix is never read as an account type.
 *
 * Nothing secret belongs in this file. Every VITE_* value is inlined into the
 * bundle at build time and is public the moment the app is served.
 */

import { env, isDevBuild } from './buildEnv.ts'

export type FrontendMode = 'business' | 'personal'

/** The two production destinations. Exhaustive, and deliberately hard-coded. */
const PRODUCTION_HOSTS: Readonly<Record<string, FrontendMode>> = Object.freeze({
  'email.aicountly.com': 'business',
  'aicountly.io': 'personal',
})

/**
 * Non-production hosts, each one reviewed before it was added.
 *
 * `email.gh.aicountly.com` is the AICOUNTLY sandbox zone for this product and
 * already serves it today — see docs/auth/AICOUNTLY_AUTH_WORKFLOW.md. Anything
 * else must be added through VITE_EXTRA_BUSINESS_HOSTS /
 * VITE_EXTRA_PERSONAL_HOSTS at build time, which is a reviewed change to a
 * deploy workflow rather than a wildcard in the code.
 */
const REVIEWED_STAGING_HOSTS: Readonly<Record<string, FrontendMode>> = Object.freeze({
  'email.gh.aicountly.com': 'business',
  'io.gh.aicountly.com': 'personal',
})

const DEV_HOSTS = ['localhost', '127.0.0.1', '[::1]', '::1'] as const

/** The common Email backend, when the frontend is not same-origin with it. */
const COMMON_EMAIL_API = 'https://email.apis.aicountly.com'

/** `a.example.com, b.example.com` → ['a.example.com', 'b.example.com'] */
function hostList(key: string): string[] {
  return env(key)
    .split(',')
    .map((host) => normalizeHostname(host))
    .filter((host) => host !== '')
}

/**
 * Lower-cased, port-stripped, trailing-dot-stripped.
 *
 * The trailing dot matters: `aicountly.io.` is the same origin to a browser and
 * a different string to a lookup table, which is one way a host check gets
 * bypassed. IPv6 literals keep their colons — stripping at the first one would
 * turn `[::1]` into `[`.
 */
export function normalizeHostname(hostname: string): string {
  const raw = String(hostname ?? '')
    .trim()
    .toLowerCase()
    .replace(/\.$/, '')

  if (raw.startsWith('[')) {
    const close = raw.indexOf(']')
    return close === -1 ? raw : raw.slice(0, close + 1)
  }
  // A bare IPv6 literal has two or more colons and no port to strip.
  if ((raw.match(/:/g) ?? []).length >= 2) return raw

  return raw.split(':')[0]
}

function lookupConfiguredMode(host: string): FrontendMode | null {
  if (PRODUCTION_HOSTS[host]) return PRODUCTION_HOSTS[host]
  if (REVIEWED_STAGING_HOSTS[host]) return REVIEWED_STAGING_HOSTS[host]
  if (hostList('VITE_EXTRA_BUSINESS_HOSTS').includes(host)) return 'business'
  if (hostList('VITE_EXTRA_PERSONAL_HOSTS').includes(host)) return 'personal'
  return null
}

function isDevHost(host: string): boolean {
  return (DEV_HOSTS as readonly string[]).includes(host) || host.endsWith('.localhost')
}

/**
 * The presentation mode for a hostname, or a throw.
 *
 * Throwing is the point: an unconfigured hostname is either a
 * misconfiguration or somebody serving this bundle from a domain nobody
 * reviewed, and neither should quietly get the business experience. The app
 * catches it and renders the unsupported-host screen.
 */
export function resolveFrontendMode(hostname: string): FrontendMode {
  const host = normalizeHostname(hostname)
  const configured = lookupConfiguredMode(host)
  if (configured) return configured

  if (isDevBuild() && isDevHost(host)) {
    return env('VITE_DEV_FRONTEND_MODE') === 'personal' ? 'personal' : 'business'
  }

  throw new Error('This hostname is not configured for Aicountly Email.')
}

export interface HostResolution {
  supported: boolean
  hostname: string
  mode: FrontendMode | null
  reason: string | null
}

/** The non-throwing form, so the shell can render a real screen instead of a blank page. */
export function describeHost(hostname: string): HostResolution {
  const host = normalizeHostname(hostname)
  try {
    return { supported: true, hostname: host, mode: resolveFrontendMode(host), reason: null }
  } catch (error) {
    return {
      supported: false,
      hostname: host,
      mode: null,
      reason: error instanceof Error ? error.message : 'This hostname is not configured for Aicountly Email.',
    }
  }
}

/**
 * Base URL of the Email API for a given frontend host.
 *
 * Resolved at RUNTIME from the hostname, which is what lets one artifact serve
 * both destinations: the business host is same-origin with its own `/api`
 * (the shape every AICOUNTLY product deploys today), and the personal host is
 * not, so it calls the common backend over CORS.
 *
 * Order: per-mode build override → shared build override → host default.
 * `VITE_API_BASE_URL` is the existing fleet-wide override and keeps working
 * unchanged; setting it points BOTH destinations at one backend, which is what
 * you want once email.apis.aicountly.com is serving.
 */
export function resolveEmailApiBaseUrl(hostname: string, mode: FrontendMode): string {
  const perMode = mode === 'personal' ? env('VITE_PERSONAL_API_BASE_URL') : env('VITE_BUSINESS_API_BASE_URL')
  if (perMode) return perMode.replace(/\/+$/, '')

  const shared = env('VITE_API_BASE_URL')
  if (shared) return shared.replace(/\/+$/, '')

  const host = normalizeHostname(hostname)
  if (mode === 'personal' && !isDevHost(host)) return COMMON_EMAIL_API

  const origin = typeof window === 'undefined' ? `https://${host}` : window.location.origin
  return `${origin}/api`
}

/** True when the API is on another origin, so the app must send credentials cross-origin. */
export function isCrossOriginApi(apiBaseUrl: string): boolean {
  if (typeof window === 'undefined') return false
  try {
    return new URL(apiBaseUrl, window.location.origin).origin !== window.location.origin
  } catch {
    return false
  }
}

export interface FrontendConfig {
  readonly mode: FrontendMode
  readonly hostname: string
  readonly emailApiBaseUrl: string
  readonly crossOriginApi: boolean
  /** True for localhost and the *.gh.aicountly.com zone — never for a production host. */
  readonly isProductionHost: boolean
}

/**
 * Resolved once, for the host this page was served from.
 *
 * Exported as a function rather than a frozen module constant so that importing
 * this module never throws: the shell asks for it inside a try, and renders the
 * unsupported-host screen when it fails.
 */
export function readFrontendConfig(hostname: string = typeof window === 'undefined' ? '' : window.location.hostname): FrontendConfig {
  const host = normalizeHostname(hostname)
  const mode = resolveFrontendMode(host)
  const emailApiBaseUrl = resolveEmailApiBaseUrl(host, mode)

  return Object.freeze({
    mode,
    hostname: host,
    emailApiBaseUrl,
    crossOriginApi: isCrossOriginApi(emailApiBaseUrl),
    isProductionHost: Object.prototype.hasOwnProperty.call(PRODUCTION_HOSTS, host),
  })
}

/** Both destinations, for the deploy docs and the release verifier. */
export const PRODUCTION_ORIGINS = Object.freeze({
  business: 'https://email.aicountly.com',
  personal: 'https://aicountly.io',
})

export const PRODUCT = Object.freeze({
  name: 'Aicountly Email',
  positioning: 'Every conversation moves business forward.',
  legalEntity: 'Aicountly Interactive Services Private Limited',
})
