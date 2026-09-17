import { useState } from 'react'
import {
  Archive,
  ChevronLeft,
  CornerUpLeft,
  CornerUpRight,
  Download,
  Eye,
  ImageOff,
  MoreHorizontal,
  Reply,
  Star,
  Trash2,
} from 'lucide-react'
import { fetchAttachment } from '../services/api.ts'
import type { Message, ThreadAnalysis } from '../services/types.ts'
import { SecureMessageFrame } from './SecureMessageFrame.tsx'
import { AiUnavailable, StateView } from './States.tsx'

interface ReadingPaneProps {
  messages: Message[]
  analysis: ThreadAnalysis | null
  analysisLoading: boolean
  mailboxId: number
  folder: string
  showBack: boolean
  onBack: () => void
  onAction: (action: string, uid: string) => void
  onReply: (mode: 'reply' | 'reply_all' | 'forward', message: Message) => void
  onLoadRemoteImages: () => void
  onOpenSource: (uid: string) => void
}

function formatDateTime(iso: string | null): string {
  if (!iso) return ''
  const date = new Date(iso)

  return Number.isNaN(date.getTime())
    ? iso
    : date.toLocaleString(undefined, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

export function ReadingPane({
  messages,
  analysis,
  analysisLoading,
  mailboxId,
  folder,
  showBack,
  onBack,
  onAction,
  onReply,
  onLoadRemoteImages,
  onOpenSource,
}: ReadingPaneProps) {
  const [expanded, setExpanded] = useState<Set<string>>(() => new Set(messages.slice(-1).map((m) => m.uid)))

  if (messages.length === 0) {
    return (
      <article className="reading-pane panel">
        <StateView title="Pick a message" description="Choose a conversation on the left to read it here." />
      </article>
    )
  }

  const latest = messages[messages.length - 1]

  const toggle = (uid: string) =>
    setExpanded((current) => {
      const next = new Set(current)
      if (next.has(uid)) next.delete(uid)
      else next.add(uid)
      return next
    })

  return (
    <article className="reading-pane panel" aria-labelledby="message-title">
      <div className="message-toolbar" aria-label="Message actions">
        {showBack ? (
          <button type="button" className="button button--quiet" onClick={onBack}>
            <ChevronLeft size={16} aria-hidden /> Back
          </button>
        ) : null}

        <button type="button" className="icon-button" aria-label="Archive" onClick={() => onAction('archive', latest.uid)}>
          <Archive size={17} aria-hidden />
        </button>
        <button
          type="button"
          className="icon-button"
          aria-label={latest.flagged ? 'Remove star' : 'Star'}
          aria-pressed={latest.flagged}
          onClick={() => onAction(latest.flagged ? 'unstar' : 'star', latest.uid)}
        >
          <Star size={17} aria-hidden />
        </button>
        <button type="button" className="icon-button" aria-label="Move to trash" onClick={() => onAction('trash', latest.uid)}>
          <Trash2 size={17} aria-hidden />
        </button>
        <button type="button" className="icon-button" aria-label="Report as spam" onClick={() => onAction('spam', latest.uid)}>
          <MoreHorizontal size={17} aria-hidden />
        </button>

        <span className="message-toolbar__spacer" />

        <button type="button" className="icon-button" aria-label="Reply" onClick={() => onReply('reply', latest)}>
          <Reply size={17} aria-hidden />
        </button>
        <button type="button" className="icon-button" aria-label="Reply all" onClick={() => onReply('reply_all', latest)}>
          <CornerUpLeft size={17} aria-hidden />
        </button>
        <button type="button" className="icon-button" aria-label="Forward" onClick={() => onReply('forward', latest)}>
          <CornerUpRight size={17} aria-hidden />
        </button>
      </div>

      <header className="message-header">
        <h2 id="message-title">{latest.subject}</h2>
        <div className="sender">
          <span className="avatar avatar--blue" aria-hidden>
            {(latest.from[0]?.name || latest.from[0]?.address || '?').slice(0, 2).toUpperCase()}
          </span>
          <div>
            <strong>{latest.from[0]?.name || latest.from[0]?.address}</strong>
            <p>
              {latest.from[0]?.address} · {formatDateTime(latest.date)}
            </p>
            <small>To {latest.to.map((to) => to.name || to.address).join(', ') || '—'}</small>
          </div>
        </div>
      </header>

      {/* --- AI summary, with its sources, or an honest absence ------------- */}
      {analysisLoading ? (
        <section className="ai-summary" aria-busy="true">
          <div className="skeleton" style={{ height: 12, width: '38%' }} />
          <div className="skeleton" style={{ height: 12, width: '85%', marginTop: 8 }} />
        </section>
      ) : analysis?.summary.available ? (
        <section className="ai-summary" aria-label="AI summary">
          <h3 className="ai-summary__heading">✦ The change that matters</h3>
          <p>{analysis.summary.text}</p>
          <div className="chip-row">
            {analysis.summary.sources.map((source, index) => (
              <button
                key={`${source.uid ?? index}`}
                type="button"
                className="source-chip"
                onClick={() => source.uid && onOpenSource(source.uid)}
              >
                {source.from ?? 'Message'} · {source.date ? formatDateTime(source.date) : 'no date'}
              </button>
            ))}
          </div>
          <small>{analysis.summary.uncertainty}</small>
        </section>
      ) : analysis ? (
        <AiUnavailable reason={analysis.summary.reason} adminHint={analysis.ai.admin_hint} />
      ) : null}

      {analysis?.classification ? (
        <div className="banner banner--info" role="status">
          <span>
            <strong>{analysis.classification.label}.</strong> {analysis.classification.reason}{' '}
            {analysis.classification.deadline
              ? `Deadline ${analysis.classification.deadline}.`
              : analysis.classification.deadline_note}
            {analysis.classification.ai_generated ? ' (AI-assisted classification.)' : ''}
          </span>
        </div>
      ) : null}

      {/* --- The messages ---------------------------------------------------- */}
      {messages.map((message, index) => {
        const isOpen = expanded.has(message.uid) || index === messages.length - 1

        return (
          <section key={message.uid} aria-label={`Message from ${message.from[0]?.address ?? 'unknown sender'}`}>
            {messages.length > 1 ? (
              <button
                type="button"
                className="context-link"
                aria-expanded={isOpen}
                onClick={() => toggle(message.uid)}
              >
                <span className="truncate">
                  {message.from[0]?.name || message.from[0]?.address} · {formatDateTime(message.date)}
                </span>
                <span>{isOpen ? 'Collapse' : 'Expand'}</span>
              </button>
            ) : null}

            {isOpen ? (
              <>
                {message.render.blocked_remote_images > 0 ? (
                  <div className="message-frame__notice">
                    <span className="message-row__line">
                      <ImageOff size={15} aria-hidden />
                      {message.render.blocked_remote_images} remote image
                      {message.render.blocked_remote_images === 1 ? '' : 's'} blocked. Loading them tells the sender you
                      opened this message.
                    </span>
                    <button type="button" className="button button--quiet" onClick={onLoadRemoteImages}>
                      Load images
                    </button>
                  </div>
                ) : null}

                <SecureMessageFrame html={message.html} subject={message.subject} />

                {message.attachments.length > 0 ? (
                  <div className="attachment-list">
                    {message.attachments.map((attachment) => (
                      <div className="attachment" key={attachment.part_id}>
                        <span className="file-icon" aria-hidden>
                          {(attachment.filename.split('.').pop() ?? 'FILE').slice(0, 4).toUpperCase()}
                        </span>
                        <span className="attachment__meta">
                          <span className="truncate">{attachment.filename}</span>
                          <small>
                            {formatBytes(attachment.size)}
                            {attachment.scanned ? ' · scanned' : ' · not scanned'}
                          </small>
                          {attachment.scan_note ? <small>{attachment.scan_note}</small> : null}
                        </span>

                        {attachment.previewable ? (
                          <button
                            type="button"
                            className="icon-button"
                            aria-label={`Preview ${attachment.filename}`}
                            onClick={async () => {
                              const { blob } = await fetchAttachment(mailboxId, message.uid, attachment.part_id, folder, true)
                              const url = URL.createObjectURL(blob)
                              window.open(url, '_blank', 'noopener,noreferrer')
                              window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
                            }}
                          >
                            <Eye size={16} aria-hidden />
                          </button>
                        ) : null}

                        <button
                          type="button"
                          className="icon-button"
                          aria-label={`Download ${attachment.filename}`}
                          onClick={async () => {
                            const { blob, filename } = await fetchAttachment(
                              mailboxId,
                              message.uid,
                              attachment.part_id,
                              folder,
                            )
                            const url = URL.createObjectURL(blob)
                            const anchor = document.createElement('a')
                            anchor.href = url
                            anchor.download = filename
                            anchor.click()
                            URL.revokeObjectURL(url)
                          }}
                        >
                          <Download size={16} aria-hidden />
                        </button>
                      </div>
                    ))}
                  </div>
                ) : null}
              </>
            ) : null}
          </section>
        )
      })}
    </article>
  )
}
