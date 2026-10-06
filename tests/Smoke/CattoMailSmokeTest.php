<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/**
 * The catto-mail integration on the running development stack, which has no
 * catto-mail configured (see README "catto-mail development route"): the
 * webhook endpoint refuses events it cannot verify, the administration pages
 * say what is missing, and no page reveals a secret.
 */
final class CattoMailSmokeTest extends SmokeTestCase
{
    public function testWebhookEndpointWithoutSecretOrSignature(): void
    {
        $response = self::request(self::client(), 'POST', '/cattomail/webhook', []);
        self::assertContains($response['status'], [401, 503], 'never accepted without a verifiable signature');
        self::assertSame([], array_filter($response['cookies'], static fn(string $c): bool => str_starts_with($c, 'ctnlist_php_session=')), 'no session started');
        self::assertSame(0, (int) self::value("SELECT COUNT(*) FROM cattomail_webhook_events WHERE cwe_received_at > NOW() - INTERVAL '1 minute'"));
    }

    public function testAdministrationPagesExplainTheConfiguration(): void
    {
        $client = self::client();
        self::loginAsAdmin($client);
        $delivery = self::request($client, 'GET', '/delivery');
        self::assertSame(200, $delivery['status']);
        self::assertStringContainsString('/cattomail/webhook', $delivery['body'], 'the endpoint to register in catto-mail');
        self::assertDoesNotMatchRegularExpression('/shk_[A-Za-z0-9]|whsec_[A-Za-z0-9]/', $delivery['body'], 'no key or secret in the page');
        $validation = self::request($client, 'GET', '/address-validation');
        self::assertSame(200, $validation['status']);
        self::assertStringContainsString('catto-mail is not configured', $validation['body']);
        self::assertSame(403, self::request(self::client(), 'GET', '/delivery')['status'], 'administration only');
    }

    public function testUnsignedOneClickLinksAreRefused(): void
    {
        $uuid = self::$admin['s_uuid'];
        self::assertSame(404, self::request(self::client(), 'GET', "/unsubscribe-link/{$uuid}/ALL/-/forged")['status']);
        self::assertSame(404, self::request(self::client(), 'POST', "/unsubscribe-link/{$uuid}/ALL/-/forged", ['List-Unsubscribe' => 'One-Click'])['status']);
    }
}
