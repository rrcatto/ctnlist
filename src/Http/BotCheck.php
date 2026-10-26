<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * Whether a submission of a public form that emails a link (sign-in,
 * subscribe) comes from a bot: its hidden trap field (BotTrapType) was filled
 * in, the server in front marked its network, or it names no browser or one
 * long out of use. The controller then shows the usual answer, sends and
 * creates nothing, and flags the request in the Site Log (SiteLog::flag()),
 * so the bot learns nothing and the administrator sees it on Flagged activity.
 */
final class BotCheck
{
    /** The trap field's name on the forms: a name form bots like to fill in. */
    public const TRAP_FIELD = 'website';

    /**
     * Set to 1 by a server in front of ctnlist (nginx: a geo block and
     * proxy_set_header) for networks it distrusts. A visitor who sends it
     * only gets their own submissions refused.
     */
    public const SUSPECT_HEADER = 'X-Suspect-Network';

    public const TRAP = 'hidden field filled in';
    public const SUSPECT_NETWORK = 'network marked by the server';
    public const NO_BROWSER = 'no browser named';
    public const OUTDATED_BROWSER = 'outdated browser';

    /** The oldest major version taken for a browser still in use (Chrome and Firefox 80: 2020; Safari 11: 2017). */
    private const OLDEST = ['chrome' => 80, 'firefox' => 80, 'safari' => 11];

    /** Why the submission is taken for a bot's, or null for a person's. */
    public static function reason(Request $request, string $trap): ?string
    {
        if (trim($trap) !== '') {
            return self::TRAP;
        }
        if (trim((string) $request->headers->get(self::SUSPECT_HEADER)) === '1') {
            return self::SUSPECT_NETWORK;
        }
        return self::browserReason($request->headers->get('User-Agent'));
    }

    /**
     * Whether a User-Agent is a bot's disguise: missing, Internet Explorer, or
     * Chrome, Firefox or Safari older than OLDEST. Anything else (current
     * browsers, other programs) passes.
     */
    public static function browserReason(?string $agent): ?string
    {
        $agent = trim((string) $agent);
        if ($agent === '') {
            return self::NO_BROWSER;
        }
        if (preg_match('#\bMSIE \d|\bTrident/\d#', $agent) === 1) {
            return self::OUTDATED_BROWSER;
        }
        $version = match (true) {
            preg_match('#\b(?:Chrome|Chromium|CriOS)/(\d+)\.#', $agent, $m) === 1 => [(int) $m[1], self::OLDEST['chrome']],
            preg_match('#\b(?:Firefox|FxiOS)/(\d+)\.#', $agent, $m) === 1 => [(int) $m[1], self::OLDEST['firefox']],
            preg_match('#\bVersion/(\d+)[.\d]* (?:Mobile/\S+ )?Safari/#', $agent, $m) === 1 => [(int) $m[1], self::OLDEST['safari']],
            default => null,
        };
        return $version !== null && $version[0] < $version[1] ? self::OUTDATED_BROWSER : null;
    }
}
