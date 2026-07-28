<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\PluginBuffer;

final class PluginBufferTest extends TestCase
{
    public function testStartsEmptyAndPreservesAppendOrder(): void
    {
        /** @var PluginBuffer<array{plugin: string, tool: string}> $buffer */
        $buffer = new PluginBuffer();
        $this->assertSame([], $buffer->all());

        $buffer->add(['plugin' => 'a', 'tool' => 'first']);
        $buffer->add(['plugin' => 'b', 'tool' => 'second']);
        $buffer->add(['plugin' => 'a', 'tool' => 'first']); // duplicates are the caller's concern

        $this->assertSame(
            [
                ['plugin' => 'a', 'tool' => 'first'],
                ['plugin' => 'b', 'tool' => 'second'],
                ['plugin' => 'a', 'tool' => 'first'],
            ],
            $buffer->all(),
            'rows drain in registration order, unfiltered',
        );
    }
}
