<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Install;

use PHPUnit\Framework\TestCase;
use Porta\Install\PortaBinary;

final class PortaBinaryTest extends TestCase
{
    public function testOutsideAPharItIsThePackagesOwnBinPorta(): void
    {
        $this->assertSame(\realpath(\dirname(__DIR__, levels: 3).'/bin/porta'), PortaBinary::path());
        $this->assertSame('', PortaBinary::phar());
    }
}
