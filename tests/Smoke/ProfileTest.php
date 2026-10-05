<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** The signed-in subscriber's pages and the ownership prompt on subscriber links. */
final class ProfileTest extends SmokeTestCase
{
    private ?string $originalBusiness = null;

    protected function tearDown(): void
    {
        self::$db->prepare('UPDATE subscribers SET s_business = ? WHERE s_id = ?')->execute([$this->originalBusiness, self::$admin['s_id']]);
        parent::tearDown();
    }

    public function testEditProfileRoundTrip(): void
    {
        $this->originalBusiness = (string) self::$db->query('SELECT s_business FROM subscribers WHERE s_id = ' . self::$admin['s_id'])->fetchColumn();
        $client = self::client();
        self::loginAsAdmin($client);
        $form = self::request($client, 'GET', '/edit-profile');
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form['body'], $csrf));
        self::assertStringContainsString('Select Country', $form['body']);

        $saved = self::request($client, 'POST', '/edit-profile', [
            'csrf' => html_entity_decode($csrf[1]), 's_fname' => 'Dev', 's_lname' => 'Admin', 's_business' => 'Smoke Test Ltd',
        ]);
        self::assertSame(302, $saved['status']);
        $page = self::request($client, 'GET', '/edit-profile')['body'];
        self::assertStringContainsString('Your profile has been updated.', $page);
        self::assertStringContainsString('value="Smoke Test Ltd"', $page);
        self::assertStringContainsString('Smoke Test Ltd', self::request($client, 'GET', '/profile/subscriber/' . self::$admin['s_uuid'])['body']);

        self::assertSame(403, self::request($client, 'POST', '/edit-profile', ['csrf' => 'forged'])['status']);
        self::assertSame(302, self::request(self::client(), 'POST', '/edit-profile', [])['status'], 'anonymous: to the login page');
    }

    public function testSomeoneElsesProfileLinkAsksForVerification(): void
    {
        $page = self::request(self::client(), 'GET', '/profile/subscriber/' . self::$admin['s_uuid']);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Authentication required', $page['body']);
        self::assertStringContainsString('This request relates to <strong>a****@ctnlist.test</strong>', $page['body'], 'only the masked address');

        self::assertSame(404, self::request(self::client(), 'GET', '/profile/subscriber/01a10309-8535-7ba9-a26a-000000000000')['status']);
    }

    public function testVerificationRequestForUnknownSubscriberIsNotFound(): void
    {
        $client = self::client();
        $form = self::request($client, 'GET', '/login')['body'];
        self::assertSame(1, preg_match('/name="csrf" value="([^"]+)"/', $form, $csrf));
        $response = self::request($client, 'POST', '/auth/request', ['csrf' => html_entity_decode($csrf[1]), 'subscriber_token' => 'nope']);
        self::assertSame(404, $response['status']);
    }
}
