<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\I18n\Translator;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/** What the diff pane's controls say under the pointer. */
final class DiffTooltipsTest extends TestCase
{
    public function testTheGearSaysWhatTheDiffShows(): void
    {
        $diff = new DiffViewWidget(new Translator('en'));
        $diff->render(new RenderContext(80, 10));
        $anchor = $diff->settingsAnchor();
        $this->assertNotNull($anchor);

        $tip = $diff->tooltipAt(0, $anchor[0] + 1);
        $this->assertSame(
            ['diff:settings', 'Diff settings: Unified diff, whitespace changes shown', 0, 1],
            [$tip?->key, $tip?->text, $tip?->row, $tip?->rows],
            'the gear on the bar row',
        );
        $this->assertNull($diff->tooltipAt(0, 2), 'the title');
        $this->assertNull($diff->tooltipAt(1, $anchor[0] + 1), 'the close under the bar');
        $this->assertNull($diff->tooltipAt(5, $anchor[0] + 1), 'below the toolbar');

        $diff->useSplitView();
        $diff->hideWhitespace();
        $this->assertSame('Diff settings: Side-by-side diff, whitespace changes hidden', $diff->tooltipAt(0, $anchor[0] + 1)?->text);
    }

    public function testAHunksExpandMarkSaysWhatAClickDoes(): void
    {
        $diff = new DiffViewWidget(new Translator('en'));
        $diff->setDocument(DiffDocument::parse("--- a/f\n+++ b/f\n@@ -1,1 +1,1 @@\n-a\n+b\n"), 'f');
        $lines = \array_map(AnsiUtils::stripAnsiCodes(...), $diff->render(new RenderContext(80, 10)));
        $hunkRow = \array_key_first(\array_filter($lines, static fn (string $line): bool => \str_contains($line, '@@')));
        $this->assertIsInt($hunkRow);

        $tip = $diff->tooltipAt($hunkRow, 1);
        $this->assertSame(['diff:expand', 'Show more of the file around the changes (c)', $hunkRow], [$tip?->key, $tip?->text, $tip?->row]);
        $this->assertNull($diff->tooltipAt($hunkRow, 10), 'past the mark, the header says nothing');
        $this->assertNull($diff->tooltipAt($hunkRow + 1, 1), 'a changed line has no mark');

        $diff->makeReadOnly();
        $this->assertNull($diff->tooltipAt($hunkRow, 1), 'read-only, a click expands nothing');
    }
}
