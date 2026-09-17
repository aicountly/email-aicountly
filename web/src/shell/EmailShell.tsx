import { useCallback, useEffect, useMemo, useState } from 'react'
import { useAuth } from '../auth/AuthProvider.tsx'
import { ApiError } from '../services/api.ts'
import { emailApi } from '../services/email.ts'
import type {
  ActionPreview,
  Briefing,
  CatalogAction,
  Commitment,
  ComparisonResult,
  Folder,
  IntegrationService,
  Message,
  NavigationItem,
  SendJob,
  ThreadAnalysis,
  ThreadSummary,
} from '../services/types.ts'
import { useApi } from '../hooks/useApi.ts'
import { useIsTablet, usePulseIsDrawer } from '../hooks/useMediaQuery.ts'
import { SHORTCUT_HELP, useKeyboardShortcuts } from '../hooks/useKeyboardShortcuts.ts'
import type { Shortcut } from '../hooks/useKeyboardShortcuts.ts'
import { useSession } from '../state/SessionProvider.tsx'
import { ActionPreviewDialog } from '../components/ActionPreviewDialog.tsx'
import { Composer } from '../components/Composer.tsx'
import type { ComposeMode } from '../components/Composer.tsx'
import { Dialog } from './../components/Dialog.tsx'
import { MessageList } from '../components/MessageList.tsx'
import { ReadingPane } from '../components/ReadingPane.tsx'
import {
  ApiErrorView,
  ListSkeleton,
  MailboxUnavailable,
  NotConfigured,
  StateView,
} from '../components/States.tsx'
import { BriefingBar } from './BriefingBar.tsx'
import { EcosystemRail } from './EcosystemRail.tsx'
import { MailboxNav } from './MailboxNav.tsx'
import { PulsePanel } from './PulsePanel.tsx'
import { TopBar } from './TopBar.tsx'

/**
 * The application.
 *
 * WHAT THIS FILE IS RESPONSIBLE FOR, beyond wiring:
 *
 *  - Which pane is on screen. On a phone there is one, with Back; on a tablet
 *    it is list-or-message; on a desktop all three. That decision lives here so
 *    no component has to know how wide the window is.
 *  - Never showing a stale answer after a company switch. Everything keyed on a
 *    company includes `scopeEpoch`, so switching refetches instead of painting
 *    the previous tenant's data while the new one loads.
 *  - Turning an API failure into the right state view rather than a toast.
 */

type PaneOnMobile = 'list' | 'message'

interface FolderState {
  key: string
  label: string
  folder: string
}

function folderForNavItem(item: NavigationItem, folders: Folder[]): FolderState {
  const byRole = folders.find((folder) => folder.role === item.role)

  return {
    key: item.key,
    label: item.label,
    // A virtual view (Needs decision, Promises, Waiting) reads the inbox and
    // filters it; it is not a folder on the server and must not be asked for.
    folder: byRole?.id ?? 'INBOX',
  }
}

