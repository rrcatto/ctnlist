<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repository;

use App\Repository\Like;
use PHPUnit\Framework\TestCase;

final class LikeTest extends TestCase
{
    public function testWildcardsInInputAreLiteral(): void
    {
        self::assertSame('%a\\_b%', Like::contains(' a_b '));
        self::assertSame('%100\\%%', Like::contains('100%'));
        self::assertSame('%back\\\\slash%', Like::contains('back\\slash'));
        self::assertSame('PROOF%', Like::startsWith('PROOF'));
        self::assertSame('\\%%', Like::startsWith('%'));
        self::assertSame('%%', Like::contains(''), 'empty matches everything, as before');
    }
}
