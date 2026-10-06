<?php

declare(strict_types=1);

namespace App\CattoMail;

/** A catto-mail API call failed. Messages never contain the API key or the Authorization header. */
class CattoMailException extends \RuntimeException
{
}
