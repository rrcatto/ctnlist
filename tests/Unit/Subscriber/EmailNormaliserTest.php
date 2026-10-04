<?php

declare(strict_types=1);

namespace App\Tests\Unit\Subscriber;

use App\Subscriber\EmailNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Characterisation of the v5 cleanup rules (results produced by the original
 * SubscribersM code). Non-commercial domains such as .org, .gov.za and
 * .ac.za are deliberately made invalid by v5.
 */
final class EmailNormaliserTest extends TestCase
{
    /** @return iterable<string, array{string, string, bool}> */
    public static function addresses(): iterable
    {
        yield 'case and typo' => ['John.Smith@Gmail.COOM', 'john.smith@gmail.com', true];
        yield 'role account' => ['postmaster@x.com', '@@@x.com', false];
        yield 'co.za kept' => ['info@company.co.za', 'info@company.co.za', true];
        yield 'com.za' => ['bob@company.com.za', 'bob@company.com', true];
        yield 'separators' => ['--x..y@-domain.coza', 'x.y@domain.co.za', true];
        yield 'absa freemail' => ['x@freemail.absa.co.za', 'x@absamail.co.za', true];
        yield 'address change' => ['kscp@vaal.net', 'capot@claydisposal.com', true];
        yield 'org blocked' => ['joe@company.org', 'joe@company@@', false];
        yield 'gov.za blocked' => ['joe@sars.gov.za', 'joe@sars@@', false];
        yield 'ac.za blocked' => ['joe@uct.ac.za', 'joe@uct@@', false];
        yield 'org.za kept' => ['joe@x.org.za', 'joe@x.org.za', true];
    }

    #[DataProvider('addresses')]
    public function testCorrect(string $input, string $expected, bool $valid): void
    {
        $corrected = EmailNormaliser::correct($input);
        self::assertSame($expected, $corrected);
        self::assertSame($valid, EmailNormaliser::isValid($corrected));
    }

    public function testParts(): void
    {
        self::assertSame('joe', EmailNormaliser::user(' Joe@Example.com'));
        self::assertSame('example.com', EmailNormaliser::domain(' Joe@Example.com'));
        self::assertSame('j***@example.com', EmailNormaliser::mask('jane@example.com'));
    }
}
