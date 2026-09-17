import { useEffect, useState } from 'react'

/**
 * A media query as state.
 *
 * The three breakpoints below are the ones the layout actually changes at, and
 * they match design/email-workspace.css so the reference and the application do
 * not drift.
 */
export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() =>
    typeof window === 'undefined' ? false : window.matchMedia(query).matches,
  )

  useEffect(() => {
    const list = window.matchMedia(query)
    const onChange = (event: MediaQueryListEvent) => setMatches(event.matches)

    setMatches(list.matches)
    list.addEventListener('change', onChange)

    return () => list.removeEventListener('change', onChange)
  }, [query])

  return matches
}

/** Under 640px: one pane at a time, with Back. */
export const useIsMobile = () => useMediaQuery('(max-width: 640px)')

/** Under 950px: list or message, navigation behind a trigger. */
export const useIsTablet = () => useMediaQuery('(max-width: 950px)')

/** Under 1250px: Pulse becomes a drawer rather than a third column. */
export const usePulseIsDrawer = () => useMediaQuery('(max-width: 1250px)')

/** Honour the operating system's reduced-motion setting everywhere. */
export const usePrefersReducedMotion = () => useMediaQuery('(prefers-reduced-motion: reduce)')
