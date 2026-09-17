import { useEffect, useMemo } from 'react'
import { useAuth } from './auth/AuthProvider.tsx'
import { readFrontendConfig } from './config/frontend.ts'
import { EmailShell } from './shell/EmailShell.tsx'
import SignIn from './pages/SignIn.tsx'
import { SessionProvider } from './state/SessionProvider.tsx'
import { initAnalytics, trackPageView } from './utils/analytics.ts'
import './styles/email.css'

initAnalytics()

/**
 * The hostname has already been checked in main.tsx, which is why this
 * component can assume it is a configured one and resolve the frontend config
 * without a try.
 *
 * What it does decide is what to show while the portal round trip is in
 * flight, and it never shows the mailbox before the backend has said what this
 * account may do — that answer comes from SessionProvider, not from the host.
 */
export default function App({ hostname }: { hostname: string }) {
  const { status } = useAuth()
  const config = useMemo(() => readFrontendConfig(hostname), [hostname])

  useEffect(() => {
    if (status === 'authenticated') trackPageView('/mail', 'Mail')
    else if (status === 'signed-out') trackPageView('/sign-in', 'Sign in')
  }, [status])

  if (status === 'signed-out') return <SignIn />

  if (status !== 'authenticated') {
    return (
      <main className="host-error">
        <div className="host-error__panel">
          <p className="muted" role="status">
            Signing you in…
          </p>
        </div>
      </main>
    )
  }

  return (
    <SessionProvider config={config}>
      <EmailShell />
    </SessionProvider>
  )
}
