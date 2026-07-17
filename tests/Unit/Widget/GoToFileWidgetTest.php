<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Widget\GoToFileWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;

final class GoToFileWidgetTest extends TestCase
{
    private function widget(TempProject $project): GoToFileWidget
    {
        $widget = new GoToFileWidget(new Translator('en'), $project->dir);
        $widget->setFocused(true);
        $widget->open();

        return $widget;
    }

    private function type(GoToFileWidget $widget, string $text): void
    {
        foreach (str_split($text) as $ch) {
            $widget->handleInput($ch);
        }
    }

    public function testClosedRendersNothing(): void
    {
        $project = new TempProject(['a.php' => '']);
        $widget = new GoToFileWidget(new Translator('en'), $project->dir);

        $this->assertSame([], $widget->render(new RenderContext(80, 10)));
        $widget->handleInput('x'); // ignored while closed
        $widget->close(); // no-op while closed
        $this->assertFalse($widget->isOpen());
    }

    public function testEmptyQueryShowsFirstFiles(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '', 'sub/c.php' => '']);
        $widget = $this->widget($project);

        $lines = $widget->render(new RenderContext(60, 12));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));

        $this->assertStringContainsString('Go to File:', $plain);
        $this->assertStringContainsString('a.php', $plain);
        foreach ($lines as $line) {
            $this->assertSame(60, AnsiUtils::visibleWidth($line));
        }
    }

    public function testLongQueryKeepsTheCaretVisible(): void
    {
        $project = new TempProject(['a.php' => '']);
        $widget = $this->widget($project);
        $this->type($widget, str_repeat('x', times: 100)); // overflow the 80-column bar

        $header = $widget->render(new RenderContext(80, 10))[0];
        $marker = AnsiUtils::cursorMarker(CursorShape::Bar);

        $this->assertSame(1, substr_count($header, $marker), 'the caret scrolls into view instead of being clipped');
        $this->assertSame(80, AnsiUtils::visibleWidth($header), 'the header stays the terminal width');
    }

    public function testFuzzyScoringPrefersBasenameAndSegmentStarts(): void
    {
        $project = new TempProject([
            'src/Controller/UserController.php' => '',
            'src/Entity/User.php' => '',
            'assets/user-avatar-tools.txt' => '',
        ]);
        $widget = $this->widget($project);

        $picked = null;
        $widget->onPick(static function (string $path) use (&$picked): void {
            $picked = $path;
        });

        $this->type($widget, 'user');
        $widget->handleInput("\r");

        $this->assertStringEndsWith('src/Entity/User.php', (string) $picked, 'shortest basename match wins');
    }

    public function testNavigationAndPick(): void
    {
        $project = new TempProject(['aa.php' => '', 'ab.php' => '']);
        $widget = $this->widget($project);
        $picked = null;
        $widget->onPick(static function (string $path) use (&$picked): void {
            $picked = $path;
        });

        $this->type($widget, 'a');
        $widget->handleInput("\x1b[B"); // down
        $widget->handleInput("\x1b[A"); // up
        $widget->handleInput("\x1b[B"); // down again
        $widget->handleInput("\r");

        $this->assertStringEndsWith('ab.php', (string) $picked);
        $this->assertFalse($widget->isOpen(), 'picking closes the palette');
    }

    public function testNoMatchesRowAndBackspace(): void
    {
        $project = new TempProject(['real.php' => '']);
        $widget = $this->widget($project);

        $this->type($widget, 'zzz');
        $lines = $widget->render(new RenderContext(50, 10));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));
        $this->assertStringContainsString('no matches', $plain);

        $widget->handleInput("\r"); // pick with no results just closes
        $this->assertFalse($widget->isOpen());

        $widget->open();
        $this->type($widget, 'z');
        $widget->handleInput("\x7f"); // backspace -> empty query again
        $widget->handleInput("\x7f"); // backspace on empty: no-op
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(50, 10))));
        $this->assertStringContainsString('real.php', $plain);
    }

    public function testEscapeClosesAndNotifies(): void
    {
        $project = new TempProject(['a.php' => '']);
        $widget = $this->widget($project);
        $closed = false;
        $widget->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        $widget->handleInput("\x1b");
        $this->assertTrue($closed);
        $this->assertFalse($widget->isOpen());
    }

    public function testOnInputHookConsumes(): void
    {
        $project = new TempProject(['a.php' => '']);
        $widget = $this->widget($project);
        $widget->onInput(static fn (string $data): bool => 'Z' === $data);

        $widget->handleInput('Z'); // consumed: query stays empty
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $widget->render(new RenderContext(50, 10))));
        $this->assertStringContainsString('a.php', $plain, 'empty query still lists files');
    }

    public function testClickResultRowPicks(): void
    {
        $project = new TempProject(['aa.php' => '', 'ab.php' => '']);
        $widget = $this->widget($project);
        $picked = null;
        $widget->onPick(static function (string $path) use (&$picked): void {
            $picked = $path;
        });
        $widget->render(new RenderContext(60, 12)); // establish geometry

        $widget->handleClick(2); // header row 0 → result rows 1,2 → index 1
        $this->assertStringEndsWith('ab.php', (string) $picked);
        $this->assertFalse($widget->isOpen(), 'clicking a result closes the palette');
    }

    public function testClickHeaderAndEmptyRowsAreNoOps(): void
    {
        $project = new TempProject(['aa.php' => '']);
        $widget = $this->widget($project);
        $picked = false;
        $widget->onPick(static function () use (&$picked): void {
            $picked = true;
        });
        $widget->render(new RenderContext(60, 12));

        $widget->handleClick(0); // header row → nothing
        $widget->handleClick(9); // beyond the visible result rows → nothing
        $widget->handleClick(5); // within rows but past the single result → nothing
        $this->assertFalse($picked);
        $this->assertTrue($widget->isOpen());
    }

    public function testHoverRowTracksPointer(): void
    {
        $project = new TempProject(['aa.php' => '', 'ab.php' => '', 'ac.php' => '']);
        $widget = $this->widget($project);
        $widget->render(new RenderContext(60, 12));

        $this->assertTrue($widget->hoverRow(2), 'row 2 → result index 1 selected');
        $this->assertFalse($widget->hoverRow(2), 'same row → no change');
        $this->assertFalse($widget->hoverRow(0), 'header row is not a result');
    }

    public function testIgnoredDirectoriesExcludedFromWalk(): void
    {
        $project = new TempProject([
            '.git/objects/xx' => '',
            'node_modules/pkg/index.js' => '',
            'src/Real.php' => '',
        ]);
        $widget = $this->widget($project);

        $this->type($widget, 'x');
        $lines = $widget->render(new RenderContext(70, 10));
        $plain = implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $lines));

        $this->assertStringNotContainsString('node_modules', $plain);
        $this->assertStringNotContainsString('index.js', $plain);
    }

    public function testWheelMovesTheSelectionThreeRowsPerNotch(): void
    {
        $project = new TempProject(['a.php' => '', 'b.php' => '', 'c.php' => '', 'd.php' => '', 'e.php' => '']);
        $widget = $this->widget($project);
        $this->type($widget, 'php'); // all five match

        $picked = null;
        $widget->onPick(static function (string $path) use (&$picked): void {
            $picked = $path;
        });

        $widget->moveSelection(3);
        $widget->moveSelection(-99); // clamps at the top
        $widget->moveSelection(3);
        $widget->handleInput("\r");
        $this->assertNotNull($picked, 'the wheel-selected row picks on Enter');
        $this->assertStringEndsWith('d.php', $picked, 'three rows down from the first');
    }
}
