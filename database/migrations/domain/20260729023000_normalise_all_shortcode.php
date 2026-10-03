<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Correct the system list shortcode and permit 3-6 character list codes.
 *
 * Existing list relationships use l_id and therefore remain intact when the
 * system shortcode changes from ALL00 to ALL.
 */
final class NormaliseAllShortcode extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'pgsql') {
            throw new RuntimeException('NormaliseAllShortcode requires PostgreSQL.');
        }

        $this->execute(<<<'SQL'
-- The system-list protection trigger intentionally prevents ordinary updates.
-- Remove it only for this migration and recreate it immediately afterwards.
DROP TRIGGER IF EXISTS trg_lists_protect_system ON lists;

ALTER TABLE lists
    DROP CONSTRAINT IF EXISTS ck_lists_shortcode;

ALTER TABLE lists
    ADD CONSTRAINT ck_lists_shortcode
    CHECK (l_shortcode ~ '^[A-Z0-9]{3,6}$');

UPDATE lists
SET l_shortcode = 'ALL'
WHERE l_shortcode = 'ALL00';

UPDATE queue
SET q_list_shortcode = 'ALL'
WHERE q_list_shortcode = 'ALL00';

UPDATE sendlog
SET sl_list_shortcode = 'ALL'
WHERE sl_list_shortcode = 'ALL00';

UPDATE smlog
SET sml_list_shortcode = 'ALL'
WHERE sml_list_shortcode = 'ALL00';

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

CREATE TRIGGER trg_lists_protect_system
BEFORE UPDATE OR DELETE ON lists
FOR EACH ROW EXECUTE FUNCTION ctnlist_protect_system_list();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'NormaliseAllShortcode is intentionally irreversible.'
        );
    }
}
