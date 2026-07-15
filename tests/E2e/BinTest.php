<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;

final class BinTest extends TestCase
{
    /** @return array{0: int, 1: string} exit code + combined output */
    private function porta(string $args): array
    {
        $bin = escapeshellarg(\dirname(__DIR__, levels: 2).'/bin/porta');
        exec(\PHP_BINARY.' '.$bin.' '.$args.' 2>&1', $output, $exitCode);

        return [$exitCode, implode("\n", $output)];
    }

    public function testHelpWorksWithoutATty(): void
    {
        [$exitCode, $text] = $this->porta('--help');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('porta', $text);
        $this->assertStringContainsString('Ctrl+Space', $text, 'help carries the keybindings cheatsheet');
        $this->assertStringContainsString('--no-lsp', $text);
    }

    public function testVersionWorksWithoutATty(): void
    {
        [$exitCode, $text] = $this->porta('--version');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('0.1.0', $text);
    }

    public function testMissingPathFails(): void
    {
        [$exitCode, $text] = $this->porta('/no/such/path');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('no such file or directory', $text);
    }

    public function testRefusesToLaunchWithoutATty(): void
    {
        // No --help/--version → the command reaches the interactive-terminal check.
        [$exitCode, $text] = $this->porta('.');

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('an interactive terminal is required', $text);
    }
}
