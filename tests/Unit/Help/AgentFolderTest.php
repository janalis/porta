<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * The agent tooling lives once, in `.agents/` — where Codex reads its skills —
 * and `.claude` is a link to it. Two copies of the same six skills had drifted,
 * one carrying a rule the other had lost, before they were merged.
 */
final class AgentFolderTest extends TestCase
{
    public function testClaudeFolderIsALinkToTheAgentsFolder(): void
    {
        $root = \dirname(__DIR__, levels: 3);
        $link = $root.'/.claude';

        $this->assertTrue(\is_link($link), '.claude must be a symlink to .agents, not a copy that drifts');
        $this->assertSame('.agents', \readlink($link), 'a relative link survives a clone anywhere');
        $this->assertSame(\realpath($root.'/.agents'), \realpath($link));
    }
}
