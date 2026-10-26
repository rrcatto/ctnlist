<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\RequestNumber;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class RequestNumberTest extends TestCase
{
    public function testEmptyOrJunkIsZeroNotA400(): void
    {
        /** @var InputBag<string> $bag as Request::$query and $request are */
        $bag = new InputBag(['l' => '', 'r' => ' 25 ', 'x' => 'ten', 'n' => '-3', 'f' => '1.5']);
        self::assertSame([0, 25, 0, -3, 0, 0], array_map(static fn(string $key): int => RequestNumber::of($bag, $key), ['l', 'r', 'x', 'n', 'f', 'missing']));
    }
}
