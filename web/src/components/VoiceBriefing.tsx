import { useEffect, useRef, useState } from 'react'
import { Pause, Play, Square } from 'lucide-react'

/**
 * Listening to the briefing.
 *
 * The honest state first: no AICOUNTLY voice service is wired into this
 * deployment, so `available` arrives false from the backend and the control
 * says so rather than pretending. Where the browser itself can speak, that is
 * offered as what it is — the device's own voice, not a product feature — so
 * the briefing can still be listened to.
 *
 * Rules, whichever path is used:
 *   - Never autoplay. Playback starts on a press, always.
 *   - The transcript is on screen the whole time; audio is an alternative to
 *     reading, not a replacement for it.
 *   - No microphone is opened. This reads out; it does not listen.
 */
interface VoiceBriefingProps {
  text: string
  available: boolean
  unavailableReason: string | null
}

type Playback = 'idle' | 'playing' | 'paused'

export function VoiceBriefing({ text, available, unavailableReason }: VoiceBriefingProps) {
  const [playback, setPlayback] = useState<Playback>('idle')
  const utteranceRef = useRef<SpeechSynthesisUtterance | null>(null)

  const deviceSpeech = typeof window !== 'undefined' && 'speechSynthesis' in window

  useEffect(() => {
    return () => {
      if (deviceSpeech) window.speechSynthesis.cancel()
    }
  }, [deviceSpeech])

  if (!available && !deviceSpeech) {
    return (
      <div className="banner banner--info" role="status">
        <span>
          {unavailableReason ??
            'No voice service is configured for this deployment, so the briefing can be read but not played.'}
        </span>
      </div>
    )
  }

  const start = () => {
    if (!deviceSpeech || text.trim() === '') return

    window.speechSynthesis.cancel()
    const utterance = new SpeechSynthesisUtterance(text)
    utterance.rate = 1
    utterance.onend = () => setPlayback('idle')
    utterance.onerror = () => setPlayback('idle')
    utteranceRef.current = utterance
    window.speechSynthesis.speak(utterance)
    setPlayback('playing')
  }

  const pause = () => {
    if (!deviceSpeech) return
    window.speechSynthesis.pause()
    setPlayback('paused')
  }

  const resume = () => {
    if (!deviceSpeech) return
    window.speechSynthesis.resume()
    setPlayback('playing')
  }

  const stop = () => {
    if (!deviceSpeech) return
    window.speechSynthesis.cancel()
    setPlayback('idle')
  }

  return (
    <div style={{ display: 'grid', gap: 8 }}>
      <div className="chip-row">
        {playback === 'playing' ? (
          <button type="button" className="button button--outline" onClick={pause}>
            <Pause size={15} aria-hidden /> Pause
          </button>
        ) : (
          <button type="button" className="button button--outline" onClick={playback === 'paused' ? resume : start}>
            <Play size={15} aria-hidden /> {playback === 'paused' ? 'Resume' : 'Listen to my briefing'}
          </button>
        )}

        {playback !== 'idle' ? (
          <button type="button" className="button button--quiet" onClick={stop}>
            <Square size={15} aria-hidden /> Stop
          </button>
        ) : null}
      </div>

      {!available ? (
        <p className="source-note">
          Played by your device, not by an AICOUNTLY voice service.{' '}
          {unavailableReason ?? 'No voice service is configured for this deployment.'}
        </p>
      ) : null}

      {/* The transcript is the briefing. Audio is the alternative. */}
      <details>
        <summary className="muted" style={{ cursor: 'pointer', fontSize: 12 }}>
          Transcript
        </summary>
        <p className="muted" style={{ marginTop: 6, whiteSpace: 'pre-wrap' }}>
          {text || 'Nothing to read out yet.'}
        </p>
      </details>
    </div>
  )
}
