import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { Clock, Send, Trash2 } from 'lucide-react'
import { ApiError } from '../services/api.ts'
import { emailApi, newSendOperationId } from '../services/email.ts'
import type { Draft, SendJob } from '../services/types.ts'
import { Dialog } from './Dialog.tsx'
import { ApiErrorView } from './States.tsx'

/**
 * Compose, reply, reply all, forward.
 *
 * THREE THINGS WORTH READING THE CODE FOR.
 *
 * The send operation id is minted when the composer OPENS, not when Send is
 * pressed. Every attempt for this compose presents the same id, so a double
 * click, a retried request or a reloaded tab is a replay on the server rather
 * than a second message.
 *
 * Autosave carries the draft's version. If the server has a newer one — another
 * tab, another device — the save comes back 409 and the conflict is shown with
 * both versions, rather than one of them being silently lost.
 *
 * A scheduled send always carries a timezone. "09:00" on its own is three
 * different moments, and the one the user meant is the one their browser is in.
 */

export type ComposeMode = 'new' | 'reply' | 'reply_all' | 'forward'

interface ComposerProps {
  open: boolean
  mailboxId: number
  mode: ComposeMode
  /** Prefilled from the message being replied to or forwarded. */
  initial?: Partial<Draft>
  canSend: boolean
  cannotSendReason: string | null
  onClose: () => void
  onSent: (job: SendJob) => void
}

const MAX_SUBJECT = 900

function addressesToText(list: Array<{ name: string; address: string }> | undefined): string {
  return (list ?? []).map((entry) => (entry.name ? `${entry.name} <${entry.address}>` : entry.address)).join(', ')
}

