import { PhoneCall, ShieldAlert } from 'lucide-react'
import type { PaymentCheck } from '../services/types.ts'

/**
 * "The bank details in this message are not the ones you have seen before."
 *
 * Two things this component refuses to do, both deliberate:
 *
 *  1. It never says a message is safe. SPF, DKIM and DMARC are reported as what
 *     they are — a statement about which server sent the mail — with the caveat
 *     the backend supplies, and there is no green tick anywhere near them. A
 *     compromised supplier account passes all three.
 *
 *  2. It never offers "confirm these details" as the remedy. The only advice
 *     that works against invoice fraud is to ring a number you already had, and
 *     that is the action on the card.
 */
export function PaymentAlert({ check, onVerify }: { check: PaymentCheck; onVerify: () => void }) {
  if (!check.has_payment_details) return null

  const { authentication } = check

  return (
    <section className={check.changed ? 'risk-card' : 'insight-card'} aria-labelledby="payment-alert-title">
      <h3 id="payment-alert-title" className="message-row__line">
        <ShieldAlert size={16} aria-hidden />
        {check.changed ? 'Payment details have changed' : 'This message contains payment details'}
      </h3>

      {check.changed ? (
        <>
          <p>{check.advice}</p>
          <div className="table-scroll">
            <table>
              <caption className="sr-only">Payment details compared with what was seen before</caption>
              <thead>
                <tr>
                  <th scope="col">Detail</th>
                  <th scope="col">Seen before</th>
                  <th scope="col">In this message</th>
                </tr>
              </thead>
              <tbody>
                {check.changes.map((change) => (
                  <tr key={change.field}>
                    <th scope="row">{change.field.replace(/_/g, ' ')}</th>
                    <td>{change.previous}</td>
                    <td>
                      <mark>{change.current}</mark>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="source-note">
            Earlier values come from previous messages from this sender in this mailbox. Only the last four characters
            are shown.
          </p>
          <button type="button" className="button button--outline" onClick={onVerify}>
            <PhoneCall size={15} aria-hidden /> Verify using a known contact
          </button>
        </>
      ) : (
        <p className="muted">
          No earlier payment details from this sender to compare against. Treat the first set you receive with the same
          care as a change.
        </p>
      )}

      <details style={{ marginTop: 10 }}>
        <summary className="muted" style={{ cursor: 'pointer', fontSize: 12 }}>
          Sender authentication
        </summary>
        <p className="muted" style={{ marginTop: 6 }}>
          {authentication.header_present ? (
            <>
              SPF {authentication.spf ?? 'not stated'} · DKIM {authentication.dkim ?? 'not stated'} · DMARC{' '}
              {authentication.dmarc ?? 'not stated'}
            </>
          ) : (
            'The receiving server recorded no authentication result for this message.'
          )}
        </p>
        <p className="source-note">{authentication.caveat}</p>
      </details>
    </section>
  )
}
