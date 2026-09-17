import { useState } from 'react'
import { AlertTriangle, ShieldCheck } from 'lucide-react'
import { ApiError } from '../services/api.ts'
import type { ActionPreview } from '../services/types.ts'
import { Dialog } from './Dialog.tsx'
import { ApiErrorView } from './States.tsx'

/**
 * Understand → Preview → Approve → Execute → Receipt, on screen.
 *
 * The preview shows the target product, the exact operation, the exact payload,
 * the permissions it needs and whether it can be undone — BEFORE anything is
 * sent. The approve button carries the preview's digest back, so a preview the
 * user read five minutes ago cannot be applied to facts that have since
 * changed: the backend refuses it and this dialog says so.
 *
 * Nothing here retries by itself. An uncertain outcome is shown as uncertain
 * with what to check, because guessing is how one approval becomes two events.
 */
interface ActionPreviewDialogProps {
  open: boolean
  preview: ActionPreview | null
  error: ApiError | null
  busy: boolean
  onApprove: (preview: ActionPreview) => void
  onRefresh: () => void
  onClose: () => void
}

export function ActionPreviewDialog({
  open,
  preview,
  error,
  busy,
  onApprove,
  onRefresh,
  onClose,
}: ActionPreviewDialogProps) {
  const [acknowledged, setAcknowledged] = useState(false)
  const stale = error?.details?.status === 'stale'

  const done = preview !== null && ['succeeded', 'failed', 'partial', 'uncertain'].includes(preview.status)

  return (
    <Dialog
      open={open}
      title={preview ? preview.operation_label : 'Preview action'}
      description={preview ? `${preview.target_label} will be asked to do this. Nothing has happened yet.` : undefined}
      onClose={onClose}
      footer={
        preview && !done ? (
          <>
            <button type="button" className="button button--quiet" onClick={onClose}>
              Cancel
            </button>
            <button
              type="button"
              className="button button--primary"
              disabled={busy || !preview.executable || (!preview.reversible && !acknowledged)}
              onClick={() => onApprove(preview)}
            >
              {busy ? 'Working…' : `Approve and send to ${preview.target_label}`}
            </button>
          </>
        ) : (
          <button type="button" className="button button--quiet" onClick={onClose}>
            Close
          </button>
        )
      }
    >
      {error ? (
        stale ? (
          <div className="banner banner--warning" role="alert">
            <AlertTriangle size={16} aria-hidden />
            <span>
              <strong>This preview is out of date.</strong> The underlying details changed after it was shown, so it
              was not sent. Refresh and read it again.
            </span>
            <span className="banner__actions">
              <button type="button" className="button button--outline" onClick={onRefresh}>
                Refresh preview
              </button>
            </span>
          </div>
        ) : (
          <ApiErrorView error={error} onRetry={onRefresh} />
        )
      ) : null}

      {preview ? (
        <div style={{ display: 'grid', gap: 14 }}>
          <div>
            <h3>What will happen</h3>
            <p className="muted">{preview.summary}</p>
          </div>

          <div>
            <h3>Target</h3>
            <p className="muted">
              {preview.target_label} · <code>{preview.operation}</code>
            </p>
          </div>

          <div>
            <h3>Exactly what is sent</h3>
            <div className="table-scroll">
              <table>
                <caption className="sr-only">The payload of this action</caption>
                <thead>
                  <tr>
                    <th scope="col">Field</th>
                    <th scope="col">Value</th>
                  </tr>
                </thead>
                <tbody>
                  {Object.entries(preview.proposal).map(([field, value]) => (
                    <tr key={field}>
                      <th scope="row">{field}</th>
                      <td>{typeof value === 'object' ? JSON.stringify(value) : String(value ?? '—')}</td>
                    </tr>
                  ))}
                  {Object.keys(preview.proposal).length === 0 ? (
                    <tr>
                      <td colSpan={2} className="muted">
                        This action sends no data of its own.
                      </td>
                    </tr>
                  ) : null}
                </tbody>
              </table>
            </div>
          </div>

          <div>
            <h3>Permissions this needs</h3>
            <ul className="muted" style={{ margin: 0, paddingLeft: 18, fontSize: 13 }}>
              {preview.required_permissions.map((permission) => (
                <li key={permission}>{permission}</li>
              ))}
            </ul>
          </div>

          <div className={preview.reversible ? 'banner banner--info' : 'banner banner--warning'}>
            <ShieldCheck size={16} aria-hidden />
            <span>
              {preview.reversible ? 'Reversible. ' : 'Not reversible. '}
              {preview.reverse_note}
            </span>
          </div>

          {!preview.reversible && !done ? (
            <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 13 }}>
              <input
                type="checkbox"
                checked={acknowledged}
                onChange={(event) => setAcknowledged(event.target.checked)}
              />
              <span>I understand this cannot be undone from Email.</span>
            </label>
          ) : null}

          {preview.not_executable_reason ? (
            <div className="banner banner--info" role="status">
              <span>{preview.not_executable_reason}</span>
            </div>
          ) : null}

          {preview.status === 'succeeded' ? (
            <div className="banner banner--success" role="status">
              <span>
                Done. {preview.target_label} accepted it
                {Object.keys(preview.external_ref).length > 0 ? (
                  <> · reference {Object.values(preview.external_ref).filter(Boolean).join(' · ')}</>
                ) : null}
                .
              </span>
            </div>
          ) : null}

          {preview.status === 'uncertain' ? (
            <div className="banner banner--warning" role="alert">
              <AlertTriangle size={16} aria-hidden />
              <span>
                <strong>Outcome uncertain.</strong> {preview.error} Email will not retry this on its own.
              </span>
            </div>
          ) : null}

          {preview.status === 'partial' ? (
            <div className="banner banner--warning" role="alert">
              <span>
                <strong>Partly done.</strong> {preview.error}
              </span>
            </div>
          ) : null}

          {preview.status === 'failed' && preview.error ? (
            <div className="banner banner--danger" role="alert">
              <span>{preview.error}</span>
            </div>
          ) : null}

          <p className="source-note">{preview.atomicity_note}</p>
          <p className="source-note">Reference {preview.correlation_id}</p>
        </div>
      ) : null}
    </Dialog>
  )
}
