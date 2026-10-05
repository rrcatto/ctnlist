<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Http\Pagination;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class PaginationTest extends TestCase
{
    public function testV5RowsPerPageAndClamping(): void
    {
        $default = Pagination::fromRequest(new Request(), 3, 25);
        self::assertSame([3, 3, 10, 20], [$default->page, $default->lastPage, $default->perPage, $default->offset()]);

        $clamped = Pagination::fromRequest(new Request(['r' => '500']), 9, 450);
        self::assertSame([3, 3, 200], [$clamped->page, $clamped->lastPage, $clamped->perPage], '?r= capped at 200, page clamped');

        $empty = Pagination::fromRequest(new Request(['r' => '-4']), 0, 0);
        self::assertSame([1, 1, 1, 0], [$empty->page, $empty->lastPage, $empty->perPage, $empty->offset()], 'v5: a negative r means 1 row');
    }
}