export function EmailShell() {
  const session = useSession()
  const { signOut } = useAuth()
  const isTablet = useIsTablet()
  const pulseIsDrawer = usePulseIsDrawer()

  const mailboxId = session.mailbox?.mailbox_id ?? null

  const [activeKey, setActiveKey] = useState('inbox')
  const [selected, setSelected] = useState<ThreadSummary | null>(null)
  const [pane, setPane] = useState<PaneOnMobile>('list')
  const [filter, setFilter] = useState<'priority' | 'all' | 'people'>('priority')
  const [query, setQuery] = useState('')
  const [submittedQuery, setSubmittedQuery] = useState('')
  const [searchFocusToken, setSearchFocusToken] = useState(0)

  const [navOpen, setNavOpen] = useState(false)
  const [pulseOpen, setPulseOpen] = useState(false)
  const [helpOpen, setHelpOpen] = useState(false)
  const [workspacesOpen, setWorkspacesOpen] = useState(false)

  const [composeOpen, setComposeOpen] = useState(false)
  const [composeMode, setComposeMode] = useState<ComposeMode>('new')
  const [composeInitial, setComposeInitial] = useState<Record<string, unknown>>({})

  const [analysis, setAnalysis] = useState<ThreadAnalysis | null>(null)
  const [analysisLoading, setAnalysisLoading] = useState(false)
  const [comparison, setComparison] = useState<ComparisonResult | null>(null)
  const [comparisonError, setComparisonError] = useState<string | null>(null)

  const [preview, setPreview] = useState<ActionPreview | null>(null)
  const [previewError, setPreviewError] = useState<ApiError | null>(null)
  const [previewBusy, setPreviewBusy] = useState(false)
  const [previewOpen, setPreviewOpen] = useState(false)

  const [cursor, setCursor] = useState<string | null>(null)
  const [olderThreads, setOlderThreads] = useState<ThreadSummary[]>([])
  const [loadingMore, setLoadingMore] = useState(false)
  const [loadRemoteImages, setLoadRemoteImages] = useState(false)
  const [notice, setNotice] = useState<string | null>(null)

  // --- Data --------------------------------------------------------------

  const foldersState = useApi(
    (signal) => emailApi.folders(mailboxId as number, signal),
    [mailboxId, session.scopeEpoch],
    mailboxId !== null,
  )

  const folders = foldersState.data?.data.folders ?? []
  const navItem = session.navigation.find((item) => item.key === activeKey) ?? session.navigation[0]
  const active = navItem ? folderForNavItem(navItem, folders) : { key: 'inbox', label: 'Inbox', folder: 'INBOX' }

  const threadsState = useApi(
    (signal) =>
      emailApi.threads(mailboxId as number, { folder: active.folder, limit: 40 }, signal),
    [mailboxId, active.folder, session.scopeEpoch],
    mailboxId !== null && submittedQuery === '',
  )

  const searchState = useApi(
    (signal) => emailApi.search(mailboxId as number, submittedQuery, active.folder, signal),
    [mailboxId, submittedQuery, active.folder, session.scopeEpoch],
    mailboxId !== null && submittedQuery !== '',
  )

  const briefingState = useApi(
    (signal) => emailApi.briefing(mailboxId as number, signal),
    [mailboxId, session.scopeEpoch],
    mailboxId !== null,
  )

  const integrationsState = useApi(
    (signal) => emailApi.integrations(signal),
    [session.scopeEpoch],
    true,
  )

  const threadState = useApi(
    (signal) =>
      emailApi.thread(mailboxId as number, (selected as ThreadSummary).thread_key || (selected as ThreadSummary).uid, active.folder, signal),
    [mailboxId, selected?.thread_key, selected?.uid, active.folder, loadRemoteImages, session.scopeEpoch],
    mailboxId !== null && selected !== null,
  )

  const threads: ThreadSummary[] = useMemo(() => {
    if (submittedQuery !== '') {
      const results = searchState.data?.data
      return [...(results?.store ?? []), ...(results?.indexed ?? [])] as ThreadSummary[]
    }

    return [...(threadsState.data?.data ?? []), ...olderThreads]
  }, [submittedQuery, searchState.data, threadsState.data, olderThreads])

  useEffect(() => {
    setCursor(threadsState.data?.meta.next_cursor ?? null)
    setOlderThreads([])
  }, [threadsState.data])

  // Everything about the previous company goes when the company changes.
  useEffect(() => {
    setSelected(null)
    setAnalysis(null)
    setComparison(null)
    setComparisonError(null)
    setPreview(null)
    setOlderThreads([])
  }, [session.scopeEpoch])

  const messages: Message[] = threadState.data?.data.messages ?? []

  // --- Thread analysis ------------------------------------------------------

  useEffect(() => {
    if (mailboxId === null || selected === null || !session.can('ai')) {
      setAnalysis(null)
      return
    }

    let cancelled = false
    setAnalysisLoading(true)

    emailApi
      .analyseThread(mailboxId, selected.thread_key || selected.uid, active.folder)
      .then((response) => {
        if (!cancelled) setAnalysis(response.data)
      })
      .catch(() => {
        // Analysis is an enhancement. A failure here greys out one panel; the
        // message itself is already on screen.
        if (!cancelled) setAnalysis(null)
      })
      .finally(() => {
        if (!cancelled) setAnalysisLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [mailboxId, selected, active.folder, session.can])

  // --- Actions ----------------------------------------------------------------

  const openThread = useCallback((thread: ThreadSummary) => {
    setSelected(thread)
    setComparison(null)
    setComparisonError(null)
    setLoadRemoteImages(false)
    setPane('message')
  }, [])

  const runMessageAction = useCallback(
    async (action: string, uid: string) => {
      if (mailboxId === null) return
      try {
        const response = await emailApi.updateMessage(mailboxId, uid, action, active.folder)
        setNotice(response.data.complete ? null : response.data.note)
        threadsState.reload()
        if (['archive', 'trash', 'spam'].includes(action)) {
          setSelected(null)
          setPane('list')
        }
      } catch (error) {
        setNotice(error instanceof ApiError ? error.message : 'That action did not go through.')
      }
    },
    [mailboxId, active.folder, threadsState],
  )

  const startCompose = useCallback((mode: ComposeMode, message?: Message) => {
    setComposeMode(mode)
    setComposeInitial(
      message
        ? {
            to: mode === 'forward' ? [] : (message.reply_to.length > 0 ? message.reply_to : message.from),
            cc: mode === 'reply_all' ? message.cc : [],
            subject: `${mode === 'forward' ? 'Fwd: ' : 'Re: '}${message.subject.replace(/^(re|fwd):\s*/i, '')}`,
            in_reply_to: message.message_id,
            thread_key: message.thread_key,
            body_text:
              mode === 'forward'
                ? `\n\n---------- Forwarded message ----------\nFrom: ${message.from[0]?.address}\nDate: ${message.date}\nSubject: ${message.subject}\n\n${message.text}`
                : '',
          }
        : {},
    )
    setComposeOpen(true)
  }, [])

  const previewAction = useCallback(
    async (action: CatalogAction, proposal: Record<string, unknown> = {}) => {
      if (mailboxId === null) return

      setPreviewOpen(true)
      setPreviewBusy(true)
      setPreviewError(null)

      try {
        const response = await emailApi.previewAction(
          mailboxId,
          action.key,
          selected?.thread_key ?? '',
          proposal,
        )
        setPreview(response.data)
      } catch (error) {
        setPreview(null)
        setPreviewError(error instanceof ApiError ? error : new ApiError(0, 'unknown', 'The preview could not be built.'))
      } finally {
        setPreviewBusy(false)
      }
    },
    [mailboxId, selected],
  )

  const approveAction = useCallback(
    async (target: ActionPreview) => {
      if (mailboxId === null) return

      setPreviewBusy(true)
      setPreviewError(null)

      try {
        const response = await emailApi.approveAction(mailboxId, target.action_id, target.preview_digest)
        setPreview(response.data)
      } catch (error) {
        setPreviewError(error instanceof ApiError ? error : new ApiError(0, 'unknown', 'The action failed.'))
      } finally {
        setPreviewBusy(false)
      }
    },
    [mailboxId],
  )

  const confirmCommitment = useCallback(
    async (commitment: Commitment) => {
      if (mailboxId === null) return
      try {
        await emailApi.setCommitmentState(mailboxId, commitment.commitment_id, 'user_confirmed')
        if (selected) {
          const response = await emailApi.analyseThread(mailboxId, selected.thread_key || selected.uid, active.folder)
          setAnalysis(response.data)
        }
      } catch (error) {
        setNotice(error instanceof ApiError ? error.message : 'That commitment could not be confirmed.')
      }
    },
    [mailboxId, selected, active.folder],
  )

  const loadMore = useCallback(async () => {
    if (mailboxId === null || cursor === null) return

    setLoadingMore(true)
    try {
      const response = await emailApi.threads(mailboxId, { folder: active.folder, cursor, limit: 40 })
      setOlderThreads((current) => [...current, ...response.data])
      setCursor(response.meta.next_cursor)
    } catch (error) {
      setNotice(error instanceof ApiError ? error.message : 'Older messages could not be loaded.')
    } finally {
      setLoadingMore(false)
    }
  }, [mailboxId, cursor, active.folder])

  // --- Keyboard ----------------------------------------------------------------

  const shortcuts: Shortcut[] = useMemo(
    () => [
      { combo: 'c', description: 'Compose', run: () => startCompose('new') },
      { combo: 'mod+k', description: 'Search', run: () => setSearchFocusToken((n) => n + 1) },
      {
        combo: 'j',
        description: 'Next message',
        run: () => {
          const index = threads.findIndex((thread) => thread.uid === selected?.uid)
          const next = threads[index + 1] ?? threads[0]
          if (next) openThread(next)
        },
      },
      {
        combo: 'k',
        description: 'Previous message',
        run: () => {
          const index = threads.findIndex((thread) => thread.uid === selected?.uid)
          const previous = threads[index - 1] ?? threads[threads.length - 1]
          if (previous) openThread(previous)
        },
      },
      { combo: 'e', description: 'Archive', run: () => selected && void runMessageAction('archive', selected.uid) },
      {
        combo: 's',
        description: 'Star',
        run: () => selected && void runMessageAction(selected.flagged ? 'unstar' : 'star', selected.uid),
      },
      { combo: 'u', description: 'Back to the list', run: () => setPane('list') },
      { combo: 'r', description: 'Reply', run: () => messages.length > 0 && startCompose('reply', messages[messages.length - 1]) },
      { combo: 'a', description: 'Reply all', run: () => messages.length > 0 && startCompose('reply_all', messages[messages.length - 1]) },
      { combo: 'f', description: 'Forward', run: () => messages.length > 0 && startCompose('forward', messages[messages.length - 1]) },
      { combo: 'g', description: 'Open Pulse', run: () => setPulseOpen(true) },
      { combo: '?', description: 'Keyboard shortcuts', run: () => setHelpOpen(true) },
      {
        combo: 'escape',
        description: 'Close',
        run: () => {
          setPulseOpen(false)
          setNavOpen(false)
        },
      },
    ],
    [threads, selected, messages, openThread, runMessageAction, startCompose],
  )

  useKeyboardShortcuts(shortcuts, !composeOpen && !previewOpen && !helpOpen)

  // --- Render -------------------------------------------------------------------

  if (session.loading) {
    return (
      <main className="email-app">
        <div className="workspace">
          <ListSkeleton rows={8} />
        </div>
      </main>
    )
  }

  if (session.error) {
    return (
      <main className="email-app">
        <div className="workspace">
          <ApiErrorView error={session.error} onRetry={session.reload} />
        </div>
      </main>
    )
  }

  const capabilities = session.capabilities
  const mailStore = capabilities?.mail.store
  const transport = capabilities?.mail.transport

  if (mailboxId === null) {
    return (
      <main className="email-app">
        <div className="workspace">
          <NotConfigured
            title="No mailbox yet"
            reason={
              mailStore?.configured === false
                ? (mailStore.reason ?? 'No mail store is configured for this deployment.')
                : 'This account has no mailbox on this deployment yet.'
            }
            adminHint={mailStore?.admin_hint ?? null}
          />
        </div>
      </main>
    )
  }

  const services: IntegrationService[] = integrationsState.data?.data.services ?? []
  const actions: CatalogAction[] = integrationsState.data?.data.actions ?? []
  const briefing: Briefing | null = briefingState.data?.data ?? null

  const insights: Record<string, { label: string; tone: 'warning' | 'danger' | 'neutral' }> = {}
  for (const decision of briefing?.decisions ?? []) {
    insights[decision.thread_key] = {
      label: decision.label,
      tone:
        decision.classification === 'price_discrepancy'
          ? 'warning'
          : decision.classification === 'payment_follow_up'
            ? 'danger'
            : 'neutral',
    }
  }

  const listError = submittedQuery === '' ? threadsState.error : searchState.error
  const listLoading = submittedQuery === '' ? threadsState.loading : searchState.loading

  const showList = !isTablet || pane === 'list'
  const showMessage = !isTablet || pane === 'message'

  const pulsePanel = (
    <PulsePanel
      analysis={analysis}
      analysisLoading={analysisLoading}
      comparison={comparison}
      comparisonError={comparisonError}
      services={services}
      actions={actions}
      aiAvailable={session.can('ai')}
      aiReason={session.reasonFor('ai') ?? capabilities?.ai.reason ?? null}
      aiAdminHint={capabilities?.ai.admin_hint ?? null}
      question={query}
      onQuestionChange={setQuery}
      onAsk={() => setSubmittedQuery(query.trim())}
      onConfirmCommitment={(commitment) => void confirmCommitment(commitment)}
      onCreateEvent={(commitment) => {
        const action = actions.find((candidate) => candidate.key === 'calendar.create_event')
        if (!action) return
        void previewAction(action, {
          title: commitment.what,
          starts_at: commitment.proposed_date ? `${commitment.proposed_date}T09:00:00` : '',
          ends_at: commitment.proposed_date ? `${commitment.proposed_date}T09:30:00` : '',
          timezone: commitment.timezone,
          notes: commitment.source_quote,
        })
      }}
      onOpenSource={(uid) => {
        const thread = threads.find((candidate) => candidate.uid === uid)
        if (thread) openThread(thread)
      }}
      onRefreshComparison={() => {
        setComparison(null)
        setComparisonError('Re-reading the business record…')
      }}
      onPreviewAction={(action) => void previewAction(action)}
      onVerifyPayment={() =>
        setNotice(
          'Call the contact on a number you already have — not a number in this email — and confirm the details before paying.',
        )
      }
    />
  )

  return (
    <div className="email-app">
      <a className="skip-link" href="#message-list">
        Skip to the message list
      </a>

      <EcosystemRail />

      <MailboxNav
        items={session.navigation}
        folders={folders}
        activeKey={activeKey}
        mailboxes={session.mailboxes}
        activeMailbox={session.mailbox}
        // The name slot shows the PERSON (their mailbox); the sub-label shows
        // which experience this hostname is drawing. They are different facts
        // and putting the second in both places says nothing.
        displayName={session.mailbox?.address ?? 'Account'}
        workspaceLabel={session.mode === 'business' ? 'Business workspace' : 'Personal workspace'}
        onSelect={(item) => {
          setActiveKey(item.key)
          setSelected(null)
          setPane('list')
          setSubmittedQuery('')
        }}
        onSelectMailbox={session.selectMailbox}
        onCompose={() => startCompose('new')}
      />

      <div className="workspace">
        <TopBar
          mode={session.mode}
          query={query}
          searchFocusToken={searchFocusToken}
          workspaceLabel={session.scope ? `Company ${session.scope.cmp_id}` : null}
          displayName={session.mailbox?.address ?? 'Account'}
          canSwitchWorkspace={session.can('shared_mailboxes')}
          onQueryChange={setQuery}
          onSubmitSearch={() => setSubmittedQuery(query.trim())}
          onOpenNavigation={() => setNavOpen(true)}
          onOpenWorkspaces={() => setWorkspacesOpen(true)}
          onOpenPulse={() => setPulseOpen(true)}
          onSignOut={signOut}
        />

        <BriefingBar
          briefing={briefing}
          loading={briefingState.loading}
          voiceAvailable={capabilities?.features.voice_briefing?.enabled ?? false}
          voiceReason={capabilities?.features.voice_briefing?.reason ?? null}
          onOpenCount={(key) => {
            setActiveKey(key === 'decisions' ? 'decisions' : key === 'commitments_due' ? 'promises' : 'waiting')
            setPane('list')
          }}
        />

        {notice ? (
          <div className="banner banner--warning" role="status" style={{ margin: '0 16px 12px' }}>
            <span>{notice}</span>
            <span className="banner__actions">
              <button type="button" className="text-button" onClick={() => setNotice(null)}>
                Dismiss
              </button>
            </span>
          </div>
        ) : null}

        {transport?.configured === false ? (
          <div className="banner banner--info" role="status" style={{ margin: '0 16px 12px' }}>
            <span>
              Sending is switched off on this deployment. {transport.reason} You can read, search and draft.
            </span>
          </div>
        ) : null}

        <main className="mail-grid" id="message-list">
          {showList ? (
            listError ? (
              <section className="panel">
                {listError.code === 'mailbox_unavailable' ? (
                  <MailboxUnavailable reason={listError.message} onRetry={threadsState.reload} />
                ) : (
                  <ApiErrorView error={listError} onRetry={threadsState.reload} />
                )}
              </section>
            ) : (
              <MessageList
                threads={threads}
                loading={listLoading}
                selectedUid={selected?.uid ?? null}
                folderLabel={active.label}
                filter={filter}
                searchQuery={submittedQuery}
                hasMore={cursor !== null && submittedQuery === ''}
                loadingMore={loadingMore}
                insights={insights}
                onSelect={openThread}
                onFilterChange={setFilter}
                onLoadMore={() => void loadMore()}
                onClearSearch={() => {
                  setQuery('')
                  setSubmittedQuery('')
                }}
                onExplain={() =>
                  setNotice(
                    briefing?.decisions.length
                      ? briefing.decisions.map((decision) => `${decision.label}: ${decision.reason}`).join(' · ')
                      : 'Nothing in this folder has been classified yet.',
                  )
                }
              />
            )
          ) : null}

          {showMessage ? (
            threadState.error ? (
              <article className="panel">
                <ApiErrorView error={threadState.error} onRetry={threadState.reload} />
              </article>
            ) : threadState.loading ? (
              <article className="reading-pane panel" aria-busy="true">
                <ListSkeleton rows={3} />
              </article>
            ) : (
              <ReadingPane
                messages={messages}
                analysis={analysis}
                analysisLoading={analysisLoading}
                mailboxId={mailboxId}
                folder={active.folder}
                showBack={isTablet}
                onBack={() => setPane('list')}
                onAction={(action, uid) => void runMessageAction(action, uid)}
                onReply={startCompose}
                onLoadRemoteImages={() => setLoadRemoteImages(true)}
                onOpenSource={(uid) => {
                  const thread = threads.find((candidate) => candidate.uid === uid)
                  if (thread) openThread(thread)
                }}
              />
            )
          ) : null}

          {!pulseIsDrawer ? pulsePanel : null}
        </main>

        <footer className="demo-note">
          Aicountly Email · Aicountly Interactive Services Private Limited
        </footer>
      </div>

      {/* --- Overlays ------------------------------------------------------- */}

      {navOpen ? (
        <>
          <div className="scrim" onClick={() => setNavOpen(false)} aria-hidden />
          <div className="drawer drawer--left" role="dialog" aria-label="Mailbox navigation">
            <button type="button" className="icon-button drawer__close" aria-label="Close navigation" onClick={() => setNavOpen(false)}>
              ✕
            </button>
            <MailboxNav
              items={session.navigation}
              folders={folders}
              activeKey={activeKey}
              mailboxes={session.mailboxes}
              activeMailbox={session.mailbox}
              displayName={session.mailbox?.address ?? 'Account'}
              workspaceLabel={session.mode === 'business' ? 'Business workspace' : 'Personal workspace'}
              onSelect={(item) => {
                setActiveKey(item.key)
                setSelected(null)
                setPane('list')
                setNavOpen(false)
              }}
              onSelectMailbox={(id) => {
                session.selectMailbox(id)
                setNavOpen(false)
              }}
              onCompose={() => {
                setNavOpen(false)
                startCompose('new')
              }}
            />
          </div>
        </>
      ) : null}

      {pulseIsDrawer && pulseOpen ? (
        <>
          <div className="scrim" onClick={() => setPulseOpen(false)} aria-hidden />
          <div className="drawer" role="dialog" aria-label="Pulse AI">
            <button type="button" className="icon-button drawer__close" aria-label="Close Pulse" onClick={() => setPulseOpen(false)}>
              ✕
            </button>
            {pulsePanel}
          </div>
        </>
      ) : null}

      <Composer
        open={composeOpen}
        mailboxId={mailboxId}
        mode={composeMode}
        initial={composeInitial}
        canSend={transport?.configured === true}
        cannotSendReason={transport?.reason ?? null}
        onClose={() => setComposeOpen(false)}
        onSent={(job: SendJob) => {
          if (job.status === 'accepted') threadsState.reload()
        }}
      />

      <ActionPreviewDialog
        open={previewOpen}
        preview={preview}
        error={previewError}
        busy={previewBusy}
        onApprove={(target) => void approveAction(target)}
        onRefresh={() => {
          const action = actions.find((candidate) => candidate.key === preview?.operation)
          if (action) void previewAction(action, (preview?.proposal as Record<string, unknown>) ?? {})
        }}
        onClose={() => {
          setPreviewOpen(false)
          setPreview(null)
          setPreviewError(null)
        }}
      />

      <Dialog open={helpOpen} title="Keyboard shortcuts" onClose={() => setHelpOpen(false)}>
        <div className="table-scroll">
          <table>
            <caption className="sr-only">Keyboard shortcuts</caption>
            <thead>
              <tr>
                <th scope="col">Key</th>
                <th scope="col">Action</th>
              </tr>
            </thead>
            <tbody>
              {SHORTCUT_HELP.map((shortcut) => (
                <tr key={shortcut.combo}>
                  <th scope="row">
                    <kbd>{shortcut.combo}</kbd>
                  </th>
                  <td>{shortcut.description}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </Dialog>

      <Dialog
        open={workspacesOpen}
        title="Switch company"
        description="Switching clears everything on screen from the current company before the new one loads."
        onClose={() => setWorkspacesOpen(false)}
      >
        <WorkspaceSwitcher
          onChoose={(cmpId, fyId) => {
            session.switchCompany({ cmp_id: cmpId, fy_id: fyId, bo_id: 0 })
            setWorkspacesOpen(false)
          }}
        />
      </Dialog>
    </div>
  )
}

/**
 * The company list, read live from Manage on open.
 *
 * Not cached: a cached list still shows a company after somebody's access to it
 * was removed, and the first they would learn of it is a 403 halfway through
 * their work.
 */
function WorkspaceSwitcher({ onChoose }: { onChoose: (cmpId: number, fyId: number) => void }) {
  const state = useApi((signal) => emailApi.companies(signal), [])

  if (state.loading) return <ListSkeleton rows={3} />
  if (state.error) return <ApiErrorView error={state.error} onRetry={state.reload} />

  const companies = (state.data?.data ?? []) as Array<Record<string, unknown>>
  if (companies.length === 0) {
    return <StateView title="No companies" description="Manage lists no company this account can open." />
  }

  return (
    <ul style={{ listStyle: 'none', margin: 0, padding: 0 }}>
      {companies.map((company, index) => {
        const cmpId = Number(company.cmp_id ?? company.comp_id ?? company.id ?? 0)
        const fyId = Number(company.fy_id ?? company.current_fy_id ?? 0)
        const name = String(company.name ?? company.company_name ?? `Company ${cmpId}`)

        return (
          <li key={`${cmpId}-${index}`}>
            <button type="button" className="context-link" onClick={() => onChoose(cmpId, fyId)} disabled={cmpId === 0}>
              <span>{name}</span>
              <span>Open</span>
            </button>
          </li>
        )
      })}
    </ul>
  )
}
