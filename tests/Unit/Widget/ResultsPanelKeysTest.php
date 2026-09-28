<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The results panel's list keys: paging, and Esc with a filter typed. Apart
 * from ResultsPanelWidgetTest, which sits on the `too-many-methods` ceiling.
 */
final class ResultsPanelKeysTest extends TestCase
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

    /** Esc clears a typed filter first and keeps the panel open; the next Esc closes it. */
    public function testEscClearsTheFilterBeforeItClosesThePanel(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/A.php', 'line' => 1, 'text' => 'alpha'], ['path' => 'src/B.php', 'line' => 2, 'text' => 'beta']]);
        $panel->handleInput('b');
        $this->assertSame('b', Probe::get($panel, 'filter')->value(), 'the premise: a filter is typed');

        $panel->handleInput("\x1b");
        $this->assertSame('', Probe::get($panel, 'filter')->value());
        $this->assertTrue($panel->isOpen(), 'the first Esc spends itself on the filter');

        $panel->handleInput("\x1b");
        $this->assertFalse($panel->isOpen());
    }

    /** The filter takes the field's own keys: ← to fix a typo, and a paste. */
    public function testTheFilterMovesItsCaretAndTakesAPaste(): void
    {
        $panel = new ResultsPanelWidget();
        $panel->setFocused(true);
        $panel->open('Find', ResultsPanelFields::searchResults());
        $panel->setResults([['path' => 'src/A.php', 'line' => 1, 'text' => 'alpha']]);
        $panel->handleInput('a');
        $panel->handleInput("\x1b[D");
        $panel->handleInput("\x1b[200~alph\x1b[201~");

        $this->assertSame('alpha', Probe::get($panel, 'filter')->value());
    }
}
