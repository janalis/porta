<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\LspWireLog;
use Porta\Tests\Support\TempProject;

/**
 * The wire log is read while the server still appends to it, so a look can
 * land between a line's first bytes and its newline.
 */
final class LspWireLogTest extends TestCase
{
    public function testALineStillBeingWrittenIsLeftForTheNextLook(): void
    {
        $initialize = \json_encode(['method' => 'initialize', 'params' => ['initializationOptions' => ['a' => 1]]], \JSON_THROW_ON_ERROR);
        $project = new TempProject(['wire.log' => $initialize."\n".\substr($initialize, offset: 0, length: 20)]);

        $this->assertSame([['a' => 1]], LspWireLog::initializeOptions($project->path('wire.log')), 'the whole line, and not the half one');

        \file_put_contents($project->path('wire.log'), \substr($initialize, offset: 20)."\n", \FILE_APPEND);

        $this->assertSame([['a' => 1], ['a' => 1]], LspWireLog::initializeOptions($project->path('wire.log')), 'read once its newline is there');
    }

    public function testNoLogYetIsNoInitialize(): void
    {
        $project = new TempProject([]);

        $this->assertSame([], LspWireLog::initializeOptions($project->path('wire.log')));
    }
}
