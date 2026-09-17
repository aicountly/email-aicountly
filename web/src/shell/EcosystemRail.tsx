import { BookOpen, Boxes, CalendarDays, HardDrive, Mail, Users } from 'lucide-react'
import { AppLauncher } from '../components/AppLauncher.tsx'
import { getAppById, launchApp } from '../services/appLauncher.ts'

/**
 * The slim rail of sibling products.
 *
 * It launches through the existing AICOUNTLY launcher, which carries the portal
 * session, so switching products does not mean signing in again. Only products
 * that exist in the shared catalogue are here; a tile for something that is not
 * deployed would be a dead end.
 */
const RAIL = [
  { id: 'books', label: 'Books', Icon: BookOpen },
  { id: 'inventory', label: 'Inventory', Icon: Boxes },
  { id: 'calendar', label: 'Calendar', Icon: CalendarDays },
  { id: 'docs', label: 'Drive', Icon: HardDrive },
  // The catalog id stays `receptionist` after the Lobby rename — it keys the
  // uploaded product icon in Manage. Only the name and the hosts moved.
  { id: 'receptionist', label: 'Lobby', Icon: Users },
] as const

export function EcosystemRail() {
  return (
    <aside className="ecosystem" aria-label="Aicountly applications">
      {/* The fleet-wide launcher, so every AICOUNTLY product is reachable and
          not just the six with a tile below. */}
      <AppLauncher />

      <span className="ecosystem__brand" aria-hidden style={{ marginTop: 44 }}>
        <Mail size={22} />
      </span>

      <button type="button" className="ecosystem__item is-active" aria-current="page">
        <Mail size={18} aria-hidden />
        Mail
      </button>

      {RAIL.map(({ id, label, Icon }) => (
        <button
          key={id}
          type="button"
          className="ecosystem__item"
          onClick={() => launchApp(getAppById(id), { newTab: true })}
          title={`Open ${label} in a new tab`}
        >
          <Icon size={18} aria-hidden />
          {label}
        </button>
      ))}
    </aside>
  )
}
