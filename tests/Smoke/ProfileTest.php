<?php

declare(strict_types=1);

namespace App\Tests\Smoke;

/** The signed-in subscriber's pages and the ownership prompt on subscriber links. */
final class ProfileTest extends SmokeTestCase
{
    private const PICTURE_EMAIL = 'smoke-picture@ctnlist.test';

    private ?string $originalBusiness = null;

    protected function tearDown(): void
    {
        self::$db->prepare('UPDATE subscribers SET s_business = ? WHERE s_id = ?')->execute([$this->originalBusiness, self::$admin['s_id']]);
        parent::tearDown();
    }

    /**
     * Choose, crop (the editor's PNG) or upload a file, see it in the menu and
     * on the profile, replace and remove it. A persistent fixture subscriber,
     * so the development administrator's own picture is never touched.
     */
    public function testProfilePicture(): void
    {
        self::$db->prepare('INSERT INTO subscribers (s_email, s_fname) VALUES (?, ?) ON CONFLICT ((LOWER(s_email))) DO NOTHING')->execute([self::PICTURE_EMAIL, 'Picture']);
        $id = (int) self::value('SELECT s_id FROM subscribers WHERE s_email = ?', [self::PICTURE_EMAIL]);
        self::$db->prepare('DELETE FROM subscriber_images WHERE si_s_id = ?')->execute([$id]);
        $client = self::client();
        self::assertSame(302, self::request($client, 'GET', '/auth/verify?token=' . self::issueLoginToken($id))['status']);
        try {
            $page = self::request($client, 'GET', '/edit-profile')['body'];
            self::assertMatchesRegularExpression('#<img class="app-avatar" src="/assets/images/avatar-placeholder-[^"]+\.svg"#', $page, 'no picture yet: the placeholder in the menu');
            self::assertStringContainsString('enctype="multipart/form-data"', $page);
            self::assertStringContainsString('data-profile-image data-size="256"', $page, 'the editor');

            // What the editor posts: the cropped square as a PNG data URL.
            $saved = self::submitAndFollow($client, '/edit-profile', 'profile_image', ['profile_image[edited]' => 'data:image/png;base64,' . base64_encode(self::picture('png', 300, 300))]);
            self::assertStringContainsString('Your profile picture has been saved.', $saved);
            $src = self::match('#<img class="app-avatar" src="(/profile/image\?v=[0-9a-f]{12})"#', $saved, 'the picture in the menu')[1];
            $image = self::request($client, 'GET', $src);
            self::assertSame(200, $image['status']);
            $info = getimagesizefromstring($image['body']);
            self::assertNotFalse($info);
            self::assertSame([256, 256, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], 'stored as the 256-pixel square');
            self::assertStringContainsString('src="' . $src . '"', self::request($client, 'GET', '/profile')['body'] . self::request($client, 'GET', '/profile/subscriber/' . self::value('SELECT s_uuid FROM subscribers WHERE s_id = ?', [$id]))['body']);

            // Without JavaScript: the file itself, cropped to its centred square by the server.
            $fields = self::formFields(self::request($client, 'GET', '/edit-profile')['body'], 'profile_image');
            $file = tempnam(sys_get_temp_dir(), 'pic');
            file_put_contents($file, self::picture('jpeg', 640, 480));
            $upload = self::request($client, 'POST', '/profile/image', ['profile_image[image]' => new \CURLFile($file, 'image/jpeg', 'me.jpg')] + $fields);
            unlink($file);
            self::assertSame(302, $upload['status']);
            $replaced = self::match('#<img class="app-avatar" src="(/profile/image\?v=[0-9a-f]{12})"#', self::request($client, 'GET', '/edit-profile')['body'])[1];
            self::assertNotSame($src, $replaced, 'a new picture is a new address');
            self::assertSame(1, (int) self::value('SELECT COUNT(*) FROM subscriber_images WHERE si_s_id = ?', [$id]));

            $text = tempnam(sys_get_temp_dir(), 'pic');
            file_put_contents($text, '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');
            $refused = self::request($client, 'POST', '/profile/image', ['profile_image[image]' => new \CURLFile($text, 'image/png', 'evil.png')] + $fields);
            unlink($text);
            self::assertSame(422, $refused['status']);
            self::assertMatchesRegularExpression('#id="profile_image_image_error1">That file is not a PNG, JPEG or WebP picture#', $refused['body']);
            $none = self::submitForm($client, '/edit-profile', 'profile_image');
            self::assertSame(422, $none['status']);
            self::assertStringContainsString('Choose a picture first.', $none['body']);
            $forged = self::submitForm($client, '/edit-profile', 'profile_image', ['profile_image[csrf]' => 'forged', 'profile_image[edited]' => 'data:image/png;base64,' . base64_encode(self::picture('png', 50, 50))]);
            self::assertSame(422, $forged['status']);
            self::assertSame($replaced, self::match('#<img class="app-avatar" src="(/profile/image\?v=[0-9a-f]{12})"#', self::request($client, 'GET', '/edit-profile')['body'])[1], 'nothing changed');

            $removed = self::request($client, 'POST', '/profile/image/remove', ['csrf' => self::csrfToken(self::request($client, 'GET', '/edit-profile')['body'])]);
            self::assertSame(302, $removed['status']);
            self::assertStringContainsString('avatar-placeholder', self::request($client, 'GET', '/edit-profile')['body']);
            $fallback = self::request($client, 'GET', '/profile/image');
            self::assertSame(302, $fallback['status'], 'no picture: the placeholder');
            self::assertStringContainsString('avatar-placeholder', $fallback['location']);
        } finally {
            self::$db->prepare('DELETE FROM subscriber_images WHERE si_s_id = ?')->execute([$id]);
            self::$db->prepare('DELETE FROM auth_sessions WHERE as_s_id = ?')->execute([$id]);
            self::$db->prepare('DELETE FROM auth_login_tokens WHERE alt_s_id = ?')->execute([$id]);
        }
        $anonymous = self::request(self::client(), 'GET', '/profile/image');
        self::assertStringEndsWith('/login', $anonymous['location'], 'only your own picture, when signed in');
        self::assertStringEndsWith('/login', self::request(self::client(), 'POST', '/profile/image', [])['location']);
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private static function picture(string $format, int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 80, 40));
        ob_start();
        $format === 'png' ? imagepng($image) : imagejpeg($image);
        return (string) ob_get_clean();
    }

    public function testEditProfileRoundTrip(): void
    {
        $this->originalBusiness = (string) self::value('SELECT s_business FROM subscribers WHERE s_id = ' . self::$admin['s_id']);
        $client = self::client();
        self::loginAsAdmin($client);
        self::assertStringContainsString('Select Country', self::request($client, 'GET', '/edit-profile')['body']);

        $page = self::submitAndFollow($client, '/edit-profile', 'profile', ['profile[business]' => 'Smoke Test Ltd']);
        self::assertStringContainsString('Your profile has been updated.', $page);
        self::assertStringContainsString('value="Smoke Test Ltd"', $page);
        self::assertStringContainsString('Smoke Test Ltd', self::request($client, 'GET', '/profile/subscriber/' . self::$admin['s_uuid'])['body']);

        $invalid = self::submitForm($client, '/edit-profile', 'profile', ['profile[business]' => 'Kept Ltd', 'profile[birthday]' => '2999-01-01']);
        self::assertSame(422, $invalid['status']);
        self::assertMatchesRegularExpression('#id="profile_birthday_error1">The birthdate cannot be in the future#', $invalid['body']);
        self::assertStringContainsString('value="Kept Ltd"', $invalid['body'], 'values are kept');
        self::assertSame('Smoke Test Ltd', (string) self::value('SELECT s_business FROM subscribers WHERE s_id = ' . self::$admin['s_id']), 'nothing saved');

        $forged = self::submitForm($client, '/edit-profile', 'profile', ['profile[csrf]' => 'forged']);
        self::assertSame(422, $forged['status']);
        self::assertStringContainsString('CSRF token is invalid', $forged['body']);
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
        $csrf = self::match('/name="(?:\w+\[)?csrf\]?"[^>]*value="([^"]+)"/', $form);
        $response = self::request($client, 'POST', '/auth/request', ['csrf' => html_entity_decode($csrf[1]), 'subscriber_token' => 'nope']);
        self::assertSame(404, $response['status']);
    }
}
