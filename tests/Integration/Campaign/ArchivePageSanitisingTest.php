<?php

declare(strict_types=1);

namespace App\Tests\Integration\Campaign;

use App\Tests\Integration\IntegrationTestCase;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Message HTML on the archive page cannot run script on the site (it is
 * written by holders of messages.manage, a delegable permission), while the
 * presentational markup email layouts use survives.
 */
final class ArchivePageSanitisingTest extends IntegrationTestCase
{
    private const LAYOUT = '<table width="600" cellpadding="0" cellspacing="0" border="0" align="center" bgcolor="#ffffff"><tr>'
        . '<td valign="top" style="padding:8px;color:#333"><h2 class="title">Hello</h2>'
        . '<p><a href="https://example.com/offer">Offer</a> <img src="https://example.com/i.png" alt="Logo" width="120"></p></td></tr></table>';

    public function testScriptIsRemovedAndLayoutKept(): void
    {
        $sanitizer = $this->sanitizer();
        $hostile = '<p onclick="steal()">Hi</p><script>steal()</script><img src="x" onerror="steal()">'
            . '<a href="javascript:steal()">x</a><iframe src="https://evil.example"></iframe>'
            . '<form action="https://evil.example"><input name="csrf"></form><style>input[value^=a]{background:url(//evil)}</style>'
            . '<svg onload="steal()"></svg><object data="x"></object>';
        $clean = $sanitizer->sanitize($hostile);
        foreach (['steal', '<script', 'onerror', 'onclick', 'javascript:', '<iframe', '<form', '<style', '<svg', '<object'] as $gone) {
            self::assertStringNotContainsStringIgnoringCase($gone, $clean, $gone);
        }

        $kept = $sanitizer->sanitize(self::LAYOUT);
        foreach (['width="600"', 'cellpadding="0"', 'align="center"', 'bgcolor="#ffffff"', 'valign="top"', 'style="padding:8px;color:#333"',
            'class="title"', 'href="https://example.com/offer"', 'src="https://example.com/i.png"', 'alt="Logo"', 'rel="noopener noreferrer"'] as $attribute) {
            self::assertStringContainsString($attribute, $kept, $attribute);
        }
        self::assertSame(str_repeat('<p>x</p>', 5000), $sanitizer->sanitize(str_repeat('<p>x</p>', 5000)), 'large messages are not truncated');
    }

    public function testTheArchivePageServesSanitisedHtmlAndTheStoredCopyIsUntouched(): void
    {
        $stored = '<p id="kept">Archived</p><script>alert(1)</script>';
        $id = (int) $this->db->fetchOne("INSERT INTO archives (a_subject, a_html) VALUES ('XSS', ?) RETURNING a_id", [$stored]);
        $kernel = self::$kernel ?? throw new \LogicException('No kernel.');
        $body = (string) $kernel->handle(Request::create('/archive/' . $id))->getContent();

        self::assertStringContainsString('<p id="kept">Archived</p>', $body);
        self::assertStringNotContainsString('alert(1)', $body);
        self::assertSame($stored, $this->db->fetchOne('SELECT a_html FROM archives WHERE a_id = ?', [$id]), 'storage keeps what was sent');
    }

    private function sanitizer(): HtmlSanitizerInterface
    {
        $sanitizer = static::getContainer()->get('html_sanitizer.sanitizer.app.message_html');
        self::assertInstanceOf(HtmlSanitizerInterface::class, $sanitizer);
        return $sanitizer;
    }
}
