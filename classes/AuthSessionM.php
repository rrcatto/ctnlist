<?php

/**
 * Mapper for revocable passwordless-login sessions.
 *
 * The table is created by Phinx migration, not at runtime.
 */
class AuthSessionM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'auth_sessions');
    }
}
