<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\Markdown;
use PHPUnit\Framework\TestCase;

/** The small Markdown renderer of the acceptable use policy: structure in, escaped HTML out. */
final class MarkdownTest extends TestCase
{
    public function testHeadingsParagraphsListsAndTables(): void
    {
        $html = Markdown::toHtml(<<<'MD'
            # Policy title

            Version 1. A paragraph
            on two lines.

            ## 1. Rules

            1. First rule:
               - a nested point;
               - another one.
            2. Second rule.

            | Measure | Warning |
            | --- | --- |
            | Hard bounces | 2% |

            3. A rule after the table.
            MD);
        self::assertStringContainsString('<h1>Policy title</h1>', $html);
        self::assertStringContainsString('<p>Version 1. A paragraph on two lines.</p>', $html);
        self::assertStringContainsString('<h2>1. Rules</h2>', $html);
        self::assertStringContainsString('<ol><li>First rule:<ul><li>a nested point;</li><li>another one.</li></ul></li><li>Second rule.</li></ol>', $html);
        self::assertStringContainsString('<thead><tr><th scope="col">Measure</th><th scope="col">Warning</th></tr></thead><tbody><tr><td>Hard bounces</td><td>2%</td></tr></tbody></table>', $html);
        self::assertStringContainsString('<ol start="3"><li>A rule after the table.</li></ol>', $html);
        self::assertSame('Policy title', Markdown::title("intro\n\n# Policy title\n"));
        self::assertNull(Markdown::title('no heading'));
    }

    public function testEverythingIsEscapedAndOnlySafeLinksAreMade(): void
    {
        $html = Markdown::toHtml("# <script>alert(1)</script>\n\nA **bold** and *plain* `code <b>` & [the site](https://example.org/?a=1&b=2), [mail](mailto:abuse@example.org),"
            . " [here](/privacy), [bad](javascript:alert(1)), [worse](//evil.example) <img src=x onerror=alert(1)>\n\n- item <i>x</i>\n\n| a | <td> |\n|---|---|\n| \"q\" | 'r' |\n");
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<i>', $html);
        self::assertStringNotContainsString('style=', $html, 'nothing the Content-Security-Policy would refuse');
        self::assertStringContainsString('<h1>&lt;script&gt;alert(1)&lt;/script&gt;</h1>', $html);
        self::assertStringContainsString('<strong>bold</strong> and <em>plain</em> <code>code &lt;b&gt;</code> &amp; <a href="https://example.org/?a=1&amp;b=2">the site</a>', $html);
        self::assertStringContainsString('<a href="mailto:abuse@example.org">mail</a>', $html);
        self::assertStringContainsString('<a href="/privacy">here</a>', $html);
        self::assertStringContainsString('[bad](javascript:alert(1))', $html, 'not a link');
        self::assertStringContainsString('[worse](//evil.example)', $html, 'not a link');
        self::assertStringContainsString('<li>item &lt;i&gt;x&lt;/i&gt;</li>', $html);
        self::assertStringContainsString('<th scope="col">&lt;td&gt;</th>', $html);
        self::assertStringContainsString('<td>&quot;q&quot;</td><td>&#039;r&#039;</td>', $html);
    }

    public function testThePolicyDocumentOfTheReleaseRenders(): void
    {
        $markdown = (string) file_get_contents(dirname(__DIR__, 3) . '/docs/AUP.md');
        self::assertNotSame('', trim($markdown));
        $html = Markdown::toHtml($markdown);
        self::assertSame(1, substr_count($html, '<h1>'));
        self::assertGreaterThanOrEqual(3, substr_count($html, '<h2>'));
        self::assertStringContainsString('<table class="table align-middle">', $html);
        self::assertStringNotContainsString('| ---', $html, 'no Markdown is left over');
        self::assertDoesNotMatchRegularExpression('/^#{1,6} /m', $html);
        // It is this application's policy and names the installation, never a product, domain or repository.
        foreach (['catto-mail', 'cattomail', 'catto mail', 'ctnlist', 'github'] as $name) {
            self::assertStringNotContainsStringIgnoringCase($name, $markdown);
        }
        preg_match_all('/\{\{[^}]*\}\}/', $markdown, $placeholders);
        self::assertEqualsCanonicalizing(['{{site_name}}', '{{domain}}', '{{organisation}}'], array_values(array_unique($placeholders[0])));
        // The placeholders survive rendering untouched, so the page can fill them in.
        self::assertStringContainsString('<h1>{{site_name}} Acceptable Use Policy</h1>', $html);
        self::assertMatchesRegularExpression('/^Version [A-Za-z0-9][A-Za-z0-9._-]*/m', $markdown);
        // One address in the whole policy: abuse@ the installation's domain, for abuse reports and complaints.
        // Never the administrator's or the contact address, in any form.
        self::assertStringContainsString('to abuse@{{domain}}.', $markdown);
        self::assertSame(1, substr_count($markdown, '@'), 'no other address');
        self::assertStringNotContainsString('{{contact}}', $markdown);
        self::assertDoesNotMatchRegularExpression('/\badmin(istrator)?\b.*@|questions about this policy/i', $markdown);
    }
}
