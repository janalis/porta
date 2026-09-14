<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Clipboard;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class ClipboardTest extends TestCase
{
    public function testCopyWritesAnOsc52SequenceWithTheBase64Payload(): void
    {
        $terminal = new VirtualTerminal(columns: 40, rows: 10);
        $clipboard = new Clipboard(new Tui(terminal: $terminal));

        $clipboard->copy('hello');

        $this->assertStringContainsString("\x1b]52;c;".\base64_encode('hello')."\x07", $terminal->getOutput());
    }

    public function testOversizedPayloadsAreSkipped(): void
    {
        $terminal = new VirtualTerminal(columns: 40, rows: 10);
        $clipboard = new Clipboard(new Tui(terminal: $terminal));

        $clipboard->copy(\str_repeat('x', times: 100_000));

        $this->assertStringNotContainsString(']52;c;', $terminal->getOutput(), 'a 100k+ payload would overflow many terminals');
    }
}
