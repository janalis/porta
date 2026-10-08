<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RendersExactWidth;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * A collaborator's selection, tinted in their colour on every line it covers
 * — whichever way round they made it — and nowhere else.
 */
final class CodeEditorRemoteSelectionTest extends TestCase
{
    use RendersExactWidth;

    public function testTheStoreSplitsASelectionByLineWhicheverWayItWasMade(): void
    {
        $editor = new CodeEditorWidget();
        $store = $editor->decorations;
        // The ones without a selection first: they are passed over, not stopped at.
        $store->setRemoteCaret('a.php', 'plain', ['line' => 1, 'col' => 0, 'label' => 'y', 'slot' => 2]);
        $store->setRemoteCaret('a.php', 'back', ['line' => 0, 'col' => 2, 'label' => 'x', 'slot' => 1, 'anchor' => ['line' => 2, 'col' => 3]]);
        $store->setRemoteCaret('a.php', 'none', ['line' => 1, 'col' => 0, 'label' => 'z', 'slot' => 3, 'anchor' => null]);

        $this->assertSame(
            [
                0 => [['start' => 2, 'end' => \PHP_INT_MAX, 'slot' => 1]],
                1 => [['start' => 0, 'end' => \PHP_INT_MAX, 'slot' => 1]],
                2 => [['start' => 0, 'end' => 3, 'slot' => 1]],
            ],
            $store->remoteSelectionsIn('a.php'),
        );
        $this->assertSame([], $store->remoteSelectionsIn('b.php'));
        $this->assertSame([], $store->remoteSelectionsIn(null));
    }

    public function testEachCoveredCellIsDrawnInTheCollaboratorsColour(): void
    {
        $editor = new CodeEditorWidget();
        $editor->openFile('a.php', "abcdef\nghijkl\nmnop\n\n");
        $editor->setFocused(true);
        // Agent2's colour (dark: 130): from "c" on line 0 to "o" on line 2.
        $editor->decorations->setRemoteCaret('a.php', 'peer', ['line' => 2, 'col' => 2, 'label' => 'peer', 'slot' => 1, 'anchor' => ['line' => 0, 'col' => 2]]);
        $editor->decorations->setRemoteCaret('a.php', 'empty', ['line' => 3, 'col' => 0, 'label' => 'e', 'slot' => 2, 'anchor' => ['line' => 3, 'col' => 0]]);

        $rows = $editor->render(new RenderContext(60, 6));

        $this->assertMatchesRegularExpression('/\e\[48;5;130m(\e\[[0-9;:]*m)*cdef/', $rows[0], 'from the anchor to the line\'s end');
        $this->assertMatchesRegularExpression('/\e\[48;5;130m(\e\[[0-9;:]*m)*ghijkl/', $rows[1], 'the whole line between');
        $this->assertMatchesRegularExpression('/\e\[48;5;130m(\e\[[0-9;:]*m)*mn/', $rows[2], 'up to the caret on its last line');
        $this->assertStringContainsString('peer', AnsiUtils::stripAnsiCodes($rows[2]));
        self::assertExactWidth($rows, 60);
    }
}
