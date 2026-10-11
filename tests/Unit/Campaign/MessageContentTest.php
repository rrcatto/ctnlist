<?php

declare(strict_types=1);

namespace App\Tests\Unit\Campaign;

use App\Campaign\MessageContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessageContentTest extends TestCase
{
    #[DataProvider('blank')]
    public function testMarkupAndWhiteSpaceAloneAreBlank(string $html, string $text): void
    {
        self::assertTrue(MessageContent::isBlank($html, $text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function blank(): iterable
    {
        yield 'nothing' => ['', ''];
        yield 'white space' => [" \n\t ", "\r\n  "];
        yield 'the empty paragraph an editor leaves' => ['<p>&nbsp;</p>', ''];
        yield 'empty markup' => ['<div><p><br></p><p> </p></div>', ''];
        yield 'non-breaking and zero-width spaces' => ["<p>\u{00A0}\u{200B}\u{FEFF}</p>", "\u{00A0}"];
        yield 'a style block and a comment' => ['<style>p { color: red; }</style><!-- note --><p></p>', ''];
        yield 'a script' => ['<script>alert(1)</script>', ''];
        yield 'a horizontal rule' => ['<hr>', ''];
    }

    #[DataProvider('content')]
    public function testTextOrAnImageIsContent(string $html, string $text): void
    {
        self::assertFalse(MessageContent::isBlank($html, $text));
    }

    /** @return iterable<string, array{string, string}> */
    public static function content(): iterable
    {
        yield 'HTML text' => ['<p>Hello</p>', ''];
        yield 'text part only' => ['', 'Hello'];
        yield 'text part beside empty markup' => ['<p>&nbsp;</p>', 'Hello'];
        yield 'an image' => ['<p><img src="https://example.com/a.png" alt=""></p>', ''];
        yield 'a placeholder' => ['{subscription}', ''];
        yield 'an entity' => ['<p>&copy;</p>', ''];
        yield 'text after a style block' => ['<style>p{}</style><p>0</p>', ''];
    }
}
