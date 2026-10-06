<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\RuntimeSettings;
use App\Config\SiteConfig;
use App\Mail\TransactionalMailer;
use App\Repository\AuthLoginTokenRepository;
use App\Repository\ListRepository;
use App\Repository\SubscriberRepository;
use App\Subscriber\EmailNormaliser;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Issues one-time sign-in links. Requesting a link for an unknown address
 * creates the subscriber identity (no list consent); a new subscriber who
 * asked for their profile is sent on to confirm the ALL list. Requests are
 * rate-limited per address and per client IP, and the caller never learns
 * whether a link was sent.
 */
final class MagicLinkRequester
{
    /** Actions a link may return to (see LoginReturnPath). */
    public const RETURN_ACTIONS = ['profile', 'messages', 'confirm', 'unsubscribe', 'forward', 'like', 'dislike', 'resend'];

    /** How long a link stays valid (AUTH_MAGIC_LINK_TTL, Settings override first): used for the token and every wording of it. */
    public function lifetimeSeconds(): int
    {
        return $this->settings->int('AUTH_MAGIC_LINK_TTL', 1800);
    }

    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly AuthLoginTokenRepository $tokens,
        private readonly ListRepository $lists,
        private readonly TransactionalMailer $mailer,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
        private readonly RequestStack $requestStack,
        private readonly RuntimeSettings $settings,
    ) {
    }

    /** @return bool whether a link was sent (for logging and tests only; never shown) */
    public function request(string $email, string $returnAction = 'profile', ?int $returnMessageId = null, ?int $returnListId = null): bool
    {
        $email = EmailNormaliser::normalise($email);
        if (!EmailNormaliser::isValid($email)) {
            return false;
        }
        $now = $this->clock->now();
        $found = $this->subscribers->findOrCreateIdentity($email, $now->format('Y-m-d H:i:s'));
        if ($found === null) {
            return false;
        }
        $request = $this->requestStack->getCurrentRequest();
        $ip = mb_substr((string) $request?->getClientIp(), 0, 45);
        if ($this->rateLimited($email, $ip, $now->getTimestamp())) {
            return false;
        }

        if ($found['created'] && $returnAction === 'profile') {
            $returnAction = 'confirm';
            $returnListId = $this->lists->findByShortcode('ALL')['l_id'] ?? null;
        }
        if (!in_array($returnAction, self::RETURN_ACTIONS, true)) {
            $returnAction = 'profile';
            $returnMessageId = null;
            $returnListId = null;
        }

        $token = AuthCookie::newToken();
        $hash = AuthCookie::hash($token);
        $this->tokens->create(
            $found['identity']['s_id'],
            $email,
            $hash,
            $now->format('Y-m-d H:i:s'),
            date('Y-m-d H:i:s', $now->getTimestamp() + $this->lifetimeSeconds()),
            $ip,
            (string) $request?->headers->get('User-Agent', ''),
            $returnAction,
            $returnMessageId,
            $returnListId
        );
        $url = rtrim($this->site->baseUrl, '/') . '/auth/verify?token=' . rawurlencode($token);
        // Asked for to confirm a list (subscribe form, consent link): the email says so.
        $confirmList = $returnAction === 'confirm' && $returnListId !== null ? $this->lists->findById($returnListId) : null;
        if (!$this->mailer->sendMagicLink($email, $found['identity']['s_uuid'], $url, $this->lifetimeSeconds(), $confirmList['l_name'] ?? null)) {
            $this->tokens->delete($hash);
            return false;
        }
        return true;
    }

    private function rateLimited(string $email, string $ip, int $now): bool
    {
        $since = static fn(int $window): string => date('Y-m-d H:i:s', $now - max(60, $window));
        if ($this->tokens->countForEmailSince($email, $since($this->settings->int('AUTH_MAGIC_LINK_EMAIL_WINDOW', 900))) >= max(1, $this->settings->int('AUTH_MAGIC_LINK_MAX_PER_EMAIL', 5))) {
            return true;
        }
        return $ip !== '' && $this->tokens->countForIpSince($ip, $since($this->settings->int('AUTH_MAGIC_LINK_IP_WINDOW', 3600))) >= max(1, $this->settings->int('AUTH_MAGIC_LINK_MAX_PER_IP', 20));
    }
}