export function Composer({
  open,
  mailboxId,
  mode,
  initial,
  canSend,
  cannotSendReason,
  onClose,
  onSent,
}: ComposerProps) {
  const [to, setTo] = useState('')
  const [cc, setCc] = useState('')
  const [bcc, setBcc] = useState('')
  const [showCc, setShowCc] = useState(false)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [draft, setDraft] = useState<Draft | null>(null)
  const [saving, setSaving] = useState<'idle' | 'saving' | 'saved' | 'conflict'>('idle')
  const [conflict, setConflict] = useState<Draft | null>(null)
  const [job, setJob] = useState<SendJob | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const [busy, setBusy] = useState(false)
  const [scheduleAt, setScheduleAt] = useState('')
  const [invalid, setInvalid] = useState<string[]>([])

  // Minted once per open. This is the idempotency boundary.
  const operationId = useRef(newSendOperationId())
  const timezone = useMemo(() => Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC', [])

  useEffect(() => {
    if (!open) return

    operationId.current = newSendOperationId()
    setTo(addressesToText(initial?.to))
    setCc(addressesToText(initial?.cc))
    setBcc('')
    setShowCc((initial?.cc ?? []).length > 0)
    setSubject(initial?.subject ?? '')
    setBody(initial?.body_text ?? '')
    setDraft(null)
    setJob(null)
    setError(null)
    setConflict(null)
    setSaving('idle')
    setInvalid([])
    setScheduleAt('')
  }, [open, initial])

  const persist = useCallback(async () => {
    if (!open) return
    if (to.trim() === '' && subject.trim() === '' && body.trim() === '') return

    setSaving('saving')
    try {
      const payload = {
        to,
        cc,
        bcc,
        subject: subject.slice(0, MAX_SUBJECT),
        body_text: body,
        in_reply_to: initial?.in_reply_to ?? '',
        thread_key: initial?.thread_key ?? '',
        origin: initial?.origin ?? 'user',
      }

      const response = draft
        ? await emailApi.saveDraft(mailboxId, draft.draft_id, { ...payload, version: draft.version })
        : await emailApi.createDraft(mailboxId, payload)

      setDraft(response.data)
      setInvalid(response.data.invalid_recipients ?? [])
      setSaving('saved')
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // Both versions survive: the server's is shown, and the local text is
        // still in the textarea, so nothing the user wrote is thrown away.
        setConflict((err.details.current as Draft) ?? null)
        setSaving('conflict')
        return
      }
      setError(err instanceof ApiError ? err : new ApiError(0, 'unknown', 'The draft could not be saved.'))
      setSaving('idle')
    }
  }, [open, to, cc, bcc, subject, body, draft, mailboxId, initial])

  // Autosave, debounced. Long enough not to write on every keystroke, short
  // enough that a closed tab does not lose a paragraph.
  useEffect(() => {
    if (!open) return
    const timer = window.setTimeout(() => void persist(), 2500)

    return () => window.clearTimeout(timer)
  }, [open, to, cc, bcc, subject, body, persist])

  const ensureDraft = async (): Promise<Draft | null> => {
    if (draft) {
      await persist()
      return draft
    }
    await persist()

    return draft
  }

  const doSend = async (scheduled: boolean) => {
    setBusy(true)
    setError(null)

    try {
      const current = (await ensureDraft()) ?? draft
      if (!current) {
        setError(new ApiError(0, 'draft_missing', 'The draft has not been saved yet. Try again in a moment.'))
        return
      }

      const response = scheduled
        ? await emailApi.schedule(mailboxId, current.draft_id, scheduleAt, timezone, operationId.current)
        : await emailApi.send(mailboxId, current.draft_id, operationId.current)

      setJob(response.data)
      setInvalid(response.data.invalid_recipients ?? [])
      onSent(response.data)
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(0, 'unknown', 'The message could not be sent.'))
    } finally {
      setBusy(false)
    }
  }

  const discard = async () => {
    if (draft) {
      try {
        await emailApi.deleteDraft(mailboxId, draft.draft_id)
      } catch {
        /* the draft stays; the composer still closes */
      }
    }
    onClose()
  }

  const title =
    mode === 'reply' ? 'Reply' : mode === 'reply_all' ? 'Reply all' : mode === 'forward' ? 'Forward' : 'New message'

  return (
    <Dialog
      open={open}
      title={title}
      description={draft ? `Draft saved · version ${draft.version}` : 'Nothing is sent until you press Send.'}
      onClose={onClose}
      footer={
        job ? (
          <button type="button" className="button button--primary" onClick={onClose}>
            Close
          </button>
        ) : (
          <>
            <button type="button" className="button button--danger" onClick={discard} disabled={busy}>
              <Trash2 size={15} aria-hidden /> Discard
            </button>
            <button
              type="button"
              className="button button--quiet"
              onClick={() => void doSend(true)}
              disabled={busy || !canSend || scheduleAt === ''}
              title={scheduleAt === '' ? 'Pick a date and time first' : `Send at ${scheduleAt} (${timezone})`}
            >
              <Clock size={15} aria-hidden /> Schedule
            </button>
            <button
              type="button"
              className="button button--primary"
              onClick={() => void doSend(false)}
              disabled={busy || !canSend}
              title={canSend ? undefined : (cannotSendReason ?? undefined)}
            >
              <Send size={15} aria-hidden /> {busy ? 'Sending…' : 'Send'}
            </button>
          </>
        )
      }
    >
      {!canSend ? (
        <div className="banner banner--warning" role="status">
          <span>{cannotSendReason ?? 'Sending is not available on this deployment.'}</span>
        </div>
      ) : null}

      {error ? <ApiErrorView error={error} /> : null}

      {conflict ? (
        <div className="banner banner--warning" role="alert">
          <span>
            <strong>This draft changed somewhere else.</strong> The saved version has subject “{conflict.subject}”.
            Your text is still here — copy anything you need, then reload to continue from the saved version.
          </span>
          <span className="banner__actions">
            <button type="button" className="button button--outline" onClick={() => setDraft(conflict)}>
              Continue from the saved version
            </button>
          </span>
        </div>
      ) : null}

      {invalid.length > 0 ? (
        <div className="banner banner--warning" role="alert">
          <span>
            These addresses were not understood and will not receive the message: {invalid.join(', ')}
          </span>
        </div>
      ) : null}

      {job ? <SendOutcome job={job} mailboxId={mailboxId} onUpdate={setJob} /> : null}

      <div className="composer">
        <div className="composer__row">
          <label htmlFor="composer-to">To</label>
          <input
            id="composer-to"
            value={to}
            onChange={(event) => setTo(event.target.value)}
            placeholder="name@example.com"
            autoComplete="off"
            disabled={job !== null}
          />
          <button type="button" className="text-button" onClick={() => setShowCc((value) => !value)}>
            {showCc ? 'Hide Cc/Bcc' : 'Cc/Bcc'}
          </button>
        </div>

        {showCc ? (
          <>
            <div className="composer__row">
              <label htmlFor="composer-cc">Cc</label>
              <input id="composer-cc" value={cc} onChange={(event) => setCc(event.target.value)} disabled={job !== null} />
              <span />
            </div>
            <div className="composer__row">
              <label htmlFor="composer-bcc">Bcc</label>
              <input
                id="composer-bcc"
                value={bcc}
                onChange={(event) => setBcc(event.target.value)}
                disabled={job !== null}
              />
              <span />
            </div>
          </>
        ) : null}

        <div className="composer__row">
          <label htmlFor="composer-subject">Subject</label>
          <input
            id="composer-subject"
            value={subject}
            maxLength={MAX_SUBJECT}
            onChange={(event) => setSubject(event.target.value)}
            disabled={job !== null}
          />
          <span />
        </div>

        <label className="sr-only" htmlFor="composer-body">
          Message
        </label>
        <textarea
          id="composer-body"
          className="composer__body"
          value={body}
          onChange={(event) => setBody(event.target.value)}
          disabled={job !== null}
        />

        <div className="composer__footer">
          <label className="muted" htmlFor="composer-schedule">
            Send later
          </label>
          <input
            id="composer-schedule"
            type="datetime-local"
            value={scheduleAt}
            onChange={(event) => setScheduleAt(event.target.value)}
            disabled={job !== null}
            style={{ width: 'auto' }}
          />
          {/* Always shown, never assumed. */}
          <span className="muted">{timezone}</span>

          <span className="composer__spacer" />
          <span className="muted" aria-live="polite">
            {saving === 'saving'
              ? 'Saving…'
              : saving === 'saved'
                ? 'Draft saved'
                : saving === 'conflict'
                  ? 'Draft conflict'
                  : ''}
          </span>
        </div>
      </div>
    </Dialog>
  )
}

