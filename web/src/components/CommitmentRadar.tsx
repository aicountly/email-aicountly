import { CalendarPlus, Check } from 'lucide-react'
import type { Commitment } from '../services/types.ts'

/**
 * The commitment radar.
 *
 * The whole point of this component is the distinction it draws: a supplier's
 * proposal and an agreed date look different, read differently, and the
 * proposal carries "Not yet agreed" until somebody presses Confirm. Nothing
 * here can flip that state on its own, and the API refuses it for a service
 * caller.
 *
 * "Add to Calendar" does not create an event here. It opens the action preview,
 * where the exact operation is shown and approved — and Calendar creates the
 * event, because Calendar owns events.
 */
interface CommitmentRadarProps {
  commitments: Commitment[]
  canCreateEvents: boolean
  eventsUnavailableReason: string | null
  onConfirm: (commitment: Commitment) => void
  onCreateEvent: (commitment: Commitment) => void
  onOpenSource: (commitment: Commitment) => void
}

function toneFor(state: string): 'warning' | 'success' | 'neutral' | 'danger' {
  switch (state) {
    case 'user_confirmed':
      return 'success'
    case 'disputed':
      return 'danger'
    case 'completed':
      return 'neutral'
    default:
      return 'warning'
  }
}

export function CommitmentRadar({
  commitments,
  canCreateEvents,
  eventsUnavailableReason,
  onConfirm,
  onCreateEvent,
  onOpenSource,
}: CommitmentRadarProps) {
  if (commitments.length === 0) {
    return (
      <section className="insight-card" aria-labelledby="radar-title">
        <h3 id="radar-title">Commitment radar</h3>
        <p className="muted">No promises found in this conversation.</p>
      </section>
    )
  }

  return (
    <section className="insight-card" aria-labelledby="radar-title">
      <h3 id="radar-title">Commitment radar</h3>

      <ol className="commitment-list">
        {commitments.map((commitment) => (
          <li key={commitment.commitment_id}>
            <div className="message-row__line">
              <strong>{commitment.proposed_date ?? 'No date stated'}</strong>
              <span className={`badge badge--${toneFor(commitment.state)}`}>{commitment.state_label}</span>
            </div>
            <span className="muted">{commitment.what}</span>
            <span className="muted">
              {commitment.direction === 'incoming' ? 'Promised to you by ' : 'You promised '}
              {commitment.direction === 'incoming' ? commitment.promised_by : commitment.promised_to}
              {commitment.proposed_date ? ` · ${commitment.timezone}` : null}
            </span>

            {/* Never paraphrased: the exact words, so the user can see what was
                actually written rather than what a model made of it. */}
            <blockquote className="muted" style={{ margin: 0, paddingLeft: 10, borderLeft: '2px solid var(--line)' }}>
              “{commitment.source_quote}”
            </blockquote>

            <div className="chip-row" style={{ marginTop: 6 }}>
              <button type="button" className="source-chip" onClick={() => onOpenSource(commitment)}>
                Open source message
              </button>

              {commitment.agreed ? null : (
                <button type="button" className="source-chip" onClick={() => onConfirm(commitment)}>
                  <Check size={13} aria-hidden /> Confirm this
                </button>
              )}

              <button
                type="button"
                className="source-chip"
                onClick={() => onCreateEvent(commitment)}
                disabled={!canCreateEvents || !commitment.proposed_date}
                title={
                  !commitment.proposed_date
                    ? 'This promise has no date, so there is nothing to put in a diary.'
                    : canCreateEvents
                      ? 'Preview an event for Calendar to create'
                      : (eventsUnavailableReason ?? 'Calendar is not available.')
                }
              >
                <CalendarPlus size={13} aria-hidden /> Add to Calendar
              </button>
            </div>
          </li>
        ))}
      </ol>

      {commitments.some((commitment) => !commitment.agreed) ? (
        <span className="badge badge--warning">Not yet agreed</span>
      ) : null}
    </section>
  )
}
