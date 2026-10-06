<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\RuntimeSettings;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Hourly limits on actions that send mail on a visitor's behalf
 * (symfony/rate-limiter, sliding window, state in the installation's cache):
 *
 * - contact: CONTACT_RATE_LIMIT messages per hour, counted separately per
 *   client IP and per submitted email address;
 * - forward: FORWARD_RATE_LIMIT forwards/resends per hour per subscriber.
 *
 * The limits are Settings (RuntimeSettings), so they are read per request.
 * Sign-in links keep their own database-backed limits (MagicLinkRequester).
 */
final class RequestThrottle
{
    private const SETTINGS = ['contact' => ['CONTACT_RATE_LIMIT', 5], 'forward' => ['FORWARD_RATE_LIMIT', 10]];

    public function __construct(
        private readonly RuntimeSettings $settings,
        #[Autowire(service: 'cache.rate_limiter')] private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Consume one attempt for every key; false (nothing consumed) when any
     * key is already at its limit.
     *
     * @param 'contact'|'forward' $action
     * @param list<string> $keys
     */
    public function allow(string $action, array $keys): bool
    {
        [$name, $default] = self::SETTINGS[$action];
        $factory = new RateLimiterFactory([
            'id' => $action,
            'policy' => 'sliding_window',
            'limit' => max(1, $this->settings->int($name, $default)),
            'interval' => '1 hour',
        ], new CacheStorage($this->cache));
        $limiters = array_map(static fn(string $key) => $factory->create(hash('sha256', strtolower(trim($key)))), $keys);
        foreach ($limiters as $limiter) {
            if ($limiter->consume(0)->getRemainingTokens() < 1) {
                return false;
            }
        }
        foreach ($limiters as $limiter) {
            $limiter->consume();
        }
        return true;
    }
}
