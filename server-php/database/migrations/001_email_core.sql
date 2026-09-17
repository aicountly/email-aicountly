-- ---------------------------------------------------------------------------
-- Aicountly Email — core schema.
--
-- EVERY TABLE HERE IS EMAIL-OWNED. There is no copy of another product's data
-- in this file and there must never be one: no contacts table, no calendar
-- events, no items, no invoices, no balances. Where Email needs one of those it
-- calls the owning product's API on the request that needs it and keeps only an
-- identifier.
--
-- Message bodies and original attachments live in the MAIL STORE, not here.
-- What this schema holds is a reference to each message plus the small amount
-- of derived text the list, the search index and the AI summaries need.
-- ---------------------------------------------------------------------------

-- The Email account behind a portal identity. Entitlements live here, and this
-- is what decides what a user may do — never the hostname they arrived on.
CREATE TABLE IF NOT EXISTS email_accounts (
    account_id          BIGSERIAL   PRIMARY KEY,
    subject_uuid        TEXT        NOT NULL UNIQUE,
    account_type        TEXT        NOT NULL DEFAULT 'personal'
                                    CHECK (account_type IN ('business', 'personal')),
    status              TEXT        NOT NULL DEFAULT 'active'
                                    CHECK (status IN ('active', 'suspended', 'closed')),
    plan_key            TEXT        NOT NULL DEFAULT 'free',
    storage_quota_bytes BIGINT      NOT NULL DEFAULT 2147483648,
    ai_opt_out          BOOLEAN     NOT NULL DEFAULT FALSE,
    remote_images       TEXT        NOT NULL DEFAULT 'ask'
                                    CHECK (remote_images IN ('ask', 'always', 'never')),
    created_at          TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at          TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

-- A custom domain, and the real state of its DNS. Nothing is provisioned until
-- ownership verifies, and Email never writes a DNS record.
CREATE TABLE IF NOT EXISTS email_domains (
    domain_id        BIGSERIAL   PRIMARY KEY,
    account_id       BIGINT      NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    cmp_id           INTEGER,
    domain           TEXT        NOT NULL,
    verification_token TEXT      NOT NULL,
    ownership_state  TEXT        NOT NULL DEFAULT 'pending'
                                 CHECK (ownership_state IN ('pending', 'verified', 'failed')),
    spf_state        TEXT        NOT NULL DEFAULT 'unknown',
    dkim_state       TEXT        NOT NULL DEFAULT 'unknown',
    dmarc_state      TEXT        NOT NULL DEFAULT 'unknown',
    mx_state         TEXT        NOT NULL DEFAULT 'unknown',
    last_checked_at  TIMESTAMP,
    created_at       TIMESTAMP    NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (domain)
);

-- A mailbox. `cmp_id` is set only for a shared business mailbox, and it is a
-- REFERENCE to a company Manage owns — never a copy of one.
CREATE TABLE IF NOT EXISTS email_mailboxes (
    mailbox_id            BIGSERIAL   PRIMARY KEY,
    account_id            BIGINT      NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    domain_id             BIGINT      REFERENCES email_domains(domain_id) ON DELETE SET NULL,
    cmp_id                INTEGER,
    address               TEXT        NOT NULL,
    display_name          TEXT        NOT NULL DEFAULT '',
    kind                  TEXT        NOT NULL DEFAULT 'personal'
                                      CHECK (kind IN ('personal', 'shared')),
    status                TEXT        NOT NULL DEFAULT 'active'
                                      CHECK (status IN ('active', 'suspended', 'deleted')),
    quota_bytes           BIGINT,
    -- Present only where the provider has no master user. AES-256-GCM, key in
    -- the server .env. Never selected into an API response.
    credential_login      TEXT,
    credential_ciphertext TEXT,
    created_at            TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at            TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (address)
);

CREATE INDEX IF NOT EXISTS email_mailboxes_account_idx ON email_mailboxes (account_id);
CREATE INDEX IF NOT EXISTS email_mailboxes_company_idx ON email_mailboxes (cmp_id) WHERE cmp_id IS NOT NULL;

-- Who may open a mailbox, and to do what. THIS TABLE IS THE AUTHORISATION.
CREATE TABLE IF NOT EXISTS email_mailbox_members (
    membership_id BIGSERIAL   PRIMARY KEY,
    mailbox_id    BIGINT    NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    member_uuid   TEXT      NOT NULL,
    role          TEXT      NOT NULL DEFAULT 'member'
                            CHECK (role IN ('owner', 'delegate', 'member')),
    permissions   JSONB     NOT NULL DEFAULT '["read"]'::jsonb,
    granted_by    TEXT      NOT NULL DEFAULT '',
    created_at    TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, member_uuid)
);

