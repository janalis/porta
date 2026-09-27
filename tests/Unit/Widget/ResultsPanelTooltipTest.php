<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * What a results row says under the pointer. Apart from ResultsPanelWidgetTest,
 * which sits on the `too-many-methods` ceiling.
 */
final class ResultsPanelTooltipTest extends TestCase
{
    /** A row the panel had to cut says where it is and all of its line; a row drawn whole says nothing. */
    public function testACutRowSaysItselfWhole(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Usages', ResultsPanelFields::resultsOnly());
        $long = 'a line of code long enough that no forty-column panel could ever hold it whole';
        $panel->setResults([
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'short', 'ranges' => []],
            ['path' => 'src/B.php', 'line' => 9, 'text' => $long, 'ranges' => []],
            ['path' => '', 'line' => 0, 'text' => $long, 'ranges' => []],
            ['path' => 'src/C.php', 'line' => 0, 'text' => 'Unused import', 'tail' => 'worse.unused_import_with_a_very_long_rule_name', 'ranges' => []],
        ]);
        $panel->render(new RenderContext(40, 6));

        $this->assertNull($panel->tooltipAt(0, 3), 'drawn whole');
        $cut = $panel->tooltipAt(1, 3);
        $this->assertSame(['results:1', "src/B.php:10\n".$long, 1, 0, 40], [$cut?->key, $cut?->text, $cut?->row, $cut?->colStart, $cut?->colEnd]);
        $this->assertSame($long, $panel->tooltipAt(2, 3)?->text, 'a location-less row has only its text');
        $this->assertSame("src/C.php:1\nUnused import  worse.unused_import_with_a_very_long_rule_name", $panel->tooltipAt(3, 3)?->text, 'the rule rides along');
        $this->assertNull($panel->tooltipAt(5, 3), 'past the rows');

        $panel->close();
        $this->assertNull($panel->tooltipAt(1, 3), 'a closed panel keeps its last rect, and says nothing');
    }

    public function testAGroupedRowMeasuresItsOwnShorterPrefix(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Find in Project', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/a/very/long/path/to/some/File.php', 'line' => 0, 'text' => 'twenty-five chars of it!', 'ranges' => []]]);
        $rows = \array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext(40, 8)));
        $row = (int) \array_key_first(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'twenty-five')));

        $this->assertNull($panel->tooltipAt($row, 3), 'under its file header the row fits, though flat it would not');
        $this->assertNull($panel->tooltipAt((int) \array_key_first(\array_filter($rows, static fn (string $r): bool => \str_contains($r, 'File.php'))), 3), 'a header is not a match');
    }
}
