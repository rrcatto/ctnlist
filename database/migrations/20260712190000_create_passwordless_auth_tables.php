<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreatePasswordlessAuthTables extends AbstractMigration
{
    public function change(): void
    {
        $this->table('auth_login_tokens')
            ->addColumn('email', 'string', ['limit' => 254])
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addColumn('expires_at', 'datetime')
            ->addColumn('used_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('requested_ip', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('user_agent', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['email', 'created_at'])
            ->addIndex(['expires_at'])
            ->create();

        $this->table('auth_sessions')
            ->addColumn('user_id', 'integer')
            ->addColumn('token_hash', 'string', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addColumn('expires_at', 'datetime')
            ->addColumn('last_seen_at', 'datetime')
            ->addColumn('revoked_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('ip_address', 'string', ['limit' => 45, 'null' => true, 'default' => null])
            ->addColumn('user_agent', 'string', ['limit' => 500, 'null' => true, 'default' => null])
            ->addIndex(['token_hash'], ['unique' => true])
            ->addIndex(['user_id'])
            ->addIndex(['expires_at'])
            ->create();
    }
}
