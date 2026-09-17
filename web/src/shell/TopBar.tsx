import { useEffect, useRef } from 'react'
import { Menu, Search, Sparkles } from 'lucide-react'
import type { FrontendMode } from '../config/frontend.ts'

/**
 * Search, the workspace selector and the account menu.
 *
 * The workspace selector is business-only and is genuinely absent in the
 * personal experience — not disabled, not greyed out. A personal user is never
 * asked to pick a company, and is never asked to create one.
 */
interface TopBarProps {
  mode: FrontendMode
  query: string
  searchFocusToken: number
  workspaceLabel: string | null
  displayName: string
  canSwitchWorkspace: boolean
  onQueryChange: (value: string) => void
  onSubmitSearch: () => void
  onOpenNavigation: () => void
  onOpenWorkspaces: () => void
  onOpenPulse: () => void
  onSignOut: () => void
}

export function TopBar({
  mode,
  query,
  searchFocusToken,
  workspaceLabel,
  displayName,
  canSwitchWorkspace,
  onQueryChange,
  onSubmitSearch,
  onOpenNavigation,
  onOpenWorkspaces,
  onOpenPulse,
  onSignOut,
}: TopBarProps) {
  const inputRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (searchFocusToken > 0) inputRef.current?.focus()
  }, [searchFocusToken])

  return (
    <header className="topbar">
      <button type="button" className="icon-button topbar__menu" aria-label="Open navigation" onClick={onOpenNavigation}>
        <Menu size={20} aria-hidden />
      </button>

      <form
        className="search-field"
        role="search"
        onSubmit={(event) => {
          event.preventDefault()
          onSubmitSearch()
        }}
      >
        <Search size={16} aria-hidden />
        <label className="sr-only" htmlFor="global-search">
          Search email or ask Pulse
        </label>
        <input
          id="global-search"
          ref={inputRef}
          type="search"
          value={query}
          onChange={(event) => onQueryChange(event.target.value)}
          placeholder="Ask your inbox or search anything…"
        />
        <kbd>Ctrl K</kbd>
      </form>

      {mode === 'business' && canSwitchWorkspace ? (
        <button type="button" className="button button--quiet workspace-picker" onClick={onOpenWorkspaces}>
          {workspaceLabel ?? 'Choose a company'}
        </button>
      ) : null}

      <button type="button" className="icon-button" aria-label="Open Pulse" onClick={onOpenPulse}>
        <Sparkles size={18} aria-hidden />
      </button>

      <button type="button" className="avatar" aria-label={`Account menu for ${displayName}`} onClick={onSignOut}>
        {displayName.slice(0, 2).toUpperCase()}
      </button>
    </header>
  )
}
