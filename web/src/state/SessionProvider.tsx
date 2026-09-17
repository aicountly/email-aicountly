/**
 * Capabilities, mailboxes and the company scope — resolved once, read everywhere.
 *
 * THE RULE THIS FILE HOLDS: the hostname chose a LAYOUT; this chose the
 * PRIVILEGES, and the layout never grants one. `mode` comes from the host,
 * `capabilities` comes from the backend, and every gate in the UI reads
 * `capabilities`. A business user on aicountly.io keeps their shared mailboxes.
 * A personal user on email.aicountly.com is shown the business chrome with the
 * business features absent and the reason given, because pretending they have
 * them and failing at the API would be worse.
 *
 * SWITCHING COMPANY CLEARS EVERYTHING FROM THE OLD ONE. Not just the id: the
 * fetched comparisons, the previews, the drafts in flight. Anything derived
 * from company A that survives into company B is a cross-tenant leak that looks
 * like a caching bug.
 */

import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import type { ReactNode } from 'react'

import type { FrontendConfig, FrontendMode } from '../config/frontend.ts'
import { ApiError, setScope } from '../services/api.ts'
import type { CompanyScope } from '../services/api.ts'
import { emailApi } from '../services/email.ts'
import type { Capabilities, Mailbox, NavigationItem } from '../services/types.ts'

interface SessionState {
  config: FrontendConfig
  capabilities: Capabilities | null
  loading: boolean
  error: ApiError | null
  mailbox: Mailbox | null
  mailboxes: Mailbox[]
  scope: CompanyScope | null
  /** Bumped on every company switch. Anything derived from a company keys on it. */
  scopeEpoch: number
  mode: FrontendMode
  navigation: NavigationItem[]
  can: (feature: string) => boolean
  reasonFor: (feature: string) => string | null
  selectMailbox: (mailboxId: number) => void
  switchCompany: (next: CompanyScope | null) => void
  reload: () => void
}

const SessionContext = createContext<SessionState | null>(null)

const SELECTED_MAILBOX_KEY = 'email:selected-mailbox'
const SCOPE_KEY = 'email:scope'

function readStoredScope(): CompanyScope | null {
  try {
    const raw = window.sessionStorage.getItem(SCOPE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as CompanyScope
    return typeof parsed?.cmp_id === 'number' ? parsed : null
  } catch {
    return null
  }
}

export function SessionProvider({ config, children }: { config: FrontendConfig; children: ReactNode }) {
  const [capabilities, setCapabilities] = useState<Capabilities | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<ApiError | null>(null)
  const [mailboxId, setMailboxId] = useState<number | null>(null)
  const [scope, setScopeState] = useState<CompanyScope | null>(readStoredScope)
  const [scopeEpoch, setScopeEpoch] = useState(0)
  const [reloadToken, setReloadToken] = useState(0)

  // Register the scope with the API client before anything fetches, so no
  // request can go out carrying the previous company.
  useEffect(() => {
    setScope(scope)
  }, [scope])

  useEffect(() => {
    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    emailApi
      .capabilities(controller.signal)
      .then((response) => {
        if (cancelled) return
        setCapabilities(response.data)

        const stored = Number(window.localStorage.getItem(SELECTED_MAILBOX_KEY) ?? '')
        const available = response.data.mailboxes
        const chosen = available.find((m) => m.mailbox_id === stored) ?? available[0] ?? null
        setMailboxId(chosen ? chosen.mailbox_id : null)
      })
      .catch((err: unknown) => {
        if (cancelled || controller.signal.aborted) return
        setError(err instanceof ApiError ? err : new ApiError(0, 'unknown', 'Could not load your Email account.'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [reloadToken, scopeEpoch])

  const selectMailbox = useCallback((next: number) => {
    setMailboxId(next)
    try {
      window.localStorage.setItem(SELECTED_MAILBOX_KEY, String(next))
    } catch {
      /* private mode — the choice simply does not persist */
    }
  }, [])

  const switchCompany = useCallback((next: CompanyScope | null) => {
    // Clear the transient store BEFORE the new scope is registered: anything
    // read afterwards must come from the new company or not at all.
    try {
      window.sessionStorage.clear()
      if (next) window.sessionStorage.setItem(SCOPE_KEY, JSON.stringify(next))
    } catch {
      /* ignore */
    }

    setScope(next)
    setScopeState(next)
    // Every company-derived query keys on this, so they all refetch rather
    // than showing the previous company's answer while the new one loads.
    setScopeEpoch((n) => n + 1)
  }, [])

  const can = useCallback(
    (feature: string) => capabilities?.features?.[feature]?.enabled === true,
    [capabilities],
  )

  const reasonFor = useCallback(
    (feature: string) => capabilities?.features?.[feature]?.reason ?? null,
    [capabilities],
  )

  const mailbox = useMemo(
    () => capabilities?.mailboxes.find((m) => m.mailbox_id === mailboxId) ?? null,
    [capabilities, mailboxId],
  )

  /**
   * The navigation for the hostname's experience.
   *
   * The LAYOUT follows the host; the ITEMS come from the backend. That is how a
   * business user on aicountly.io gets the personal chrome without losing
   * anything, and how a personal user on the business host is never shown a
   * shared-mailboxes item they cannot use.
   */
  const navigation = useMemo(() => {
    if (!capabilities) return []
    const items = config.mode === 'personal' ? capabilities.navigation.personal : capabilities.navigation.business

    return items.filter((item) => {
      if (item.key === 'shared') return can('shared_mailboxes')
      if (item.key === 'documents') return can('product_actions')
      return true
    })
  }, [capabilities, config.mode, can])

  const value: SessionState = {
    config,
    capabilities,
    loading,
    error,
    mailbox,
    mailboxes: capabilities?.mailboxes ?? [],
    scope,
    scopeEpoch,
    mode: config.mode,
    navigation,
    can,
    reasonFor,
    selectMailbox,
    switchCompany,
    reload: () => setReloadToken((n) => n + 1),
  }

  return <SessionContext.Provider value={value}>{children}</SessionContext.Provider>
}

export function useSession(): SessionState {
  const context = useContext(SessionContext)
  if (!context) throw new Error('useSession must be used inside <SessionProvider>')

  return context
}
