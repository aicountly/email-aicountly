import { useEffect } from 'react'

export interface Shortcut {
  /** A single key, or `mod+k` for Ctrl/Cmd. */
  combo: string
  description: string
  run: () => void
}

/**
 * Mail keyboard shortcuts.
 *
 * Two rules that decide whether this helps or infuriates:
 *
 *  1. A shortcut never fires while the user is typing. An `a` pressed inside
 *     the composer is the letter A, not Archive.
 *  2. A shortcut never overrides a browser or assistive-technology binding, so
 *     single letters only, plus mod+k for search, and nothing bound to Tab,
 *     Enter, Space or the arrow keys, which are how the UI is navigated.
 */
export function useKeyboardShortcuts(shortcuts: Shortcut[], enabled = true): void {
  useEffect(() => {
    if (!enabled) return

    const isTyping = (target: EventTarget | null): boolean => {
      if (!(target instanceof HTMLElement)) return false
      const tag = target.tagName.toLowerCase()

      return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable
    }

    const onKeyDown = (event: KeyboardEvent) => {
      const mod = event.metaKey || event.ctrlKey
      const combo = mod ? `mod+${event.key.toLowerCase()}` : event.key.toLowerCase()

      // mod+k is allowed to interrupt typing — it is how you reach search from
      // anywhere, which is the point of it.
      if (isTyping(event.target) && combo !== 'mod+k' && event.key !== 'Escape') return
      if (event.altKey) return
      if (mod && combo !== 'mod+k') return

      const match = shortcuts.find((shortcut) => shortcut.combo === combo)
      if (!match) return

      event.preventDefault()
      match.run()
    }

    window.addEventListener('keydown', onKeyDown)

    return () => window.removeEventListener('keydown', onKeyDown)
  }, [shortcuts, enabled])
}

/** The list the help sheet renders, so the shortcuts are discoverable. */
export const SHORTCUT_HELP: Array<{ combo: string; description: string }> = [
  { combo: 'c', description: 'Compose' },
  { combo: 'Ctrl / ⌘ K', description: 'Search or ask Pulse' },
  { combo: 'j', description: 'Next message' },
  { combo: 'k', description: 'Previous message' },
  { combo: 'e', description: 'Archive' },
  { combo: 's', description: 'Star' },
  { combo: 'u', description: 'Back to the list' },
  { combo: 'r', description: 'Reply' },
  { combo: 'a', description: 'Reply all' },
  { combo: 'f', description: 'Forward' },
  { combo: 'g', description: 'Open Pulse' },
  { combo: '?', description: 'Keyboard shortcuts' },
  { combo: 'Escape', description: 'Close the dialog or drawer' },
]
