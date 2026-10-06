<?php

declare(strict_types=1);

namespace App\Tests\Unit\CattoMail;

use App\CattoMail\CattoMailConfig;
use App\CattoMail\WebhookSignature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** catto-mail's webhook signature contract: t=<unix>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>[,v1=…]. */
final class WebhookSignatureTest extends TestCase
{
    private const CURRENT = 'whsec_current_secret';
    private const PREVIOUS = 'whsec_previous_secret';
    private const BODY = '{"id":"0199a000-0000-7000-8000-000000000001","type":"send.completed","created_at":"2026-10-06T10:00:00Z","data":{}}';

    public function testValidSignatureWithTheCurrentSecret(): void
    {
        $now = 1767225600;
        self::assertSame(WebhookSignature::VALID, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now, [self::CURRENT])));
    }

    public function testPreviousSecretIsAcceptedDuringTheOverlap(): void
    {
        $now = 1767225600;
        // catto-mail sends one v1 per active secret; either may be the one ctnlist holds.
        self::assertSame(WebhookSignature::VALID, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now, [self::PREVIOUS])));
        self::assertSame(WebhookSignature::VALID, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now, ['whsec_newer_unknown', self::CURRENT])));
        self::assertSame(WebhookSignature::VALID, $this->verifier($now, self::CURRENT, '')->verify(self::BODY, self::header(self::BODY, $now, [self::CURRENT, self::PREVIOUS])));
    }

    /** Rotation: the old secret works only while CATTOMAIL_WEBHOOK_SECRET_PREVIOUS holds it; nothing rotates automatically. */
    public function testTheOldSecretStopsWorkingOnceThePreviousSecretIsRemoved(): void
    {
        $now = 1767225600;
        $oldOnly = self::header(self::BODY, $now, [self::PREVIOUS]);
        self::assertSame(WebhookSignature::VALID, $this->verifier($now)->verify(self::BODY, $oldOnly), 'during the overlap');
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now, self::CURRENT, '')->verify(self::BODY, $oldOnly), 'after removing it');
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now, self::CURRENT, 'whsec_wrong_previous')->verify(self::BODY, $oldOnly), 'a wrong previous secret');
        self::assertSame(WebhookSignature::VALID, $this->verifier($now, self::CURRENT, '')->verify(self::BODY, self::header(self::BODY, $now, [self::CURRENT])));
    }

    public function testAlteredBodyOrTimestampIsRejected(): void
    {
        $now = 1767225600;
        $header = self::header(self::BODY, $now, [self::CURRENT]);
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now)->verify(str_replace('send.completed', 'send.failed', self::BODY), $header), 'altered body');
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now)->verify(self::BODY . ' ', $header), 'one extra byte');
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now)->verify(self::BODY, str_replace('t=' . $now, 't=' . ($now - 1), $header)), 'altered timestamp');
    }

    public function testStaleTimestampsAreRejected(): void
    {
        $now = 1767225600;
        self::assertSame(WebhookSignature::VALID, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now - 300, [self::CURRENT])), 'exactly 5 minutes');
        self::assertSame(WebhookSignature::STALE, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now - 301, [self::CURRENT])), 'older');
        self::assertSame(WebhookSignature::STALE, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now + 301, [self::CURRENT])), 'from the future');
    }

    public function testInvalidSecretAndMalformedHeaders(): void
    {
        $now = 1767225600;
        self::assertSame(WebhookSignature::MISMATCH, $this->verifier($now)->verify(self::BODY, self::header(self::BODY, $now, ['whsec_somebody_else'])));
        foreach (['', 'v1=' . str_repeat('a', 64), 't=' . $now, 't=abc,v1=' . str_repeat('a', 64), 't=' . $now . ',v1=XYZ', 'sha256=' . str_repeat('a', 64)] as $header) {
            self::assertSame(WebhookSignature::MALFORMED, $this->verifier($now)->verify(self::BODY, $header), $header);
        }
        self::assertSame(WebhookSignature::NOT_CONFIGURED, $this->verifier($now, '', '')->verify(self::BODY, self::header(self::BODY, $now, [self::CURRENT])));
    }

    private function verifier(int $now, string $current = self::CURRENT, string $previous = self::PREVIOUS): WebhookSignature
    {
        return new WebhookSignature(new CattoMailConfig('https://cattomail.test', 'key', $current, $previous), new MockClock(new \DateTimeImmutable('@' . $now)));
    }

    /** @param list<string> $secrets */
    public static function header(string $body, int $timestamp, array $secrets): string
    {
        $parts = ['t=' . $timestamp];
        foreach ($secrets as $secret) {
            $parts[] = 'v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        }
        return implode(',', $parts);
    }
}
