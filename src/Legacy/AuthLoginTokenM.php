<?php

declare(strict_types=1);

namespace App\Legacy;

use Base;

class AuthLoginTokenM extends \DB\SQL\Mapper
{
    public function __construct(Base $fat)
    {
        parent::__construct($fat->get('dbPDO'), 'auth_login_tokens');
    }
}