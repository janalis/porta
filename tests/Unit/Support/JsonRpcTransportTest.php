<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\JsonRpcFramer;
use Porta\Support\JsonRpcTransport;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TempProject;

final class JsonRpcTransportTest extends TestCase
{
    public function testAFramerOverflowReachesTheLoggerNamedForThePeer(): void
    {
        $project = new TempProject();
        $lines = [];
        $transport = new JsonRpcTransport([\PHP_BINARY, '-v'], $project->dir, 'chatty peer', static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $framer = Probe::object($transport, 'framer');
        \assert($framer instanceof JsonRpcFramer, description: 'the transport owns a framer');
        $framer->feed(str_repeat('x', JsonRpcFramer::MAX_FRAME + 1));

        $this->assertCount(1, $lines, 'the transport wires the framer overflow into its logger');
        $this->assertStringContainsString('chatty peer overflowed the read buffer', $lines[0]);
    }
}
