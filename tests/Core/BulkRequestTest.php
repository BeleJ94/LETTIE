<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\BulkRequest;
use PHPUnit\Framework\TestCase;

final class BulkRequestTest extends TestCase
{
    public function testParsesCommaSeparatedIdsInOrderWithoutDuplicates(): void
    {
        self::assertSame([12, 15, 18], BulkRequest::ids('12,15,18'));
        self::assertSame([12, 15], BulkRequest::ids(' 12 , 15,12 '));
        self::assertSame([7, 3], BulkRequest::ids(['7', 3, '7']));
    }

    public function testDropsAnythingThatIsNotAPositiveInteger(): void
    {
        self::assertSame([4], BulkRequest::ids('0,-1,4,abc,1.5,1 OR 1=1,'));
        self::assertSame([], BulkRequest::ids(''));
        self::assertSame([], BulkRequest::ids(null));
        self::assertSame([], BulkRequest::ids(['x', [], null, 0]));
        self::assertSame([], BulkRequest::ids(42), 'a bare number is not a selection');
    }

    public function testASelectionIsCapped(): void
    {
        $ids = BulkRequest::ids(implode(',', range(1, BulkRequest::MAX_IDS + 50)));
        self::assertCount(BulkRequest::MAX_IDS, $ids);
        self::assertSame(BulkRequest::MAX_IDS, end($ids));
    }
}
