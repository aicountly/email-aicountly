import { PenLine } from 'lucide-react'
import type { Folder, Mailbox, NavigationItem } from '../services/types.ts'

/**
 * The mailbox navigation.
 *
 * The ITEMS come from the backend (see SessionController::navigation) and the
 * LAYOUT from the hostname. A shared-mailboxes entry therefore cannot appear
 * for an account that has no shared mailboxes, whichever domain the browser is
 * on — the list is filtered where the entitlement is known, not here.
 */
interface MailboxNavProps {
  items: NavigationItem[]
  folders: Folder[]
  activeKey: string
  mailboxes: Mailbox[]
  activeMailbox: Mailbox | null
  displayName: string
  workspaceLabel: string
  onSelect: (item: NavigationItem) => void
  onSelectMailbox: (mailboxId: number) => void
  onCompose: () => void
}

function countFor(item: NavigationItem, folders: Folder[]): number | null {
  const folder = folders.find((candidate) => candidate.role === item.role)

  return folder?.unread ?? null
}

export function MailboxNav({
  items,
  folders,
  activeKey,
  mailboxes,
  activeMailbox,
  displayName,
  workspaceLabel,
  onSelect,
  onSelectMailbox,
  onCompose,
}: MailboxNavProps) {
  return (
    <aside className="mail-navigation" aria-label="Mailbox">
      <a className="wordmark" href="/">
        aicountly <span>EMAIL</span>
      </a>

      <button type="button" className="button button--primary compose-button" onClick={onCompose}>
        <PenLine size={16} aria-hidden /> Compose
      </button>

      <nav className="nav-list" aria-label="Folders">
        {items.map((item) => {
          const count = countFor(item, folders)

          return (
            <button
              key={item.key}
              type="button"
              className={item.key === activeKey ? 'nav-item is-selected' : 'nav-item'}
              aria-current={item.key === activeKey ? 'page' : undefined}
              onClick={() => onSelect(item)}
            >
              <span>{item.label}</span>
              {count !== null && count > 0 ? (
                <span className="nav-item__count">
                  {count}
                  <span className="sr-only"> unread</span>
                </span>
              ) : null}
            </button>
          )
        })}
      </nav>

      {mailboxes.length > 1 ? (
        <div style={{ marginTop: 16 }}>
          <label className="muted" htmlFor="mailbox-picker">
            Mailbox
          </label>
          <select
            id="mailbox-picker"
            value={activeMailbox?.mailbox_id ?? ''}
            onChange={(event) => onSelectMailbox(Number(event.target.value))}
            style={{ width: '100%', marginTop: 6, padding: 8, borderRadius: 8, border: '1px solid var(--line)' }}
          >
            {mailboxes.map((mailbox) => (
              <option key={mailbox.mailbox_id} value={mailbox.mailbox_id}>
                {mailbox.address}
                {mailbox.kind === 'shared' ? ' (shared)' : ''}
              </option>
            ))}
          </select>
        </div>
      ) : null}

      <div className="navigation-footer">
        <button type="button" className="account-button">
          <span className="avatar" aria-hidden>
            {displayName.slice(0, 2).toUpperCase()}
          </span>
          <span>
            {displayName}
            <small>{workspaceLabel}</small>
          </span>
        </button>
      </div>
    </aside>
  )
}