CREATE INDEX IF NOT EXISTS email_mailbox_members_uuid_idx ON email_mailbox_members (member_uuid);

-- Labels are Email's, and they map onto folders in the mail store.
CREATE TABLE IF NOT EXISTS email_labels (
    label_id    BIGSERIAL   PRIMARY KEY,
    mailbox_id  BIGINT    NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    name        TEXT      NOT NULL,
    colour      TEXT      NOT NULL DEFAULT 'neutral',
    folder_path TEXT,
    system_role TEXT,
    created_at  TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, name)
);

-- A POINTER to a message in the mail store, plus the derived text the list and
-- the search need. NOT the message: `snippet` is a preview, and there is no
-- body column and no attachment column anywhere in this schema.
CREATE TABLE IF NOT EXISTS email_message_refs (
    ref_id         BIGSERIAL   PRIMARY KEY,
    mailbox_id     BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    folder         TEXT        NOT NULL,
    remote_uid     TEXT        NOT NULL,
    message_id     TEXT        NOT NULL DEFAULT '',
    thread_key     TEXT        NOT NULL DEFAULT '',
    subject        TEXT        NOT NULL DEFAULT '',
    from_address   TEXT        NOT NULL DEFAULT '',
    from_name      TEXT        NOT NULL DEFAULT '',
    snippet        TEXT        NOT NULL DEFAULT '',
    internal_date  TIMESTAMP,
    size_bytes     BIGINT,
    has_attachments BOOLEAN    NOT NULL DEFAULT FALSE,
    indexed_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, folder, remote_uid)
);

CREATE INDEX IF NOT EXISTS email_message_refs_thread_idx ON email_message_refs (mailbox_id, thread_key);
CREATE INDEX IF NOT EXISTS email_message_refs_date_idx   ON email_message_refs (mailbox_id, internal_date DESC);

CREATE TABLE IF NOT EXISTS email_message_labels (
    ref_id   BIGINT NOT NULL REFERENCES email_message_refs(ref_id) ON DELETE CASCADE,
    label_id BIGINT NOT NULL REFERENCES email_labels(label_id) ON DELETE CASCADE,
    PRIMARY KEY (ref_id, label_id)
);

