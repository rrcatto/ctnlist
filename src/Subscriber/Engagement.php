<?php

declare(strict_types=1);

namespace App\Subscriber;

use App\Config\SiteConfig;
use App\Repository\SubscriberRepository;
use Psr\Clock\ClockInterface;

/**
 * v5 engagement ordering: interactions raise a subscriber's priority and
 * record the interaction; a dislike resets it. Queue order uses these fields.
 */
final class Engagement
{
    public function __construct(
        private readonly SubscriberRepository $subscribers,
        private readonly SiteConfig $site,
        private readonly ClockInterface $clock,
    ) {
    }

    public function bump(string $subscriberUuid, int $amount = 1): bool
    {
        return $this->subscribers->updateEngagement($subscriberUuid, $amount, true, $this->site->subscriptionConfirmAmount, $this->now());
    }

    public function set(string $subscriberUuid, int $priority): bool
    {
        return $this->subscribers->updateEngagement($subscriberUuid, $priority, false, $this->site->subscriptionConfirmAmount, $this->now());
    }

    public function reset(string $subscriberUuid): bool
    {
        return $this->subscribers->updateEngagement($subscriberUuid, 0, false, 0, null);
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