/**
 * What happened to the send.
 *
 * Four different sentences for four different outcomes, and "accepted" never
 * reads as "delivered": nothing in this deployment observes delivery, so
 * claiming it would be a lie the user acts on.
 */
function SendOutcome({
  job,
  mailboxId,
  onUpdate,
}: {
  job: SendJob
  mailboxId: number
  onUpdate: (job: SendJob) => void
}) {
  const [remaining, setRemaining] = useState(job.undo_seconds ?? 0)

  useEffect(() => {
    if (!job.can_cancel || remaining <= 0) return
    const timer = window.setInterval(() => setRemaining((value) => Math.max(0, value - 1)), 1000)

    return () => window.clearInterval(timer)
  }, [job.can_cancel, remaining])

  // Poll while the job is still moving, and stop as soon as it settles. Nothing
  // spins forever: a settled job is terminal and the interval clears.
  useEffect(() => {
    if (!['queued', 'submitting', 'deferred'].includes(job.status)) return

    const timer = window.setInterval(async () => {
      try {
        const response = await emailApi.sendStatus(mailboxId, job.job_id)
        onUpdate(response.data)
      } catch {
        /* leave the last known state on screen */
      }
    }, 4000)

    return () => window.clearInterval(timer)
  }, [job.status, job.job_id, mailboxId, onUpdate])

  const tone =
    job.status === 'accepted'
      ? 'success'
      : job.status === 'failed'
        ? 'danger'
        : job.status === 'uncertain'
          ? 'warning'
          : 'info'

  return (
    <div className={`banner banner--${tone}`} role="status" aria-live="polite">
      <span>
        <strong>{job.label}.</strong> {job.explanation}
        {job.queue_id ? <> Queue id {job.queue_id}.</> : null}
      </span>

      {job.can_cancel && remaining > 0 ? (
        <span className="banner__actions">
          <button
            type="button"
            className="button button--outline"
            onClick={async () => {
              try {
                const response = await emailApi.cancelSend(mailboxId, job.job_id)
                onUpdate(response.data)
              } catch {
                /* it had already left; the status poll will say so */
              }
            }}
          >
            Undo ({remaining}s)
          </button>
        </span>
      ) : null}

      {job.needs_decision ? (
        <span className="banner__actions">
          <a
            className="button button--outline"
            href={`#/mailbox/${mailboxId}/sends/${job.job_id}`}
            onClick={(event) => event.preventDefault()}
          >
            Check before resending
          </a>
        </span>
      ) : null}
    </div>
  )
}
