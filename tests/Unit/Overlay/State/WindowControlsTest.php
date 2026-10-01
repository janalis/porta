<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Overlay\State;

use PHPUnit\Framework\TestCase;
use Porta\Overlay\Region\CloseButton;
use Porta\Overlay\State\WindowControls;

final class WindowControlsTest extends TestCase
{
    public function testMacOsClosesOnTheLeftAndEveryOtherPlatformOnTheRight(): void
    {
        $this->assertSame(CloseButton::Mac, new WindowControls('Darwin')->closeButton);
        $this->assertSame(CloseButton::Standard, new WindowControls('Linux')->closeButton);
        $this->assertSame(CloseButton::Standard, new WindowControls('Windows')->closeButton);
        $this->assertSame(CloseButton::forOs(\PHP_OS_FAMILY), new WindowControls()->closeButton, 'the default is the running platform');
    }
}
