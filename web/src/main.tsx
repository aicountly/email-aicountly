import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthProvider.tsx'
import { describeHost } from './config/frontend.ts'
import { UnsupportedHost } from './pages/UnsupportedHost.tsx'
import './index.css'

const rootElement = document.getElementById('root')
if (!rootElement) throw new Error('Root element #root not found')

/**
 * The host check happens BEFORE the auth provider is mounted, and that ordering
 * is the point.
 *
 * AuthProvider jumps to the portal on boot when it finds no token. If it were
 * mounted first, an unreviewed hostname would bounce the visitor through
 * my.aicountly.com — sending a real sign-in attempt, with this origin as the
 * returnUrl, from a domain nobody configured — and only then render the error
 * screen. Failing closed means the application never starts here at all.
 */
const resolution = describeHost(window.location.hostname)

createRoot(rootElement).render(
  <StrictMode>
    {resolution.supported ? (
      <AuthProvider>
        <App hostname={resolution.hostname} />
      </AuthProvider>
    ) : (
      <UnsupportedHost hostname={resolution.hostname} reason={resolution.reason} />
    )}
  </StrictMode>,
)
