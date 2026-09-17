/**
 * A modal that behaves.
 *
 * Four things, and a dialog without all four is a keyboard trap or a leak:
 *
 *  1. Focus moves into the dialog when it opens and RETURNS to whatever opened
 *     it when it closes.
 *  2. Tab cycles inside it. Tabbing out of a modal and driving the page behind
 *     is how a screen-reader user sends the wrong message.
 *  3. Escape closes it, and so does a click on the scrim.
 *  4. `aria-modal` and a labelled heading, so it is announced as a dialog and
 *     not as a pile of buttons.
 */

import { useEffect, useId, useRef } from 'react'
import type { ReactNode } from 'react'
import { X } from 'lucide-react'

interface DialogProps {
  open: boolean
  title: string
  description?: string
  onClose: () => void
  children: ReactNode
  footer?: ReactNode
}

const FOCUSABLE =
  'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'

export function Dialog({ open, title, description, onClose, children, footer }: DialogProps) {
  const panelRef = useRef<HTMLDivElement>(null)
  const returnFocusTo = useRef<HTMLElement | null>(null)
  const titleId = useId()
  const descriptionId = useId()

  useEffect(() => {
    if (!open) return

    returnFocusTo.current = document.activeElement instanceof HTMLElement ? document.activeElement : null

    const panel = panelRef.current
    const first = panel?.querySelector<HTMLElement>(FOCUSABLE)
    ;(first ?? panel)?.focus()

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.stopPropagation()
        onClose()
        return
      }
      if (event.key !== 'Tab' || !panel) return

      const focusable = Array.from(panel.querySelectorAll<HTMLElement>(FOCUSABLE)).filter(
        (element) => element.offsetParent !== null,
      )
      if (focusable.length === 0) {
        event.preventDefault()
        return
      }

      const firstElement = focusable[0]
      const lastElement = focusable[focusable.length - 1]

      if (event.shiftKey && document.activeElement === firstElement) {
        event.preventDefault()
        lastElement.focus()
      } else if (!event.shiftKey && document.activeElement === lastElement) {
        event.preventDefault()
        firstElement.focus()
      }
    }

    document.addEventListener('keydown', onKeyDown, true)
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'

    return () => {
      document.removeEventListener('keydown', onKeyDown, true)
      document.body.style.overflow = previousOverflow
      // Back to the button that opened it, so the keyboard user does not land
      // at the top of the document.
      returnFocusTo.current?.focus()
    }
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="dialog">
      <div className="scrim" onClick={onClose} aria-hidden />
      <div
        className="dialog__panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={description ? descriptionId : undefined}
        ref={panelRef}
        tabIndex={-1}
      >
        <div className="dialog__header">
          <div>
            <h2 id={titleId}>{title}</h2>
            {description ? (
              <p id={descriptionId} className="muted">
                {description}
              </p>
            ) : null}
          </div>
          <button type="button" className="icon-button" onClick={onClose} aria-label="Close">
            <X size={18} aria-hidden />
          </button>
        </div>

        {children}

        {footer ? <div className="dialog__footer">{footer}</div> : null}
      </div>
    </div>
  )
}
