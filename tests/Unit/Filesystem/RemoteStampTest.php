<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Filesystem;

use PHPUnit\Framework\TestCase;
use Porta\Filesystem\RemoteStamp;

final class RemoteStampTest extends TestCase
{
    public function testTwoStampsAgreeOnlyOnSizeAndTime(): void
    {
        $stamp = new RemoteStamp(10, 100);

        $this->assertTrue($stamp->equals(new RemoteStamp(10, 100)));
        $this->assertFalse($stamp->equals(new RemoteStamp(11, 100)));
        $this->assertFalse($stamp->equals(new RemoteStamp(10, 101)));
    }
}
