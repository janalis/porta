<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Debug;

use PHPUnit\Framework\TestCase;
use Porta\Debug\DebugSessionState;

final class DebugSessionStateTest extends TestCase
{
    public function testStoppedLineForOnlyAnswersTheStoppedPath(): void
    {
        $state = new DebugSessionState();
        $this->assertNull($state->stoppedLineFor('/p/a.php'), 'never stopped');

        $state->stopAt('/p/a.php', 4);
        $this->assertSame(4, $state->stoppedLineFor('/p/a.php'));
        $this->assertNull($state->stoppedLineFor('/p/other.php'), 'a different path is not the stopped one');

        $this->assertSame('/p/a.php', $state->stoppedPath());
        $this->assertSame(4, $state->stoppedLine());

        $state->clear();
        $this->assertNull($state->stoppedLineFor('/p/a.php'));
        $this->assertNull($state->stoppedPath());
        $this->assertNull($state->stoppedLine());
    }
}
