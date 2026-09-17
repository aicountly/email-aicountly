-- ---------------------------------------------------------------------------
-- Aicountly Email — Pulse: summaries, commitments, actions, integration state.
--
-- Everything here is DERIVED FROM EMAIL, which Email owns. What is deliberately
-- absent is any place to put a record fetched from another product:
--
--   * a comparison stores the two figures it compared and the SOURCE IDS, and
--     is re-fetched when it is reopened, because a stored purchase order is a
--     stale purchase order;
--   * a commitment stores a quotation from the email and a reference, never a
--     calendar event — if it becomes an event, Calendar creates it and Email
--     keeps its uuid;
--   * an action record stores the operation, the approval and the result id.
--
-- Cross-product payloads are never persisted, and the AI summaries below must
-- not embed them either: see EMAIL_DATA_OWNERSHIP.md.
-- ---------------------------------------------------------------------------

-- What Pulse concluded about one thread. Regenerated, never trusted as a
-- record of anything but "this is what the model said, from these sources".
CREATE TABLE IF NOT EXISTS email_thread_insights (
    insight_id     BIGSERIAL   PRIMARY KEY,
    mailbox_id     BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    thread_key     TEXT        NOT NULL,
    classification TEXT        NOT NULL DEFAULT 'none'
                               CHECK (classification IN ('none','approval_requested','response_required',
                                                         'price_discrepancy','delivery_change',
                                                         'payment_follow_up','meeting_request')),
    -- Why it was prioritised, in words, plus the message it came from. A
    -- priority with no reason is a priority nobody can argue with.
    reason         TEXT        NOT NULL DEFAULT '',
    summary        TEXT        NOT NULL DEFAULT '',
    -- Message ids and uids only. Never a fetched business payload.
    sources        JSONB       NOT NULL DEFAULT '[]'::jsonb,
    deadline_at    TIMESTAMP,
    deadline_source TEXT,
    generator      TEXT        NOT NULL DEFAULT 'rules'
                               CHECK (generator IN ('rules', 'model')),
    model_name     TEXT,
    -- A user can disagree, and the disagreement is kept: it is the only signal
    -- that the classifier is wrong about this kind of thread.
    corrected_to   TEXT,
    corrected_by   TEXT,
    dismissed_at   TIMESTAMP,
    created_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, thread_key)
);

-- A candidate promise found in a thread.
--
-- `state` is the whole point. An incoming supplier proposal is a PROPOSAL and
-- stays one until a person confirms it — there is no code path from
-- 'supplier_proposal' to 'confirmed' that is not a user pressing a button.
CREATE TABLE IF NOT EXISTS email_commitments (
    commitment_id  BIGSERIAL   PRIMARY KEY,
    mailbox_id     BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    thread_key     TEXT        NOT NULL,
    source_uid     TEXT        NOT NULL DEFAULT '',
    source_quote   TEXT        NOT NULL DEFAULT '',
    promised_by    TEXT        NOT NULL DEFAULT '',
    promised_to    TEXT        NOT NULL DEFAULT '',
    what           TEXT        NOT NULL DEFAULT '',
    proposed_date  DATE,
    proposed_time  TIME,
    timezone       TEXT        NOT NULL DEFAULT 'Asia/Kolkata',
    direction      TEXT        NOT NULL DEFAULT 'incoming'
                               CHECK (direction IN ('incoming', 'outgoing')),
    state          TEXT        NOT NULL DEFAULT 'supplier_proposal'
                               CHECK (state IN ('supplier_proposal','inferred','user_confirmed',
                                                'completed','disputed','cancelled')),
    confirmed_by   TEXT,
    confirmed_at   TIMESTAMP,
    -- Where it went if it became something in another product. The uuid only.
    external_service TEXT,
    external_ref     TEXT,
    created_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE INDEX IF NOT EXISTS email_commitments_thread_idx ON email_commitments (mailbox_id, thread_key);
CREATE INDEX IF NOT EXISTS email_commitments_due_idx    ON email_commitments (mailbox_id, proposed_date)
    WHERE state IN ('supplier_proposal', 'user_confirmed');

-- Understand -> Preview -> Approve -> Execute -> Receipt, as rows.
--
-- `preview_digest` is a hash of the facts the preview was built from. At
-- execution the facts are re-fetched and re-hashed; a different hash means the
-- world moved after the person approved, and the approval is refused rather
-- than applied to something they did not see.
CREATE TABLE IF NOT EXISTS email_action_requests (
    action_id       BIGSERIAL   PRIMARY KEY,
    mailbox_id      BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    account_id      BIGINT      NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    cmp_id          INTEGER,
    thread_key      TEXT        NOT NULL DEFAULT '',
    target_service  TEXT        NOT NULL,
    operation       TEXT        NOT NULL,
    -- What will be sent, as the preview showed it. Composed by Email from the
    -- email itself and from ids; it is not a copy of another product's record.
    proposal        JSONB       NOT NULL DEFAULT '{}'::jsonb,
    preview_digest  TEXT        NOT NULL DEFAULT '',
    reversible      BOOLEAN     NOT NULL DEFAULT FALSE,
    required_permissions JSONB  NOT NULL DEFAULT '[]'::jsonb,
    status          TEXT        NOT NULL DEFAULT 'previewed'
                                CHECK (status IN ('previewed','approved','executing','succeeded',
                                                  'failed','partial','uncertain','stale','cancelled')),
    idempotency_key TEXT        NOT NULL,
    approved_by     TEXT,
    approved_at     TIMESTAMP,
    executed_at     TIMESTAMP,
    -- The id the other product handed back, and nothing else from its response.
    external_ref    JSONB       NOT NULL DEFAULT '{}'::jsonb,
    last_error      TEXT,
    correlation_id  TEXT        NOT NULL DEFAULT '',
    created_at      TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at      TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, idempotency_key)
);

CREATE INDEX IF NOT EXISTS email_action_requests_thread_idx ON email_action_requests (mailbox_id, thread_key);

-- The last thing each integration said, so the registry can show a state
-- instead of a spinner. STATUS ONLY: a reachability verdict, a code and a
-- timestamp. No response body is stored here, ever.
CREATE TABLE IF NOT EXISTS email_integration_health (
    service        TEXT        PRIMARY KEY,
    state          TEXT        NOT NULL DEFAULT 'not_configured'
                               CHECK (state IN ('connected','not_connected','not_configured',
                                                'permission_required','temporarily_unavailable','unsupported')),
    last_status    INTEGER,
    last_checked_at TIMESTAMP,
    detail         TEXT
);
