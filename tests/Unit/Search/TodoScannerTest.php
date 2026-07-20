<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Search\ProjectSearch;
use Porta\Search\TodoScanner;
use Porta\Search\TodoTags;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestBottomDock;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class TodoScannerTest extends TestCase
{
    private string $originalPath;

    protected function setUp(): void
    {
        // No grep on PATH → the engine's synchronous PHP fallback, so the
        // scan completes inside scan() without pumping the event loop.
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    public function testScanFillsTheTodoTabWithBothMarkersCaseSensitively(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// TODO extract this\n",
            'src/B.py' => "# FIXME races\n# just a todo word in prose\n",
        ]);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 120, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $results, new DebugPanelWidget());

        new TodoScanner($dock, new ProjectSearch($project->dir), new \Porta\I18n\Translator('en'), new TodoTags())->scan();

        $this->assertSame(ResultsPanelMode::Todo, $dock->mode());
        $this->assertSame('TODO', $results->getTitle());
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $results->render(new RenderContext(120, 12))));
        $this->assertStringContainsString('TODO extract this', $plain);
        $this->assertStringContainsString('FIXME races', $plain);
        $this->assertStringNotContainsString('prose', $plain, 'lower-case "todo" prose is not a hit');
        $this->assertStringContainsString('2 results', $results->statusText());
    }

    public function testConfiguredTagsWidenAndNarrowTheScan(): void
    {
        $project = new TempProject([
            'src/A.php' => "<?php\n// HACK temporary\n// TODO later\n",
        ]);
        $tui = new Tui(terminal: new VirtualTerminal(columns: 120, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));
        $results = new ResultsPanelWidget();
        $dock = TestBottomDock::create($tui, new TerminalPanelWidget(), $results, new DebugPanelWidget());

        // A custom tag list restricted to HACK: TODO is no longer a hit.
        $tags = new TodoTags('HACK');
        new TodoScanner($dock, new ProjectSearch($project->dir), new \Porta\I18n\Translator('en'), $tags)->scan();
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $results->render(new RenderContext(120, 12))));
        $this->assertStringContainsString('HACK temporary', $plain, 'HACK is scanned when configured');
        $this->assertStringNotContainsString('TODO later', $plain, 'a tag outside the list is not a hit');

        // A blank list falls back to the built-in TODO|FIXME.
        $tags->set('  ,  ');
        new TodoScanner($dock, new ProjectSearch($project->dir), new \Porta\I18n\Translator('en'), $tags)->scan();
        $plain = AnsiUtils::stripAnsiCodes(implode("\n", $results->render(new RenderContext(120, 12))));
        $this->assertStringContainsString('TODO later', $plain, 'blank tags fall back to TODO|FIXME');
    }
}
