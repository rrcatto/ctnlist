<?php

declare(strict_types=1);

namespace App\CattoMail;

use App\Config\SiteConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The per-recipient one-click unsubscribe URL catto-mail puts into
 * List-Unsubscribe / List-Unsubscribe-Post (RFC 8058). It is hosted by
 * ctnlist, works without signing in (an HMAC of subscriber, list and
 * message proves it came from that email), carries no tracking parameters
 * and only unsubscribes from that list: ordinary unsubscribe state stays in
 * ctnlist and is never reported to catto-mail.
 */
final class UnsubscribeLinks
{
    private readonly string $key;

    public function __construct(
        private readonly SiteConfig $site,
        #[Autowire('%kernel.secret%')] #[\SensitiveParameter] string $secret,
    ) {
        $this->key = hash_hmac('sha256', 'ctnlist one-click unsubscribe', $secret, true);
    }

    public function url(string $subscriberUuid, string $listShortcode, string $muid): string
    {
        $uuid = strtolower(trim($subscriberUuid));
        $list = strtoupper(trim($listShortcode));
        return $this->site->baseUrl . 'unsubscribe-link/' . rawurlencode($uuid) . '/' . rawurlencode($list) . '/'
            . ($muid !== '' ? rawurlencode($muid) : '-') . '/' . $this->signature($uuid, $list, $muid);
    }

    public function isValid(string $subscriberUuid, string $listShortcode, string $muid, string $signature): bool
    {
        return hash_equals($this->signature(strtolower(trim($subscriberUuid)), strtoupper(trim($listShortcode)), $muid === '-' ? '' : $muid), $signature);
    }

    /** catto-mail accepts only https unsubscribe URLs. */
    public function isUsable(): bool
    {
        return str_starts_with(strtolower($this->site->baseUrl), 'https://');
    }

    private function signature(string $uuid, string $list, string $muid): string
    {
        return rtrim(strtr(base64_encode(substr(hash_hmac('sha256', $uuid . '|' . $list . '|' . $muid, $this->key, true), 0, 24)), '+/', '-_'), '=');
    }
}
