import { usePrefersReducedMotion } from '../hooks/useMediaQuery.ts'
import type { Briefing } from '../services/types.ts'
import { VoiceBriefing } from '../components/VoiceBriefing.tsx'

/**
 * The morning briefing.
 *
 * Every chip is a COUNT OF THINGS THAT EXIST, and clicking it opens them. There
 * is no "hours saved" and no money total, because nobody measured the hours and
 * an email is not a ledger — the disclaimer under the chips says exactly that,
 * in the product, not only in the docs.
 */
interface BriefingBarProps {
  briefing: Briefing | null
  loading: boolean
  voiceAvailable: boolean
  voiceReason: string | null
  onOpenCount: (key: string) => void
}

export function BriefingBar({ briefing, loading, voiceAvailable, voiceReason, onOpenCount }: BriefingBarProps) {
  const reducedMotion = usePrefersReducedMotion()

  const transcript = briefing
    ? [
        briefing.greeting,
        ...Object.entries(briefing.counts).map(([key, count]) => `${count.value} ${key.replace(/_/g, ' ')}.`),
        ...briefing.decisions.slice(0, 3).map((decision) => `${decision.label}: ${decision.reason}`),
      ].join(' ')
    : ''

  return (
    <section className="briefing" aria-labelledby="briefing-title">
      <div className="pulse-orb" aria-hidden data-active={loading && !reducedMotion ? 'true' : 'false'} />

      <div className="briefing__copy">
        <h1 id="briefing-title">{briefing?.greeting ?? 'Good day.'}</h1>
        <p>Your inbox, organised around what needs you.</p>

        <div className="chip-row">
          {loading ? (
            <>
              <span className="skeleton" style={{ height: 24, width: 110, borderRadius: 20 }} />
              <span className="skeleton" style={{ height: 24, width: 140, borderRadius: 20 }} />
            </>
          ) : briefing ? (
            Object.entries(briefing.counts).map(([key, count]) => (
              <button
                key={key}
                type="button"
                className="chip"
                onClick={() => onOpenCount(key)}
                title={count.source}
              >
                {count.value} {key.replace(/_/g, ' ')}
              </button>
            ))
          ) : (
            <span className="muted">Nothing to brief yet.</span>
          )}
        </div>

        {briefing ? <p className="source-note">{briefing.disclaimer}</p> : null}
      </div>

      <div className="briefing__voice">
        <VoiceBriefing text={transcript} available={voiceAvailable} unavailableReason={voiceReason} />
      </div>
    </section>
  )
}
