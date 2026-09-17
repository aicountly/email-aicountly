import type { ComparisonResult } from '../services/types.ts'

/**
 * Email vs business record.
 *
 * The card renders what the backend computed and adds nothing. In particular it
 * does no arithmetic of its own: a percentage calculated in the browser from
 * two rounded strings is a second answer, and the first one is the right one.
 *
 * When the two sides are not on the same basis, the figures are still shown —
 * the reader can judge — but the deltas are not, and the card says why.
 */
export function ComparisonCard({ result, onRefresh }: { result: ComparisonResult; onRefresh: () => void }) {
  const readAt = new Date(result.source.record.fetched_at)
  const readLabel = Number.isNaN(readAt.getTime())
    ? result.source.record.fetched_at
    : readAt.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })

  return (
    <section className="comparison-card" aria-labelledby="comparison-title">
      <h3 id="comparison-title">Email vs business record</h3>

      {result.requires_review ? (
        <>
          <div className="banner banner--warning" role="status" style={{ marginTop: 10 }}>
            <span>
              <strong>Comparison requires review.</strong> {result.review_reason}
            </span>
          </div>
          <ul style={{ margin: '10px 0 0', paddingLeft: 18, fontSize: 12 }}>
            {result.blockers.map((blocker) => (
              <li key={blocker.field} style={{ padding: '3px 0' }}>
                {blocker.reason}
              </li>
            ))}
          </ul>

          {result.side_by_side && result.side_by_side.length > 0 ? (
            <div className="table-scroll">
              <table>
                <caption className="sr-only">The two sides, shown without a calculated difference</caption>
                <thead>
                  <tr>
                    <th scope="col">Field</th>
                    <th scope="col">Business record</th>
                    <th scope="col">Latest email</th>
                  </tr>
                </thead>
                <tbody>
                  {result.side_by_side.map((row) => (
                    <tr key={row.field}>
                      <th scope="row">{row.label}</th>
                      <td>{row.record ?? '—'}</td>
                      <td>{row.email ?? '—'}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          ) : null}
        </>
      ) : result.differences.length === 0 ? (
        <p className="muted" style={{ marginTop: 10 }}>
          Every field this email states matches the business record.
        </p>
      ) : (
        <div className="table-scroll">
          <table>
            <caption className="sr-only">Business record and email compared field by field</caption>
            <thead>
              <tr>
                <th scope="col">Field</th>
                <th scope="col">Business record</th>
                <th scope="col">Latest email</th>
              </tr>
            </thead>
            <tbody>
              {result.differences.map((difference) => (
                <tr key={difference.field}>
                  <th scope="row">{difference.label}</th>
                  <td>
                    {difference.kind === 'amount' && difference.currency ? `${difference.currency} ` : ''}
                    {difference.record}
                  </td>
                  <td>
                    <mark>
                      {difference.kind === 'amount' && difference.currency ? `${difference.currency} ` : ''}
                      {difference.email}
                      {difference.kind === 'amount' && difference.delta_percent
                        ? ` · ${Number(difference.delta_percent) > 0 ? '+' : ''}${difference.delta_percent}%`
                        : null}
                      {difference.kind === 'date' && typeof difference.delta_days === 'number'
                        ? ` · ${difference.delta_days > 0 ? '+' : ''}${difference.delta_days} day${Math.abs(difference.delta_days) === 1 ? '' : 's'}`
                        : null}
                    </mark>
                    {/* The direction is spelled out as well as marked, so the
                        highlight is not the only thing carrying it. */}
                    <span className="sr-only">
                      {difference.direction === 'increase'
                        ? 'increased'
                        : difference.direction === 'decrease'
                          ? 'decreased'
                          : difference.direction}
                    </span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <p className="source-note">
        Source: {result.source.record.service} · {result.source.record.id} · read live at {readLabel}.{' '}
        <button type="button" className="text-button" onClick={onRefresh}>
          Re-read
        </button>
      </p>
      <p className="source-note">Differences are calculated by Email, not by a language model.</p>
    </section>
  )
}
