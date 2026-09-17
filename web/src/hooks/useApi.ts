/**
 * Fetch-on-mount with loading, error and reload, cancelled cleanly on unmount.
 *
 * The abort matters in a mail client more than most places: clicking down a
 * message list faster than the network answers means the FIRST response paints
 * last, and the reading pane shows a message the user has already left.
 */

import { useCallback, useEffect, useState } from 'react'
import { ApiError } from '../services/api.ts'

export interface AsyncState<T> {
  data: T | null
  loading: boolean
  error: ApiError | null
  reload: () => void
  setData: (next: T | null) => void
}

export function useApi<T>(
  fetcher: (signal: AbortSignal) => Promise<T>,
  deps: unknown[],
  enabled = true,
): AsyncState<T> {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(enabled)
  const [error, setError] = useState<ApiError | null>(null)
  const [token, setToken] = useState(0)

  const reload = useCallback(() => setToken((n) => n + 1), [])

  useEffect(() => {
    if (!enabled) {
      setLoading(false)
      return
    }

    const controller = new AbortController()
    let cancelled = false

    setLoading(true)
    setError(null)

    fetcher(controller.signal)
      .then((result) => {
        if (!cancelled) setData(result)
      })
      .catch((err: unknown) => {
        // An abort is this component going away, not a failure to report.
        if (cancelled || controller.signal.aborted) return
        setError(
          err instanceof ApiError
            ? err
            : new ApiError(0, 'unknown', err instanceof Error ? err.message : 'Something went wrong.'),
        )
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
      controller.abort()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, token, enabled])

  return { data, loading, error, reload, setData }
}
