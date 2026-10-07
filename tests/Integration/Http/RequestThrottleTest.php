<?php

declare(strict_types=1);

namespace App\Tests\Integration\Http;

use App\Http\RequestThrottle;
use App\Repository\SettingRepository;
use App\Tests\Integration\IntegrationTestCase;

/** The hourly limits follow their Settings; keys are random because limiter state outlives the test transaction. */
final class RequestThrottleTest extends IntegrationTestCase
{
    public function testLimitsFollowTheSettingAndEveryKeyCounts(): void
    {
        $this->service(SettingRepository::class)->set('CONTACT_RATE_LIMIT', '2', false, null);
        $throttle = $this->service(RequestThrottle::class);
        [$ip, $email, $other] = [self::key(), self::key(), self::key()];

        self::assertTrue($throttle->allow('contact', [$ip, $email]));
        self::assertTrue($throttle->allow('contact', [$ip, $other]));
        self::assertFalse($throttle->allow('contact', [$ip, self::key()]), 'the IP address is at its limit');
        self::assertTrue($throttle->allow('contact', [self::key(), $email]), 'the address has one left');
        self::assertFalse($throttle->allow('contact', [self::key(), $email]), 'and now none');
        self::assertTrue($throttle->allow('forward', [$ip]), 'actions are counted separately');
    }

    /** Global opt-out requests and withdrawals: a fixed six per subscriber per hour (each calls catto-mail). */
    public function testOptOutChangesHaveAFixedLimit(): void
    {
        $throttle = $this->service(RequestThrottle::class);
        $subscriber = self::key();
        for ($i = 1; $i <= 6; $i++) {
            self::assertTrue($throttle->allow('optout', [$subscriber]), "change {$i}");
        }
        self::assertFalse($throttle->allow('optout', [$subscriber]), 'the seventh within the hour is refused');
        self::assertTrue($throttle->allow('optout', [self::key()]), 'per subscriber');
    }

    private static function key(): string
    {
        return bin2hex(random_bytes(8));
    }
}
