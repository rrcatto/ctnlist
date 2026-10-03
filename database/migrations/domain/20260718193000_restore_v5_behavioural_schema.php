<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Bring an already-created v5.0.1 development schema into line with the
 * restored v5 behavioural contracts.
 *
 * The original clean-build migration has also been corrected for new
 * installations. This forward migration exists because Phinx will not rerun an
 * older migration merely because its source file changed.
 */
final class RestoreV5BehaviouralSchema extends AbstractMigration
{
    public function up(): void
    {
        if ($this->getAdapter()->getAdapterType() !== 'pgsql') {
            throw new RuntimeException('RestoreV5BehaviouralSchema requires PostgreSQL.');
        }

        $this->execute(<<<'SQL'
-- Blank means that no list context was supplied. Never invent ALL00.
ALTER TABLE queue
    ALTER COLUMN q_list_shortcode SET DEFAULT '';

ALTER TABLE sendlog
    ALTER COLUMN sl_list_shortcode SET DEFAULT '';

-- Restore the message/list interaction fields used by the v5 reports and
-- once-only delivery workflow. Existing records receive neutral defaults.
ALTER TABLE smlog
    ADD COLUMN IF NOT EXISTS sml_list_shortcode VARCHAR(6) NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS sml_subscribe INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sml_subscribed_at TIMESTAMP WITHOUT TIME ZONE,
    ADD COLUMN IF NOT EXISTS sml_unsubscribe INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS sml_unsubscribed_at TIMESTAMP WITHOUT TIME ZONE;

-- Message audiences are chosen by the administrator. Remove the database rule
-- that automatically assigned every newly created message to ALL00.
DROP TRIGGER IF EXISTS trg_messages_defaults ON messages;
DROP FUNCTION IF EXISTS ctnlist_message_defaults();
SQL);
    }

    public function down(): void
    {
        // Recreating compulsory ALL assignment or dropping restored audit data
        // would reintroduce the regression and may destroy operational history.
        throw new RuntimeException(
            'RestoreV5BehaviouralSchema is intentionally irreversible.'
        );
    }
}
