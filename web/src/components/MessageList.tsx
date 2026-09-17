import { useMemo } from 'react'
import { Paperclip, SlidersHorizontal, Star } from 'lucide-react'
import type { ThreadSummary } from '../services/types.ts'
import { EmptyInbox, ListSkeleton, NoSearchResults } from './States.tsx'

interface MessageListProps {
  threads: ThreadSummary[]
  loading: boolean
  selectedUid: string | null
  folderLabel: string
  filter: 'priority' | 'all' | 'people'
  searchQuery: string
  hasMore: boolean
  loadingMore: boolean
  insights: Record<string, { label: string; tone: 'warning' | 'danger' | 'neutral' }>
  onSelect: (thread: ThreadSummary) => void
  onFilterChange: (filter: 'priority' | 'all' | 'people') => void
  onLoadMore: () => void
  onClearSearch: () => void
  onExplain: () => void
}

function initials(name: string, address: string): string {
  const source = name.trim() || address.trim()
  const parts = source.split(/[\s.@_-]+/).filter(Boolean)

  return (parts.slice(0, 2).map((part) => part[0]).join('') || '?').toUpperCase()
}

/** Today shows a time, this year a date, older a date with the year. */
function formatWhen(iso: string | null): string {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''

  const now = new Date()
  const sameDay = date.toDateString() === now.toDateString()
  if (sameDay) return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })

  const sameYear = date.getFullYear() === now.getFullYear()

  return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: sameYear ? undefined : 'numeric' })
}

const AVATAR_TONES = ['avatar--blue', 'avatar--purple', 'avatar--peach', ''] as const

export function MessageList({
  threads,
  loading,
  selectedUid,
  folderLabel,
  filter,
  searchQuery,
  hasMore,
  loadingMore,
  insights,
  onSelect,
  onFilterChange,
  onLoadMore,
  onClearSearch,
  onExplain,
}: MessageListProps) {
  const filtered = useMemo(() => {
    if (filter === 'priority') {
      // "Priority" is not a re-sort: it is the threads Pulse has a reason for,
      // and the reason is on the row. Everything else is one click away under
      // All mail, so nothing is hidden.
      const prioritised = threads.filter((thread) => insights[thread.thread_key] !== undefined || !thread.seen)
      return prioritised.length > 0 ? prioritised : threads
    }
    if (filter === 'people') {
      return threads.filter((thread) => {
        const address = thread.from[0]?.address ?? ''
        return !/(no-?reply|newsletter|notification|mailer|bounce)/i.test(address)
      })
    }

    return threads
  }, [threads, filter, insights])

  return (
    <section className="message-list panel" aria-labelledby="inbox-title">
      <header className="panel-heading">
        <h2 id="inbox-title">{searchQuery ? `Results for “${searchQuery}”` : folderLabel}</h2>
        <button className="icon-button" type="button" aria-label="Inbox filters" onClick={() => onFilterChange('all')}>
          <SlidersHorizontal size={17} aria-hidden />
        </button>
      </header>

      <div className="filter-row" role="tablist" aria-label="Inbox view">
        {(['priority', 'all', 'people'] as const).map((value) => (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={filter === value}
            className={filter === value ? 'filter is-active' : 'filter'}
            onClick={() => onFilterChange(value)}
          >
            {value === 'priority' ? 'Priority' : value === 'all' ? 'All mail' : 'People'}
          </button>
        ))}
      </div>

      {loading ? (
        <ListSkeleton />
      ) : filtered.length === 0 ? (
        searchQuery ? (
          <NoSearchResults query={searchQuery} onClear={onClearSearch} />
        ) : (
          <EmptyInbox folderLabel={folderLabel} />
        )
      ) : (
        <div className="message-rows" role="list">
          {filtered.map((thread, index) => {
            const sender = thread.from[0] ?? { name: '', address: '' }
            const insight = insights[thread.thread_key]
            const selected = thread.uid === selectedUid

            return (
              <button
                key={thread.uid}
                type="button"
                role="listitem"
                aria-current={selected ? 'true' : undefined}
                className={[
                  'message-row',
                  selected ? 'is-selected' : '',
                  thread.seen ? '' : 'is-unread',
                ].filter(Boolean).join(' ')}
                onClick={() => onSelect(thread)}
              >
                <span className={`avatar ${AVATAR_TONES[index % AVATAR_TONES.length]}`} aria-hidden>
                  {initials(sender.name, sender.address)}
                </span>
                <span className="message-row__content">
                  <span className="message-row__meta">
                    <strong className="truncate">{sender.name || sender.address}</strong>
                    <time dateTime={thread.date ?? undefined}>{formatWhen(thread.date)}</time>
                  </span>
                  <span className="message-row__line">
                    <strong className="message-row__subject truncate">{thread.subject}</strong>
                    {thread.has_attachments ? <Paperclip size={12} aria-label="Has attachments" /> : null}
                    {thread.flagged ? <Star size={12} aria-label="Starred" /> : null}
                  </span>
                  {insight ? (
                    <span className={`badge badge--${insight.tone}`}>{insight.label}</span>
                  ) : (
                    <span className="muted truncate">{thread.to.map((to) => to.address).join(', ') || ' '}</span>
                  )}
                  {/* Unread is carried by weight AND by this label, because
                      colour and weight alone are not information. */}
                  {thread.seen ? null : <span className="sr-only">Unread</span>}
                </span>
              </button>
            )
          })}

          {hasMore ? (
            <button type="button" className="text-button list-explanation" onClick={onLoadMore} disabled={loadingMore}>
              {loadingMore ? 'Loading…' : 'Load older messages'}
            </button>
          ) : null}
        </div>
      )}

      {filtered.length > 0 && filter === 'priority' ? (
        <button type="button" className="text-button list-explanation" onClick={onExplain}>
          Explain this priority
        </button>
      ) : null}
    </section>
  )
}