-- Drafts are Email's own content until they are sent. `version` is what makes a
-- draft conflict visible: two tabs editing one draft do not silently overwrite
-- each other.
CREATE TABLE IF NOT EXISTS email_drafts (
    draft_id      BIGSERIAL   PRIMARY KEY,
    mailbox_id    BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    account_id    BIGINT      NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    author_uuid   TEXT        NOT NULL,
    in_reply_to   TEXT        NOT NULL DEFAULT '',
    thread_key    TEXT        NOT NULL DEFAULT '',
    to_addresses  JSONB       NOT NULL DEFAULT '[]'::jsonb,
    cc_addresses  JSONB       NOT NULL DEFAULT '[]'::jsonb,
    bcc_addresses JSONB       NOT NULL DEFAULT '[]'::jsonb,
    subject       TEXT        NOT NULL DEFAULT '',
    body_text     TEXT        NOT NULL DEFAULT '',
    body_html     TEXT        NOT NULL DEFAULT '',
    -- 'user' or 'ai_suggested'. An AI draft is still a draft and is marked as
    -- one until a person sends it.
    origin        TEXT        NOT NULL DEFAULT 'user',
    version       INTEGER     NOT NULL DEFAULT 1,
    created_at    TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at    TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE INDEX IF NOT EXISTS email_drafts_mailbox_idx ON email_drafts (mailbox_id, updated_at DESC);

-- A draft's attachment, before the draft becomes a message. Once it is sent the
-- attachment lives in the sent message in the mail store and this row goes,
-- so there is never a second permanent copy.
CREATE TABLE IF NOT EXISTS email_draft_attachments (
    attachment_id BIGSERIAL   PRIMARY KEY,
    draft_id      BIGINT      NOT NULL REFERENCES email_drafts(draft_id) ON DELETE CASCADE,
    filename      TEXT        NOT NULL,
    mime_type     TEXT        NOT NULL DEFAULT 'application/octet-stream',
    size_bytes    BIGINT      NOT NULL DEFAULT 0,
    content       BYTEA       NOT NULL,
    created_at    TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

-- One attempt to put one message on the wire. `operation_id` is unique per
-- mailbox, which is what makes a repeated submission a replay rather than a
-- second send.
CREATE TABLE IF NOT EXISTS email_send_jobs (
    job_id            BIGSERIAL   PRIMARY KEY,
    mailbox_id        BIGINT      NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    account_id        BIGINT      NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    draft_id          BIGINT,
    operation_id      TEXT        NOT NULL,
    status            TEXT        NOT NULL DEFAULT 'queued'
                                  CHECK (status IN ('queued','submitting','accepted','failed','deferred','uncertain','cancelled')),
    attempts          INTEGER     NOT NULL DEFAULT 0,
    recipient_count   INTEGER     NOT NULL DEFAULT 0,
    -- Stored with the zone the user chose, because "09:00" without one is three
    -- different moments to three different people.
    scheduled_for     TIMESTAMP,
    schedule_timezone TEXT        NOT NULL DEFAULT 'UTC',
    release_after     TIMESTAMP   NOT NULL,
    transport_code    INTEGER,
    queue_id          TEXT,
    last_detail       TEXT,
    last_attempt_at   TIMESTAMP,
    completed_at      TIMESTAMP,
    summary           JSONB       NOT NULL DEFAULT '{}'::jsonb,
    created_at        TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at        TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, operation_id)
);

CREATE INDEX IF NOT EXISTS email_send_jobs_due_idx ON email_send_jobs (status, release_after);

CREATE TABLE IF NOT EXISTS email_blocklist (
    block_id   BIGSERIAL   PRIMARY KEY,
    mailbox_id BIGINT    NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    pattern    TEXT      NOT NULL,
    scope      TEXT      NOT NULL DEFAULT 'sender' CHECK (scope IN ('sender', 'domain')),
    created_by TEXT      NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    UNIQUE (mailbox_id, scope, pattern)
);

CREATE TABLE IF NOT EXISTS email_signatures (
    signature_id BIGSERIAL   PRIMARY KEY,
    mailbox_id   BIGINT    NOT NULL REFERENCES email_mailboxes(mailbox_id) ON DELETE CASCADE,
    name         TEXT      NOT NULL DEFAULT 'Default',
    body_text    TEXT      NOT NULL DEFAULT '',
    body_html    TEXT      NOT NULL DEFAULT '',
    is_default   BOOLEAN   NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc'),
    updated_at   TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE TABLE IF NOT EXISTS email_preferences (
    account_id   BIGINT    PRIMARY KEY REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    timezone     TEXT      NOT NULL DEFAULT 'Asia/Kolkata',
    undo_seconds INTEGER   NOT NULL DEFAULT 10 CHECK (undo_seconds BETWEEN 0 AND 60),
    density      TEXT      NOT NULL DEFAULT 'comfortable',
    preferences  JSONB     NOT NULL DEFAULT '{}'::jsonb,
    updated_at   TIMESTAMP NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE TABLE IF NOT EXISTS email_rate_counters (
    account_id   BIGINT    NOT NULL REFERENCES email_accounts(account_id) ON DELETE CASCADE,
    bucket       TEXT      NOT NULL,
    window_start TIMESTAMP NOT NULL,
    used         INTEGER   NOT NULL DEFAULT 0,
    PRIMARY KEY (account_id, bucket, window_start)
);

-- Ids, outcomes and timestamps. No message content, no fetched business record.
CREATE TABLE IF NOT EXISTS email_audit_log (
    audit_id       BIGSERIAL   PRIMARY KEY,
    account_id     BIGINT,
    cmp_id         INTEGER,
    actor_uuid     TEXT        NOT NULL,
    actor_kind     TEXT        NOT NULL DEFAULT 'user',
    source_app     TEXT        NOT NULL DEFAULT 'email',
    action         TEXT        NOT NULL,
    entity_type    TEXT        NOT NULL,
    entity_id      TEXT,
    outcome        TEXT        NOT NULL DEFAULT 'ok',
    detail         JSONB       NOT NULL DEFAULT '{}'::jsonb,
    correlation_id TEXT        NOT NULL DEFAULT '',
    ip_address     TEXT,
    created_at     TIMESTAMP   NOT NULL DEFAULT (NOW() AT TIME ZONE 'utc')
);

CREATE INDEX IF NOT EXISTS email_audit_log_account_idx ON email_audit_log (account_id, created_at DESC);
CREATE INDEX IF NOT EXISTS email_audit_log_entity_idx  ON email_audit_log (entity_type, entity_id);
