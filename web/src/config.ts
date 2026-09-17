/**
 * Build-time configuration.
 *
 * Vite inlines every VITE_* value into the bundle when the app is compiled, so
 * these are public values and the deployed app never reads a .env from disk.
 * See docs/DEPLOYMENT.md.
 *
 * WHICH API THIS APP CALLS IS RESOLVED AT RUNTIME, from the hostname the page
 * was served from — see config/frontend.ts. That is what lets one built
 * artifact serve both email.aicountly.com and aicountly.io: the business host
 * is same-origin with its own /api, the personal host is not, and neither needs
 * its own build.
 */

import { env } from './config/buildEnv.ts'
import { describeHost, resolveEmailApiBaseUrl } from './config/frontend.ts'

export const APP_NAME = env('VITE_APP_NAME') || 'Email'

/** `local` | `sandbox` | `production` — set by the deploy workflows. */
export const APP_ENV = env('VITE_APP_ENV') || 'local'

const CONFIGURED_API_BASE_URL = env('VITE_API_BASE_URL')

/**
 * Base URL of the Email API for this hostname.
 *
 * Kept as a function, not a constant, because the auth bootstrap imports it
 * before the shell has decided anything, and because a host this build does not
 * recognise must not be given an API to call at all.
 */
export function getApiBaseUrl(): string {
  const resolution = describeHost(window.location.hostname)

  if (!resolution.supported || resolution.mode === null) {
    // An unrecognised host still needs somewhere for the auth relay to go, and
    // its own origin is the only defensible guess. The shell refuses to render
    // the application on such a host regardless.
    return CONFIGURED_API_BASE_URL
      ? CONFIGURED_API_BASE_URL.replace(/\/+$/, '')
      : `${window.location.origin}/api`
  }

  return resolveEmailApiBaseUrl(resolution.hostname, resolution.mode)
}
