<?php

declare(strict_types=1);

namespace App\Legacy;

/** Thrown from F3's ONREROUTE hook; LegacyBridge answers with a redirect. */
final class LegacyRedirect extends \RuntimeException
{
    public function __construct(public readonly string $url, public readonly bool $permanent)
    {
        parent::__construct('Redirect to ' . $url);
    }
}
