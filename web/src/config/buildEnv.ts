/**
 * Reading a build-time value without assuming a bundler.
 *
 * `import.meta.env` is Vite's, and it does not exist when a module is imported
 * by the Node test runner. Every module that reads it goes through here, so the
 * pure parts of the app — the host table, the API resolution, the error
 * semantics — can be tested directly instead of only through a browser.
 *
 * Nothing here is secret. Vite inlines every VITE_* value into the bundle, so
 * anything read through this function is public the moment the app is served.
 */

export interface BuildEnv {
  readonly DEV?: boolean
  readonly BASE_URL?: string
  readonly [key: string]: string | boolean | undefined
}

export function buildEnv(): BuildEnv {
  return ((import.meta as unknown as { env?: BuildEnv }).env ?? {}) as BuildEnv
}

export function env(key: string): string {
  const raw = buildEnv()[key]

  return typeof raw === 'string' ? raw.trim() : ''
}

export function isDevBuild(): boolean {
  return buildEnv().DEV === true
}
