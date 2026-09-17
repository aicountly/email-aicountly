/**
 * Every state this product can be in that is not "here is your mail".
 *
 * They live together because the rule that governs them is one rule: SAY WHAT
 * IS TRUE. An inbox that cannot be reached is not an empty inbox. An
 * integration nobody configured is not an error. A send whose outcome is
 * unknown is not a failure and not a success. Each of those is a different
 * sentence, and the difference is what the user acts on.
 *
 * There is no endless spinner anywhere in here: every loading state is bounded
 * by the caller's own timeout, and every failure has a next step.
 */

import type { ReactNode } from 'react'
import {
  AlertTriangle,
  CheckCircle2,
  CloudOff,
  HelpCircle,
  Inbox,
  Lock,
  PlugZap,
  RefreshCw,
  Search,
  Settings2,
} from 'lucide-react'
import type { ApiError } from '../services/api.ts'

interface StateViewProps {
  icon?: ReactNode
  title: string
  description?: ReactNode
  hint?: ReactNode
  action?: ReactNode
  inline?: boolean
}

export function StateView({ icon, title, description, hint, action, inline }: StateViewProps) {
  return (
    <div className={inline ? 'state state--inline' : 'state'} role="status">
      {icon}
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {hint ? <p className="state__hint">{hint}</p> : null}
      {action}
    </div>
  )
}

/** A list that is loading. Shaped like the rows it will become, so nothing jumps. */
export function ListSkeleton({ rows = 6 }: { rows?: number }) {
  return (
    <div aria-busy="true" aria-live="polite">
      <span className="sr-only">Loading messages…</span>
      {Array.from({ length: rows }, (_, index) => (
        <div className="skeleton-row" key={index} aria-hidden>
          <div className="skeleton skeleton-row__avatar" />
          <div className="skeleton-row__lines">
            <div className="skeleton" style={{ height: 10, width: '45%' }} />
            <div className="skeleton" style={{ height: 12, width: '80%' }} />
            <div className="skeleton" style={{ height: 10, width: '62%' }} />
          </div>
        </div>
      ))}
    </div>
  )
}

export function EmptyInbox({ folderLabel }: { folderLabel: string }) {
  return (
    <StateView
      icon={<Inbox size={28} aria-hidden />}
      title={`Nothing in ${folderLabel}`}
      description="When a message arrives it will appear here."
    />
  )
}

export function NoSearchResults({ query, onClear }: { query: string; onClear: () => void }) {
  return (
    <StateView
      icon={<Search size={28} aria-hidden />}
      title="No messages match that search"
      description={<>Nothing in this mailbox matches “{query}”.</>}
      action={
        <button type="button" className="button button--quiet" onClick={onClear}>
          Clear the search
        </button>
      }
    />
  )
}

export function Offline({ onRetry }: { onRetry: () => void }) {
  return (
    <StateView
      icon={<CloudOff size={28} aria-hidden />}
      title="You are offline"
      description="Email cannot reach the server. Anything you type is kept in the composer until the connection is back."
      action={
        <button type="button" className="button button--outline" onClick={onRetry}>
          <RefreshCw size={15} aria-hidden /> Try again
        </button>
      }
    />
  )
}

export function SessionExpired({ onSignIn }: { onSignIn: () => void }) {
  return (
    <StateView
      icon={<Lock size={28} aria-hidden />}
      title="Your session has expired"
      description="Sign in again to carry on. Nothing you were writing has been sent."
      action={
        <button type="button" className="button button--primary" onClick={onSignIn}>
          Sign in
        </button>
      }
    />
  )
}

export function PermissionDenied({ reason }: { reason?: string | null }) {
  return (
    <StateView
      icon={<Lock size={28} aria-hidden />}
      title="You do not have access to this"
      description={reason ?? 'This account does not have permission for this mailbox or this feature.'}
    />
  )
}

/**
 * A dependency that was never set up.
 *
 * Deliberately not styled as an error: nothing is broken. The admin hint is
 * shown only when the backend sent one, because it names a server setting and
 * that is useful to an administrator and noise to everybody else.
 */
export function NotConfigured({
  title,
  reason,
  adminHint,
}: {
  title: string
  reason?: string | null
  adminHint?: string | null
}) {
  return (
    <StateView
      icon={<Settings2 size={28} aria-hidden />}
      title={title}
      description={reason ?? 'This part of Email has not been configured for this deployment.'}
      hint={adminHint ? <>Administrator: {adminHint}</> : undefined}
    />
  )
}

export function MailboxUnavailable({ reason, onRetry }: { reason?: string | null; onRetry: () => void }) {
  return (
    <StateView
      icon={<AlertTriangle size={28} aria-hidden />}
      title="Mailbox unavailable"
      description={reason ?? 'The mail store did not answer. Your mail is not lost — Email just cannot read it right now.'}
      action={
        <button type="button" className="button button--outline" onClick={onRetry}>
          <RefreshCw size={15} aria-hidden /> Try again
        </button>
      }
    />
  )
}

export function IntegrationUnavailable({ label, state, reason }: { label: string; state: string; reason?: string | null }) {
  const title =
    state === 'not_configured'
      ? `${label} is not configured`
      : state === 'unsupported'
        ? `${label} is not connected yet`
        : state === 'permission_required'
          ? `${label} needs permission`
          : `${label} is temporarily unavailable`

  return (
    <StateView
      inline
      icon={<PlugZap size={22} aria-hidden />}
      title={title}
      description={reason ?? 'This panel will work once the integration is available. Your email is unaffected.'}
    />
  )
}

export function AiUnavailable({ reason, adminHint }: { reason?: string | null; adminHint?: string | null }) {
  return (
    <StateView
      inline
      icon={<HelpCircle size={22} aria-hidden />}
      title="Pulse AI is unavailable"
      description={reason ?? 'No model is configured for this deployment, so summaries and drafting are switched off.'}
      hint={adminHint ? <>Administrator: {adminHint}</> : undefined}
    />
  )
}

export function ActionSucceeded({ label, reference }: { label: string; reference?: string | null }) {
  return (
    <div className="banner banner--success" role="status">
      <CheckCircle2 size={16} aria-hidden />
      <span>
        {label} completed{reference ? <> · reference {reference}</> : null}.
      </span>
    </div>
  )
}

/**
 * An error, rendered from what the API actually said.
 *
 * The correlation id is shown because it is the one thing that finds this
 * request in the server log, and a support conversation that starts with it is
 * five minutes instead of an afternoon.
 */
export function ApiErrorView({ error, onRetry }: { error: ApiError; onRetry?: () => void }) {
  if (error.notConfigured) {
    return <NotConfigured title="Not set up yet" reason={error.message} adminHint={error.adminHint} />
  }
  if (error.sessionExpired) {
    return <SessionExpired onSignIn={() => window.location.reload()} />
  }
  if (error.status === 403) {
    return <PermissionDenied reason={error.message} />
  }
  if (error.code === 'offline') {
    return <Offline onRetry={onRetry ?? (() => window.location.reload())} />
  }

  return (
    <StateView
      icon={<AlertTriangle size={28} aria-hidden />}
      title={error.rateLimited ? 'Too many requests' : 'Something went wrong'}
      description={error.message}
      hint={error.correlationId ? <>Reference {error.correlationId}</> : undefined}
      action={
        error.retryable && onRetry ? (
          <button type="button" className="button button--outline" onClick={onRetry}>
            <RefreshCw size={15} aria-hidden /> Try again
          </button>
        ) : undefined
      }
    />
  )
}
