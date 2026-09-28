<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * PgUp/PgDn in the results panel. Apart from ResultsPanelWidgetTest, which
 * sits on the `too-many-methods` ceiling.
 */
final class ResultsPanelPagingTest extends TestCase
{
    /** A page is the rows the panel shows: PgDn lands on a row it drew, PgUp comes back. */
    public function testPageKeysMoveByTheRowsShown(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find in Project', ResultsPanelFields::resultsOnly());
        $panel->setResults(\array_map(
            static fn (int $i): array => ['path' => "src/File{$i}.php", 'line' => $i, 'text' => "match {$i}"],
            \range(start: 0, end: 39),
        ));
        $panel->render(new RenderContext(60, 12));
        $page = $panel->visibleRows();
        $this->assertGreaterThan(1, $page, 'the premise: a page of more than one row');

        $panel->handleInput("\x1b[6~");
        $this->assertSame($page, Probe::get($panel, 'list')->selected());
        $panel->handleInput("\x1b[5~");
        $this->assertSame(0, Probe::get($panel, 'list')->selected());
    }
}
