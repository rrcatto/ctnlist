<?php

/**
 * Mapper for one-time passwordless-login tokens.
 *
 * The table is created by Phinx migration, not at runtime.
 */
class AuthLoginTokenM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'auth_login_tokens');
    }
}
