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

CREATE TABLE options (
    o_id SERIAL PRIMARY KEY,
    o_key VARCHAR(100) NOT NULL UNIQUE,
    o_value VARCHAR(255)
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
    s_photo VARCHAR(253),
    s_business VARCHAR(100),
    s_province VARCHAR(100),
    s_country VARCHAR(100),
    CONSTRAINT uq_subscribers_uuid UNIQUE (s_uuid),
    CONSTRAINT ck_subscribers_uuid_v7 CHECK (s_uuid::TEXT ~* '^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
);
CREATE UNIQUE INDEX uq_subscribers_email_ci ON subscribers (LOWER(s_email));
CREATE INDEX idx_subscribers_email_domain ON subscribers (s_email_domain);
CREATE INDEX idx_subscribers_last_interacted ON subscribers (s_last_interacted);
CREATE INDEX idx_subscribers_priority ON subscribers (s_priority);

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

CREATE TABLE sessions (
    ses_id VARCHAR(255) PRIMARY KEY,
    ses_data TEXT NOT NULL DEFAULT '',
    ses_ip VARCHAR(45),
    ses_agent VARCHAR(500),
    ses_stamp INTEGER NOT NULL
);
CREATE INDEX idx_sessions_stamp ON sessions (ses_stamp);
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
            'sessions',
            'auth_sessions',
            'auth_login_tokens',
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