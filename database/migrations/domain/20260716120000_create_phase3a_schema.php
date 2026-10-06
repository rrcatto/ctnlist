<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePhase3aSchema extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'pgsql') {
            throw new RuntimeException('CreatePhase3aSchema requires PostgreSQL.');
        }

        $this->execute(<<<'SQL'
CREATE OR REPLACE FUNCTION ctn_uuid_v7()
RETURNS uuid
LANGUAGE plpgsql
VOLATILE
AS $$
DECLARE
    unix_ms BIGINT;
    raw_bytes BYTEA;
    hex_value TEXT;
BEGIN
    unix_ms := floor(extract(epoch FROM clock_timestamp()) * 1000)::BIGINT;
    raw_bytes := substring(int8send(unix_ms) FROM 3 FOR 6)
        || substring(decode(replace(gen_random_uuid()::TEXT, '-', ''), 'hex') FROM 1 FOR 10);

    raw_bytes := set_byte(raw_bytes, 6, (get_byte(raw_bytes, 6) & 15) | 112);
    raw_bytes := set_byte(raw_bytes, 8, (get_byte(raw_bytes, 8) & 63) | 128);
    hex_value := encode(raw_bytes, 'hex');

    RETURN (
        substr(hex_value, 1, 8) || '-' ||
        substr(hex_value, 9, 4) || '-' ||
        substr(hex_value, 13, 4) || '-' ||
        substr(hex_value, 17, 4) || '-' ||
        substr(hex_value, 21, 12)
    )::uuid;
END;
$$;

CREATE TABLE archives (
    a_id SERIAL PRIMARY KEY,
    a_subject VARCHAR(200),
    a_html TEXT,
    a_datecreated TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    a_viewed INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE templates (
    t_id SERIAL PRIMARY KEY,
    t_name VARCHAR(100),
    t_html TEXT,
    t_text TEXT
);

CREATE TABLE subscribers (
    s_id BIGSERIAL PRIMARY KEY,
    s_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    s_email VARCHAR(254) NOT NULL,
    s_email_user VARCHAR(64) GENERATED ALWAYS AS (split_part(s_email, '@', 1)) STORED,
    s_email_domain VARCHAR(253) GENERATED ALWAYS AS (split_part(s_email, '@', 2)) STORED,
    s_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    s_last_login_at TIMESTAMP WITHOUT TIME ZONE,
    s_last_login_ip VARCHAR(45),
    s_last_interacted TIMESTAMP WITHOUT TIME ZONE,
    s_priority INTEGER NOT NULL DEFAULT 0,
    s_bounces INTEGER NOT NULL DEFAULT 0,
    s_emailsleft INTEGER NOT NULL DEFAULT 0,
    s_fname VARCHAR(100),
    s_lname VARCHAR(100),
    s_gender VARCHAR(30),
    s_birthday DATE,
    s_url VARCHAR(253),
    s_phone VARCHAR(30),
    s_business VARCHAR(100),
    s_province VARCHAR(100),
    s_country VARCHAR(100),
    -- Delivery state from catto-mail events: a hard bounce or complaint takes
    -- the subscriber out of campaign selection (consent is separate).
    s_delivery_state VARCHAR(20) NOT NULL DEFAULT 'ok',
    s_delivery_state_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_subscribers_uuid UNIQUE (s_uuid),
    CONSTRAINT ck_subscribers_delivery_state CHECK (s_delivery_state IN ('ok', 'hard_bounced', 'complained')),
    CONSTRAINT ck_subscribers_uuid_v7 CHECK (s_uuid::TEXT ~* '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
);
CREATE UNIQUE INDEX uq_subscribers_email_ci ON subscribers (LOWER(s_email));
CREATE INDEX idx_subscribers_email_domain ON subscribers (s_email_domain);
CREATE INDEX idx_subscribers_last_interacted ON subscribers (s_last_interacted);
CREATE INDEX idx_subscribers_priority ON subscribers (s_priority);

-- A subscriber's profile picture: one square PNG, chosen and cropped on the
-- profile page and re-encoded by the server (App\Subscriber\ProfileImage).
-- Its own table so subscriber lists never carry the bytes; replacing it is an
-- upsert, and it goes with the subscriber.
CREATE TABLE subscriber_images (
    si_s_id BIGINT PRIMARY KEY REFERENCES subscribers (s_id) ON DELETE CASCADE,
    si_mime VARCHAR(20) NOT NULL,
    si_size INTEGER NOT NULL,
    si_bytes INTEGER NOT NULL,
    si_data BYTEA NOT NULL,
    si_updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    CONSTRAINT ck_subscriber_images_png CHECK (si_mime = 'image/png'),
    CONSTRAINT ck_subscriber_images_bounded CHECK (si_size BETWEEN 1 AND 1024 AND si_bytes BETWEEN 1 AND 1048576 AND octet_length(si_data) = si_bytes)
);

CREATE TABLE lists (
    l_id SERIAL PRIMARY KEY,
    l_shortcode VARCHAR(6) NOT NULL,
    l_name VARCHAR(100) NOT NULL,
    l_description TEXT,
    l_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    l_active BOOLEAN NOT NULL DEFAULT TRUE,
    l_system BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT uq_lists_shortcode UNIQUE (l_shortcode),
    CONSTRAINT uq_lists_name UNIQUE (l_name),
    CONSTRAINT ck_lists_shortcode CHECK (l_shortcode ~ '^[A-Z0-9]{3,6}$')
);

CREATE TABLE list_subscribers (
    ls_id BIGSERIAL PRIMARY KEY,
    ls_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    ls_s_id BIGINT NOT NULL REFERENCES subscribers (s_id) ON DELETE CASCADE,
    ls_l_id INTEGER NOT NULL REFERENCES lists (l_id) ON DELETE CASCADE,
    ls_subscribed_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ls_confirmed_at TIMESTAMP WITHOUT TIME ZONE,
    ls_unsubscribed_at TIMESTAMP WITHOUT TIME ZONE,
    ls_unsubscribed BOOLEAN NOT NULL DEFAULT FALSE,
    ls_confirmed BOOLEAN NOT NULL DEFAULT FALSE,
    ls_unsubscribe_reason VARCHAR(255),
    CONSTRAINT uq_list_subscribers UNIQUE (ls_s_id, ls_l_id),
    CONSTRAINT uq_list_subscribers_uuid UNIQUE (ls_uuid),
    CONSTRAINT ck_list_subscribers_uuid_v7 CHECK (ls_uuid::TEXT ~* '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
);
CREATE INDEX idx_list_subscribers_list_state
    ON list_subscribers (ls_l_id, ls_confirmed, ls_unsubscribed);
CREATE INDEX idx_list_subscribers_subscriber ON list_subscribers (ls_s_id);


CREATE TABLE list_subscription_events (
    lse_id BIGSERIAL PRIMARY KEY,
    lse_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    lse_ls_id BIGINT NOT NULL REFERENCES list_subscribers (ls_id) ON DELETE RESTRICT,
    lse_event VARCHAR(30) NOT NULL,
    lse_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lse_source VARCHAR(30) NOT NULL DEFAULT 'database',
    lse_actor_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL,
    lse_ip VARCHAR(45),
    lse_user_agent VARCHAR(500),
    CONSTRAINT uq_list_subscription_events_uuid UNIQUE (lse_uuid),
    CONSTRAINT ck_list_subscription_events_uuid_v7 CHECK (lse_uuid::TEXT ~* '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$'),
    CONSTRAINT ck_list_subscription_events_event CHECK (
        lse_event IN ('joined', 'confirmed', 'reconfirmed', 'unsubscribed')
    )
);
CREATE INDEX idx_list_subscription_events_membership
    ON list_subscription_events (lse_ls_id, lse_created_at);
CREATE INDEX idx_list_subscription_events_uuid ON list_subscription_events (lse_uuid);

CREATE TABLE messages (
    m_id BIGSERIAL PRIMARY KEY,
    m_uniqid VARCHAR(32) NOT NULL,
    m_t_id INTEGER NOT NULL DEFAULT 0,
    m_from_name VARCHAR(100),
    m_from_address VARCHAR(254),
    m_subject VARCHAR(200),
    m_priority INTEGER NOT NULL DEFAULT 0,
    m_html TEXT,
    m_text TEXT,
    m_datesent TIMESTAMP WITHOUT TIME ZONE,
    m_queued INTEGER NOT NULL DEFAULT 0,
    m_sent INTEGER NOT NULL DEFAULT 0,
    m_max_send INTEGER NOT NULL DEFAULT 0,
    m_reads INTEGER NOT NULL DEFAULT 0,
    m_last_read TIMESTAMP WITHOUT TIME ZONE,
    m_likes INTEGER NOT NULL DEFAULT 0,
    m_last_like TIMESTAMP WITHOUT TIME ZONE,
    m_dislikes INTEGER NOT NULL DEFAULT 0,
    m_last_dislike TIMESTAMP WITHOUT TIME ZONE,
    m_bounces INTEGER NOT NULL DEFAULT 0,
    m_a_id INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT uq_messages_uniqid UNIQUE (m_uniqid),
    CONSTRAINT ck_messages_uniqid CHECK (m_uniqid ~ '^[0-9a-f]{32}$')
);
CREATE INDEX idx_messages_archive_id ON messages (m_a_id);

CREATE TABLE message_lists (
    ml_id BIGSERIAL PRIMARY KEY,
    ml_m_id BIGINT NOT NULL REFERENCES messages (m_id) ON DELETE CASCADE,
    ml_l_id INTEGER NOT NULL REFERENCES lists (l_id) ON DELETE RESTRICT,
    CONSTRAINT uq_message_lists UNIQUE (ml_m_id, ml_l_id)
);
CREATE INDEX idx_message_lists_list ON message_lists (ml_l_id);

CREATE TABLE queue (
    q_id BIGSERIAL PRIMARY KEY,
    q_muid VARCHAR(32) NOT NULL,
    q_subject VARCHAR(200),
    q_s_uuid UUID NOT NULL,
    q_email VARCHAR(254) NOT NULL,
    q_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    q_date_added TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    q_last_interacted TIMESTAMP WITHOUT TIME ZONE,
    q_mpriority INTEGER NOT NULL DEFAULT 0,
    q_spriority INTEGER NOT NULL DEFAULT 0,
    CONSTRAINT uq_queue_message_subscriber UNIQUE (q_muid, q_s_uuid)
);
CREATE INDEX idx_queue_default
    ON queue (q_mpriority DESC, q_last_interacted DESC NULLS LAST, q_spriority DESC, q_id ASC);

CREATE TABLE sendlog (
    sl_id BIGSERIAL PRIMARY KEY,
    sl_muid VARCHAR(32) NOT NULL DEFAULT '',
    sl_datesent TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sl_type VARCHAR(30) NOT NULL,
    sl_email VARCHAR(254) NOT NULL DEFAULT '',
    sl_s_uuid UUID,
    sl_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    sl_subject VARCHAR(200) NOT NULL DEFAULT ''
);
CREATE INDEX idx_sendlog_type ON sendlog (sl_type);
CREATE INDEX idx_sendlog_email ON sendlog (LOWER(sl_email));
CREATE INDEX idx_sendlog_datesent ON sendlog (sl_datesent);

CREATE TABLE sitelog (
    stl_id BIGSERIAL PRIMARY KEY,
    stl_email VARCHAR(254),
    stl_url VARCHAR(253),
    stl_ip VARCHAR(45),
    stl_host VARCHAR(253),
    stl_xfwdfor VARCHAR(45),
    stl_agent VARCHAR(500),
    stl_logged_in BOOLEAN NOT NULL DEFAULT FALSE,
    stl_logged_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_sitelog_logged_at ON sitelog (stl_logged_at);

CREATE TABLE smlog (
    sml_id BIGSERIAL PRIMARY KEY,
    sml_s_uuid UUID NOT NULL,
    sml_email VARCHAR(254) NOT NULL,
    sml_muid VARCHAR(32) NOT NULL,
    sml_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    sml_date_added TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sml_date_sent TIMESTAMP WITHOUT TIME ZONE,
    sml_reads INTEGER NOT NULL DEFAULT 0,
    sml_last_read TIMESTAMP WITHOUT TIME ZONE,
    sml_likes INTEGER NOT NULL DEFAULT 0,
    sml_last_like TIMESTAMP WITHOUT TIME ZONE,
    sml_dislikes INTEGER NOT NULL DEFAULT 0,
    sml_last_dislike TIMESTAMP WITHOUT TIME ZONE,
    sml_updates INTEGER NOT NULL DEFAULT 0,
    sml_last_update TIMESTAMP WITHOUT TIME ZONE,
    sml_confirms INTEGER NOT NULL DEFAULT 0,
    sml_confirmed_at TIMESTAMP WITHOUT TIME ZONE,
    sml_forwards INTEGER NOT NULL DEFAULT 0,
    sml_last_forwarded TIMESTAMP WITHOUT TIME ZONE,
    sml_bookings INTEGER NOT NULL DEFAULT 0,
    sml_last_booking TIMESTAMP WITHOUT TIME ZONE,
    sml_subscribe INTEGER NOT NULL DEFAULT 0,
    sml_subscribed_at TIMESTAMP WITHOUT TIME ZONE,
    sml_unsubscribe INTEGER NOT NULL DEFAULT 0,
    sml_unsubscribed_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_smlog_subscriber_message UNIQUE (sml_s_uuid, sml_muid)
);
CREATE INDEX idx_smlog_message ON smlog (sml_muid);
CREATE INDEX idx_smlog_subscriber ON smlog (sml_s_uuid);

CREATE TABLE roles (
    r_id SERIAL PRIMARY KEY,
    r_key VARCHAR(64) NOT NULL UNIQUE,
    r_name VARCHAR(100) NOT NULL UNIQUE,
    r_description TEXT,
    r_system BOOLEAN NOT NULL DEFAULT FALSE,
    r_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    r_updated_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE subscriber_roles (
    sr_id BIGSERIAL PRIMARY KEY,
    sr_s_id BIGINT NOT NULL REFERENCES subscribers (s_id) ON DELETE CASCADE,
    sr_r_id INTEGER NOT NULL REFERENCES roles (r_id) ON DELETE RESTRICT,
    sr_assigned_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sr_assigned_by_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL,
    CONSTRAINT uq_subscriber_roles UNIQUE (sr_s_id, sr_r_id)
);

CREATE TABLE acl_permissions (
    ap_id SERIAL PRIMARY KEY,
    ap_key VARCHAR(100) NOT NULL UNIQUE,
    ap_name VARCHAR(150) NOT NULL,
    ap_description TEXT,
    ap_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE role_permissions (
    rp_id BIGSERIAL PRIMARY KEY,
    rp_r_id INTEGER NOT NULL REFERENCES roles (r_id) ON DELETE CASCADE,
    rp_ap_id INTEGER NOT NULL REFERENCES acl_permissions (ap_id) ON DELETE CASCADE,
    rp_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_role_permissions UNIQUE (rp_r_id, rp_ap_id)
);

CREATE TABLE message_forwards (
    mf_id BIGSERIAL PRIMARY KEY,
    mf_m_id BIGINT NOT NULL REFERENCES messages (m_id) ON DELETE CASCADE,
    mf_sender_s_id BIGINT NOT NULL REFERENCES subscribers (s_id) ON DELETE CASCADE,
    mf_recipient_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL,
    mf_recipient_email VARCHAR(254) NOT NULL,
    mf_forwarded_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX idx_message_forwards_sender_message ON message_forwards (mf_sender_s_id, mf_m_id);
CREATE INDEX idx_message_forwards_recipient_email ON message_forwards (LOWER(mf_recipient_email));

CREATE TABLE auth_login_tokens (
    alt_id BIGSERIAL PRIMARY KEY,
    alt_s_id BIGINT NOT NULL REFERENCES subscribers (s_id) ON DELETE CASCADE,
    alt_email VARCHAR(254) NOT NULL,
    alt_token_hash CHAR(64) NOT NULL UNIQUE,
    alt_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    alt_expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    alt_used_at TIMESTAMP WITHOUT TIME ZONE,
    alt_requested_ip VARCHAR(45),
    alt_user_agent VARCHAR(500),
    alt_return_action VARCHAR(30) NOT NULL DEFAULT 'profile',
    alt_return_m_id BIGINT REFERENCES messages (m_id) ON DELETE SET NULL,
    alt_return_l_id INTEGER REFERENCES lists (l_id) ON DELETE SET NULL
);
CREATE INDEX idx_auth_login_tokens_subscriber ON auth_login_tokens (alt_s_id);
CREATE INDEX idx_auth_login_tokens_expiry ON auth_login_tokens (alt_expires_at);
CREATE INDEX idx_auth_login_tokens_rate_email ON auth_login_tokens (alt_email, alt_created_at);
CREATE INDEX idx_auth_login_tokens_rate_ip ON auth_login_tokens (alt_requested_ip, alt_created_at);

CREATE TABLE auth_sessions (
    as_id BIGSERIAL PRIMARY KEY,
    as_s_id BIGINT NOT NULL REFERENCES subscribers (s_id) ON DELETE CASCADE,
    as_token_hash CHAR(64) NOT NULL UNIQUE,
    as_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    as_expires_at TIMESTAMP WITHOUT TIME ZONE NOT NULL,
    as_last_seen_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    as_revoked_at TIMESTAMP WITHOUT TIME ZONE,
    as_ip_address VARCHAR(45),
    as_user_agent VARCHAR(500)
);
CREATE INDEX idx_auth_sessions_subscriber ON auth_sessions (as_s_id);
CREATE INDEX idx_auth_sessions_expiry ON auth_sessions (as_expires_at);

-- Application state (queue flags) and administrator overrides of selected
-- .env settings (keys 'setting:<ENV_NAME>'); secret values are encrypted.
CREATE TABLE options (
    o_id SERIAL PRIMARY KEY,
    o_key VARCHAR(100) NOT NULL UNIQUE,
    o_value TEXT,
    o_secret BOOLEAN NOT NULL DEFAULT FALSE,
    o_updated_at TIMESTAMP WITHOUT TIME ZONE,
    o_updated_by_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL
);

CREATE TABLE sessions (
    ses_id VARCHAR(255) PRIMARY KEY,
    ses_data TEXT NOT NULL DEFAULT '',
    ses_ip VARCHAR(45),
    ses_agent VARCHAR(500),
    ses_stamp INTEGER NOT NULL
);
CREATE INDEX idx_sessions_stamp ON sessions (ses_stamp);

-- catto-mail integration (ctnlist side only; catto-mail's own database is
-- never accessed). Remote ids are catto-mail's UUIDs; every external
-- reference sent to catto-mail is a ctnlist UUID from these tables (or a
-- subscriber UUID), and every Idempotency-Key is stored before its request.

-- Address validation jobs (POST /v1/validation-jobs), at most 10,000 addresses each.
CREATE TABLE cattomail_validation_jobs (
    cvj_id BIGSERIAL PRIMARY KEY,
    cvj_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    cvj_idempotency_key VARCHAR(64) NOT NULL,
    cvj_remote_id UUID,
    cvj_scope VARCHAR(200) NOT NULL DEFAULT '',
    cvj_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    cvj_total INTEGER NOT NULL DEFAULT 0,
    cvj_processed INTEGER NOT NULL DEFAULT 0,
    cvj_counts TEXT,
    cvj_results_complete BOOLEAN NOT NULL DEFAULT FALSE,
    cvj_error TEXT,
    cvj_attempts INTEGER NOT NULL DEFAULT 0,
    cvj_created_by_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL,
    cvj_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cvj_updated_at TIMESTAMP WITHOUT TIME ZONE,
    cvj_last_checked_at TIMESTAMP WITHOUT TIME ZONE,
    cvj_completed_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_cvj_uuid UNIQUE (cvj_uuid),
    CONSTRAINT uq_cvj_idempotency_key UNIQUE (cvj_idempotency_key),
    CONSTRAINT uq_cvj_remote_id UNIQUE (cvj_remote_id),
    CONSTRAINT ck_cvj_status CHECK (cvj_status IN ('pending', 'queued', 'processing', 'completed', 'failed', 'cancelled'))
);

-- One submitted address per subscriber and job; external_address_reference is
-- the subscriber UUID. Result columns mirror catto-mail's classification
-- unchanged (a suggestion is stored, never applied).
CREATE TABLE cattomail_validation_addresses (
    cva_id BIGSERIAL PRIMARY KEY,
    cva_cvj_id BIGINT NOT NULL REFERENCES cattomail_validation_jobs (cvj_id) ON DELETE CASCADE,
    cva_s_uuid UUID NOT NULL,
    cva_address VARCHAR(512) NOT NULL,
    cva_remote_id UUID,
    cva_normalized_address VARCHAR(512),
    cva_syntax_status VARCHAR(30),
    cva_domain_status VARCHAR(30),
    cva_smtp_status VARCHAR(30),
    cva_is_role BOOLEAN,
    cva_is_disposable BOOLEAN,
    cva_is_catch_all BOOLEAN,
    cva_is_typo_suspected BOOLEAN,
    cva_suggested_address VARCHAR(512),
    cva_suggestion_reason VARCHAR(60),
    cva_suggestion_confidence VARCHAR(10),
    cva_classification VARCHAR(30),
    cva_confidence VARCHAR(10),
    cva_diagnostic_code VARCHAR(120),
    cva_diagnostic_text TEXT,
    cva_checked_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_cva_job_subscriber UNIQUE (cva_cvj_id, cva_s_uuid)
);
CREATE INDEX idx_cva_subscriber ON cattomail_validation_addresses (cva_s_uuid, cva_id DESC);

-- One ctnlist sending run (a queue run, a proof, a resend, a forward); it may
-- span several catto-mail send jobs.
CREATE TABLE cattomail_runs (
    cr_id BIGSERIAL PRIMARY KEY,
    cr_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    cr_kind VARCHAR(20) NOT NULL,
    cr_muid VARCHAR(32) NOT NULL DEFAULT '',
    cr_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cr_finished_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_cr_uuid UNIQUE (cr_uuid),
    CONSTRAINT ck_cr_kind CHECK (cr_kind IN ('campaign', 'proof', 'resend', 'forward'))
);

-- catto-mail send jobs (POST /v1/send-jobs): one message, one list context,
-- at most 10,000 recipients. csj_request is the exact create body, so a retry
-- with the same Idempotency-Key sends the same body.
CREATE TABLE cattomail_send_jobs (
    csj_id BIGSERIAL PRIMARY KEY,
    csj_cr_id BIGINT NOT NULL REFERENCES cattomail_runs (cr_id) ON DELETE CASCADE,
    csj_seq INTEGER NOT NULL,
    csj_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    csj_idempotency_key VARCHAR(64) NOT NULL,
    csj_remote_id UUID,
    csj_muid VARCHAR(32) NOT NULL DEFAULT '',
    csj_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    csj_message_class VARCHAR(20) NOT NULL,
    csj_request TEXT NOT NULL,
    csj_status VARCHAR(20) NOT NULL DEFAULT 'open',
    csj_total INTEGER NOT NULL DEFAULT 0,
    csj_summary TEXT,
    csj_error TEXT,
    csj_attempts INTEGER NOT NULL DEFAULT 0,
    csj_created_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    csj_submitted_at TIMESTAMP WITHOUT TIME ZONE,
    csj_completed_at TIMESTAMP WITHOUT TIME ZONE,
    csj_last_checked_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_csj_run_seq UNIQUE (csj_cr_id, csj_seq),
    CONSTRAINT uq_csj_uuid UNIQUE (csj_uuid),
    CONSTRAINT uq_csj_idempotency_key UNIQUE (csj_idempotency_key),
    CONSTRAINT uq_csj_remote_id UNIQUE (csj_remote_id),
    CONSTRAINT ck_csj_class CHECK (csj_message_class IN ('transactional', 'subscription')),
    CONSTRAINT ck_csj_status CHECK (csj_status IN ('open', 'ready', 'queued', 'processing', 'dispatched', 'completed', 'failed', 'cancelled'))
);
CREATE INDEX idx_csj_status ON cattomail_send_jobs (csj_status);
CREATE INDEX idx_csj_muid ON cattomail_send_jobs (csj_muid);

-- Recipient batches (POST /v1/send-jobs/{id}/recipients), at most 500 each,
-- with their persisted Idempotency-Key.
CREATE TABLE cattomail_batches (
    cb_id BIGSERIAL PRIMARY KEY,
    cb_csj_id BIGINT NOT NULL REFERENCES cattomail_send_jobs (csj_id) ON DELETE CASCADE,
    cb_seq INTEGER NOT NULL,
    cb_idempotency_key VARCHAR(64) NOT NULL,
    cb_count INTEGER NOT NULL DEFAULT 0,
    cb_status VARCHAR(20) NOT NULL DEFAULT 'open',
    cb_remote_id UUID,
    cb_accepted_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_cb_job_seq UNIQUE (cb_csj_id, cb_seq),
    CONSTRAINT uq_cb_idempotency_key UNIQUE (cb_idempotency_key),
    CONSTRAINT ck_cb_status CHECK (cb_status IN ('open', 'ready', 'accepted'))
);

-- One rendered recipient message; crp_uuid is its external_recipient_reference.
-- The rendered content is kept until its batch is accepted (a retry must
-- resend the identical body) and cleared afterwards.
CREATE TABLE cattomail_recipients (
    crp_id BIGSERIAL PRIMARY KEY,
    crp_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    crp_csj_id BIGINT NOT NULL REFERENCES cattomail_send_jobs (csj_id) ON DELETE CASCADE,
    crp_cb_id BIGINT NOT NULL REFERENCES cattomail_batches (cb_id) ON DELETE CASCADE,
    crp_s_uuid UUID,
    crp_email VARCHAR(320) NOT NULL,
    crp_muid VARCHAR(32) NOT NULL DEFAULT '',
    crp_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    crp_type VARCHAR(30) NOT NULL,
    crp_subject VARCHAR(998) NOT NULL,
    crp_html TEXT,
    crp_text TEXT,
    crp_unsubscribe_url VARCHAR(2048),
    crp_remote_message_id UUID,
    crp_status VARCHAR(20) NOT NULL DEFAULT 'staged',
    crp_resolved_at TIMESTAMP WITHOUT TIME ZONE,
    crp_updated_at TIMESTAMP WITHOUT TIME ZONE,
    crp_effect VARCHAR(20),
    crp_effect_applied_at TIMESTAMP WITHOUT TIME ZONE,
    CONSTRAINT uq_crp_uuid UNIQUE (crp_uuid),
    CONSTRAINT ck_crp_status CHECK (crp_status IN ('staged', 'handed_off', 'not_sent', 'created', 'queued', 'submitted',
        'deferred', 'outcome_unknown', 'remote_accepted', 'soft_bounced', 'hard_bounced', 'complained', 'failed', 'suppressed'))
);
CREATE UNIQUE INDEX uq_crp_job_email ON cattomail_recipients (crp_csj_id, LOWER(crp_email));
-- At most one active campaign delivery of a message to a subscriber (queue
-- runs). A refused job's rows ('not_sent') may be requeued; resends and
-- forwards are separate deliveries by design.
CREATE UNIQUE INDEX uq_crp_campaign_delivery ON cattomail_recipients (crp_muid, crp_s_uuid)
    WHERE crp_type = 'MESSAGE' AND crp_s_uuid IS NOT NULL AND crp_status <> 'not_sent';
CREATE INDEX idx_crp_batch ON cattomail_recipients (crp_cb_id);
CREATE INDEX idx_crp_subscriber ON cattomail_recipients (crp_s_uuid);
CREATE INDEX idx_crp_message ON cattomail_recipients (crp_muid);

-- Signed webhook events received from catto-mail, de-duplicated by event id
-- (delivery is at-least-once) and processed after the acknowledgement.
CREATE TABLE cattomail_webhook_events (
    cwe_id BIGSERIAL PRIMARY KEY,
    cwe_event_id UUID NOT NULL,
    cwe_type VARCHAR(80) NOT NULL,
    cwe_payload TEXT NOT NULL,
    cwe_received_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cwe_processed_at TIMESTAMP WITHOUT TIME ZONE,
    cwe_outcome VARCHAR(200),
    cwe_attempts INTEGER NOT NULL DEFAULT 0,
    cwe_error TEXT,
    CONSTRAINT uq_cwe_event_id UNIQUE (cwe_event_id)
);
CREATE INDEX idx_cwe_unprocessed ON cattomail_webhook_events (cwe_id) WHERE cwe_processed_at IS NULL;

-- Explicit recipient global opt-outs reported to catto-mail
-- (POST /v1/global-suppressions); never created by an ordinary unsubscribe.
CREATE TABLE cattomail_global_optouts (
    cgo_id BIGSERIAL PRIMARY KEY,
    cgo_uuid UUID NOT NULL DEFAULT ctn_uuid_v7(),
    cgo_s_id BIGINT REFERENCES subscribers (s_id) ON DELETE SET NULL,
    cgo_email VARCHAR(320) NOT NULL,
    cgo_idempotency_key VARCHAR(64) NOT NULL,
    cgo_remote_id UUID,
    cgo_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    cgo_requested_at TIMESTAMP WITHOUT TIME ZONE NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cgo_confirmed_at TIMESTAMP WITHOUT TIME ZONE,
    cgo_lift_requested_at TIMESTAMP WITHOUT TIME ZONE,
    cgo_lifted_at TIMESTAMP WITHOUT TIME ZONE,
    cgo_attempts INTEGER NOT NULL DEFAULT 0,
    cgo_error TEXT,
    CONSTRAINT uq_cgo_uuid UNIQUE (cgo_uuid),
    CONSTRAINT uq_cgo_idempotency_key UNIQUE (cgo_idempotency_key),
    CONSTRAINT uq_cgo_remote_id UNIQUE (cgo_remote_id),
    CONSTRAINT ck_cgo_status CHECK (cgo_status IN ('pending', 'active', 'lift_pending', 'lifted', 'rejected'))
);
CREATE INDEX idx_cgo_subscriber ON cattomail_global_optouts (cgo_s_id);
-- At most one opt-out in progress or in force per subscriber (a double submit
-- cannot report two); lifted and refused ones are history.
CREATE UNIQUE INDEX uq_cgo_open_per_subscriber ON cattomail_global_optouts (cgo_s_id) WHERE cgo_status IN ('pending', 'active', 'lift_pending');
SQL);

        $this->seedSystemData();
        $this->installTriggers();
    }

    private function seedSystemData(): void
    {
        $permissions = [
            ['profile.view', 'View own profile', 'View the authenticated subscriber profile.'],
            ['profile.edit', 'Edit own profile', 'Edit the authenticated subscriber profile.'],
            ['messages.history', 'View own message history', 'View messages previously sent to the authenticated subscriber.'],
            ['messages.resend', 'Resend own messages', 'Send the authenticated subscriber another copy of a message.'],
            ['messages.forward', 'Forward own messages', 'Forward messages previously sent to the authenticated subscriber.'],
            ['messages.react', 'React to messages', 'Like or dislike messages sent to the authenticated subscriber.'],
            ['lists.view', 'View lists', 'View available lists and own memberships.'],
            ['lists.join', 'Join lists', 'Request membership of a list.'],
            ['lists.confirm', 'Confirm list membership', 'Confirm consent to receive a list.'],
            ['lists.unsubscribe', 'Unsubscribe from lists', 'Withdraw consent for a list.'],
            ['archives.view', 'View archives', 'View public or permitted archives.'],
            ['subscribers.view', 'View subscribers', 'View subscriber records.'],
            ['subscribers.manage', 'Manage subscribers', 'Create and edit subscriber records.'],
            ['lists.manage', 'Manage lists', 'Create and manage non-system lists.'],
            ['messages.manage', 'Manage messages', 'Create and edit messages.'],
            ['messages.queue', 'Queue messages', 'Queue messages for delivery.'],
            ['queue.process', 'Process queue', 'Process or stop the delivery queue.'],
            ['templates.manage', 'Manage templates', 'Create and edit templates.'],
            ['logs.view', 'View logs', 'View send and interaction logs.'],
            ['roles.manage', 'Manage roles', 'Create roles and assign them to subscribers.'],
            ['acl.manage', 'Manage ACL', 'Assign permissions to roles.'],
            ['settings.manage', 'Manage settings', 'Change the site, mail and sign-in settings that override .env.'],
            ['system.admin', 'System administration', 'Full administrative access.'],
        ];

        $this->execute(<<<'SQL'
INSERT INTO lists (l_shortcode, l_name, l_description, l_system)
VALUES ('ALL', 'ALL', 'Immutable system list available as the general mailing audience.', TRUE);

INSERT INTO roles (r_key, r_name, r_description, r_system)
VALUES
    ('administrator', 'Administrator', 'Full system administrator.', TRUE),
    ('subscriber', 'Subscriber', 'Standard authenticated subscriber.', TRUE);
SQL);

        foreach ($permissions as [$key, $name, $description]) {
            $this->execute(
                'INSERT INTO acl_permissions (ap_key, ap_name, ap_description) VALUES (:key, :name, :description)',
                ['key' => $key, 'name' => $name, 'description' => $description]
            );
        }

        $this->execute(<<<'SQL'
INSERT INTO role_permissions (rp_r_id, rp_ap_id)
SELECT r.r_id, ap.ap_id
FROM roles r
CROSS JOIN acl_permissions ap
WHERE r.r_key = 'administrator';

INSERT INTO role_permissions (rp_r_id, rp_ap_id)
SELECT r.r_id, ap.ap_id
FROM roles r
JOIN acl_permissions ap ON ap.ap_key IN (
    'profile.view',
    'profile.edit',
    'messages.history',
    'messages.resend',
    'messages.forward',
    'messages.react',
    'lists.view',
    'lists.join',
    'lists.confirm',
    'lists.unsubscribe',
    'archives.view'
)
WHERE r.r_key = 'subscriber';
SQL);
    }

    private function installTriggers(): void
    {
        $this->execute(<<<'SQL'
CREATE OR REPLACE FUNCTION ctnlist_protect_system_list()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.l_system THEN
        RAISE EXCEPTION 'System lists cannot be changed or deleted.';
    END IF;
    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_lists_protect_system
BEFORE UPDATE OR DELETE ON lists
FOR EACH ROW EXECUTE FUNCTION ctnlist_protect_system_list();

CREATE OR REPLACE FUNCTION ctnlist_subscriber_defaults()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    all_list_id INTEGER;
    subscriber_role_id INTEGER;
BEGIN
    SELECT l_id INTO all_list_id FROM lists WHERE l_shortcode = 'ALL';
    SELECT r_id INTO subscriber_role_id FROM roles WHERE r_key = 'subscriber';

    INSERT INTO list_subscribers (ls_s_id, ls_l_id)
    VALUES (NEW.s_id, all_list_id)
    ON CONFLICT (ls_s_id, ls_l_id) DO NOTHING;

    INSERT INTO subscriber_roles (sr_s_id, sr_r_id)
    VALUES (NEW.s_id, subscriber_role_id)
    ON CONFLICT (sr_s_id, sr_r_id) DO NOTHING;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_subscribers_defaults
AFTER INSERT ON subscribers
FOR EACH ROW EXECUTE FUNCTION ctnlist_subscriber_defaults();


CREATE OR REPLACE FUNCTION ctnlist_protect_subscriber_uuid()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.s_uuid IS DISTINCT FROM OLD.s_uuid THEN
        RAISE EXCEPTION 'Subscriber UUIDv7 values are immutable.';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_subscribers_protect_uuid
BEFORE UPDATE OF s_uuid ON subscribers
FOR EACH ROW EXECUTE FUNCTION ctnlist_protect_subscriber_uuid();

CREATE OR REPLACE FUNCTION ctnlist_protect_membership_uuid()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF NEW.ls_uuid IS DISTINCT FROM OLD.ls_uuid THEN
        RAISE EXCEPTION 'List membership UUIDv7 values are immutable.';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_list_subscribers_protect_uuid
BEFORE UPDATE OF ls_uuid ON list_subscribers
FOR EACH ROW EXECUTE FUNCTION ctnlist_protect_membership_uuid();

CREATE OR REPLACE FUNCTION ctnlist_log_subscription_event()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    event_name VARCHAR(30);
BEGIN
    IF TG_OP = 'INSERT' THEN
        event_name := 'joined';
    ELSIF OLD.ls_unsubscribed = FALSE AND NEW.ls_unsubscribed = TRUE THEN
        event_name := 'unsubscribed';
    ELSIF OLD.ls_confirmed = FALSE AND NEW.ls_confirmed = TRUE THEN
        event_name := CASE WHEN OLD.ls_unsubscribed THEN 'reconfirmed' ELSE 'confirmed' END;
    ELSE
        RETURN NEW;
    END IF;

    INSERT INTO list_subscription_events (lse_ls_id, lse_event)
    VALUES (NEW.ls_id, event_name);

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_list_subscribers_audit
AFTER INSERT OR UPDATE OF ls_confirmed, ls_unsubscribed ON list_subscribers
FOR EACH ROW EXECUTE FUNCTION ctnlist_log_subscription_event();

CREATE OR REPLACE FUNCTION ctnlist_protect_subscription_event()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'List subscription events are append-only.';
END;
$$;

CREATE TRIGGER trg_list_subscription_events_append_only
BEFORE UPDATE OR DELETE ON list_subscription_events
FOR EACH ROW EXECUTE FUNCTION ctnlist_protect_subscription_event();

SQL);
    }

    public function down(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'pgsql') {
            throw new RuntimeException('CreatePhase3aSchema requires PostgreSQL.');
        }

        $this->execute('DROP FUNCTION IF EXISTS ctnlist_protect_subscription_event() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctnlist_log_subscription_event() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctnlist_protect_membership_uuid() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctnlist_protect_subscriber_uuid() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctnlist_subscriber_defaults() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctnlist_protect_system_list() CASCADE');
        $this->execute('DROP FUNCTION IF EXISTS ctn_uuid_v7() CASCADE');

        foreach ([
            'cattomail_global_optouts',
            'cattomail_webhook_events',
            'cattomail_recipients',
            'cattomail_batches',
            'cattomail_send_jobs',
            'cattomail_runs',
            'cattomail_validation_addresses',
            'cattomail_validation_jobs',
            'sessions',
            'auth_sessions',
            'auth_login_tokens',
            'subscriber_images',
            'message_forwards',
            'role_permissions',
            'acl_permissions',
            'subscriber_roles',
            'roles',
            'smlog',
            'sitelog',
            'sendlog',
            'queue',
            'message_lists',
            'messages',
            'list_subscription_events',
            'list_subscribers',
            'lists',
            'subscribers',
            'options',
            'templates',
            'archives',
        ] as $table) {
            $this->execute(sprintf('DROP TABLE IF EXISTS %s CASCADE', $table));
        }
    }
}