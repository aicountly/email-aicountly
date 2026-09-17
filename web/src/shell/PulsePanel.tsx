import { Send } from 'lucide-react'
import { usePrefersReducedMotion } from '../hooks/useMediaQuery.ts'
import type {
  CatalogAction,
  Commitment,
  ComparisonResult,
  IntegrationService,
  ThreadAnalysis,
} from '../services/types.ts'
import { CommitmentRadar } from '../components/CommitmentRadar.tsx'
import { ComparisonCard } from '../components/ComparisonCard.tsx'
import { PaymentAlert } from '../components/PaymentAlert.tsx'
import { AiUnavailable, IntegrationUnavailable } from '../components/States.tsx'

/**
 * The contextual Pulse panel.
 *
 * Every card in here is allowed to be absent, and says why when it is. The
 * panel as a whole is never allowed to stop somebody reading their mail: an
 * unreachable integration greys out one section, and the inbox and the reading
 * pane carry on.
 */
interface PulsePanelProps {
  analysis: ThreadAnalysis | null
  analysisLoading: boolean
  comparison: ComparisonResult | null
  comparisonError: string | null
  services: IntegrationService[]
  actions: CatalogAction[]
  aiAvailable: boolean
  aiReason: string | null
  aiAdminHint: string | null
  question: string
  onQuestionChange: (value: string) => void
  onAsk: () => void
  onConfirmCommitment: (commitment: Commitment) => void
  onCreateEvent: (commitment: Commitment) => void
  onOpenSource: (uid: string) => void
  onRefreshComparison: () => void
  onPreviewAction: (action: CatalogAction) => void
  onVerifyPayment: () => void
}

function serviceFor(services: IntegrationService[], key: string): IntegrationService | undefined {
  return services.find((service) => service.service === key)
}

export function PulsePanel({
  analysis,
  analysisLoading,
  comparison,
  comparisonError,
  services,
  actions,
  aiAvailable,
  aiReason,
  aiAdminHint,
  question,
  onQuestionChange,
  onAsk,
  onConfirmCommitment,
  onCreateEvent,
  onOpenSource,
  onRefreshComparison,
  onPreviewAction,
  onVerifyPayment,
}: PulsePanelProps) {
  const reducedMotion = usePrefersReducedMotion()
  const calendar = serviceFor(services, 'calendar')
  const calendarAction = actions.find((action) => action.key === 'calendar.create_event')

  return (
    <aside className="pulse-panel panel" aria-labelledby="pulse-title">
      <header className="pulse-heading">
        <div
          className="pulse-orb pulse-orb--small"
          aria-hidden
          // Animated only while Pulse is actually working, and never when the
          // operating system has asked for less motion.
          data-active={analysisLoading && !reducedMotion ? 'true' : 'false'}
        />
        <div>
          <h2 id="pulse-title">Pulse AI</h2>
          <p>Context into action</p>
        </div>
      </header>

      {!aiAvailable ? <AiUnavailable reason={aiReason} adminHint={aiAdminHint} /> : null}

      {analysis?.payment_check ? (
        <PaymentAlert check={analysis.payment_check} onVerify={onVerifyPayment} />
      ) : null}

      {comparison ? (
        <ComparisonCard result={comparison} onRefresh={onRefreshComparison} />
      ) : comparisonError ? (
        <section className="insight-card">
          <h3>Email vs business record</h3>
          <p className="muted">{comparisonError}</p>
        </section>
      ) : null}

      {analysis ? (
        <CommitmentRadar
          commitments={analysis.commitments}
          canCreateEvents={calendarAction?.available === true}
          eventsUnavailableReason={calendarAction?.reason ?? calendar?.state_reason ?? null}
          onConfirm={onConfirmCommitment}
          onCreateEvent={onCreateEvent}
          onOpenSource={(commitment) => commitment.source_uid && onOpenSource(commitment.source_uid)}
        />
      ) : null}

      <section className="insight-card" aria-labelledby="context-title">
        <h3 id="context-title">Connected context</h3>

        {services.length === 0 ? (
          <p className="muted">No integrations are configured for this deployment.</p>
        ) : (
          services
            .filter((service) => ['purchases', 'inventory', 'drive', 'calendar', 'books', 'contacts'].includes(service.service))
            .map((service) => (
              <button
                key={service.service}
                type="button"
                className="context-link"
                disabled={service.state !== 'connected'}
                title={service.state_reason ?? `Open ${service.label}`}
                onClick={() => {
                  if (service.api_base_url) window.open(service.api_base_url, '_blank', 'noopener,noreferrer')
                }}
              >
                <span className="truncate">
                  {service.label}
                  <br />
                  <span className="muted">{service.state.replace(/_/g, ' ')}</span>
                </span>
                <span>{service.state === 'connected' ? 'Open' : 'Unavailable'}</span>
              </button>
            ))
        )}
      </section>

      <section className="insight-card" aria-labelledby="actions-title">
        <h3 id="actions-title">Email → action</h3>
        <p className="muted">Each one is previewed before anything happens.</p>

        {actions.length === 0 ? (
          <IntegrationUnavailable label="Product actions" state="not_configured" reason={null} />
        ) : (
          actions.slice(0, 6).map((action) => (
            <div className="action-preview" key={action.key}>
              <strong>{action.label}</strong>
              <span className="muted">{action.summary}</span>
              {action.available ? null : <span className="badge badge--neutral">{action.reason}</span>}
              <button
                type="button"
                className="button button--outline"
                disabled={!action.available}
                onClick={() => onPreviewAction(action)}
              >
                Preview action
              </button>
            </div>
          ))
        )}
      </section>

      <form
        className="pulse-question"
        onSubmit={(event) => {
          event.preventDefault()
          onAsk()
        }}
      >
        <label className="sr-only" htmlFor="pulse-question">
          Ask Pulse about this thread
        </label>
        <input
          id="pulse-question"
          value={question}
          onChange={(event) => onQuestionChange(event.target.value)}
          placeholder="Ask about this thread…"
          disabled={!aiAvailable}
        />
        <button className="icon-button" type="submit" aria-label="Ask Pulse" disabled={!aiAvailable}>
          <Send size={16} aria-hidden />
        </button>
      </form>
    </aside>
  )
}
