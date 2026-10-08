<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\InlayHint;
use Porta\Editor\InlayHintKind;
use Porta\Ide;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * Inlay hints end to end: the pointer lands on the character the screen
 * shows when a hint has moved the text right of it.
 */
final class IdeInlayHintTest extends TestCase
{
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    private function project(): TempProject
    {
        \putenv('PATH=/nonexistent');

        return new TempProject(['call.php' => "<?php\nf(42, \$y);\n"]);
    }

    /** `n: ` before the 4 of line 1, drawn into the line. */
    private static function hint(Tui $tui): void
    {
        Widgets::editor($tui)->decorations->setInlayHints([1 => [new InlayHint(2, 'n:', InlayHintKind::Parameter, padRight: true)]]);
    }

    /** @return array{int, int} the 0-based screen cell of line 1's first text column */
    private function lineOne(Ide $ide, CodeEditorWidget $editor): array
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($editor);
        $this->assertInstanceOf(WidgetRect::class, $rect);

        return [$rect->row + 1, $rect->col + $editor->gutterWidth()];
    }

    public function testAClickLandsOnTheCharacterDrawnUnderThePointer(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('call.php'),
            [
                100 => self::hint(...),
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'f(n: 42, $y);');
                    $editor = Widgets::editor($tui);
                    [$row, $col] = $this->lineOne($ide, $editor);

                    self::click($tui, $row, $col + 6); // the 2 of 42
                    $this->assertSame([1, 3], [$editor->getDocument()->cursorLine, $editor->getDocument()->cursorCol], 'right of the hint: the character drawn there');

                    self::click($tui, $row, $col + 3); // on the hint
                    $this->assertSame(2, $editor->getDocument()->cursorCol, 'on the hint: the character it sits before');
                },
            ],
        );
    }

    public function testADragAcrossAHintSelectsExactlyTheText(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('call.php'),
            [
                100 => self::hint(...),
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'f(n: 42, $y);');
                    $editor = Widgets::editor($tui);
                    [$row, $col] = $this->lineOne($ide, $editor);

                    // From the ( to the comma after 42, across the hint.
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 2, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<32;%d;%dM", $col + 8, $row + 1));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 8, $row + 1));

                    $this->assertSame('(42', $editor->getDocument()->getSelectedText());
                },
            ],
        );
    }

    /**
     * PhpStorm's way to turn a kind of hint off: right-click it. The menu is
     * the hint's own — the caret does not move — and its first verb hides
     * every parameter name and says so in the project's settings.
     */
    public function testARightClickOnAHintHidesItsKind(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('call.php'),
            [
                100 => self::hint(...),
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->awaitPainted($tui, $terminal, 'f(n: 42, $y);');
                    $editor = Widgets::editor($tui);
                    [$row, $col] = $this->lineOne($ide, $editor);
                    $editor->getDocument()->setCursor(0, 0);

                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 4, $row + 1)); // right-press on the hint
                    $this->awaitPainted($tui, $terminal, 'Hide Parameter Name Hints');
                    $this->assertSame([0, 0], [$editor->getDocument()->cursorLine, $editor->getDocument()->cursorCol], 'the caret stays where it was');

                    $tui->handleInput("\r"); // the first item
                    $this->awaitPainted($tui, $terminal, 'f(42, $y);');
                    $written = \json_decode((string) \file_get_contents($project->path('.porta.json')), associative: true, flags: \JSON_THROW_ON_ERROR);
                    $this->assertSame('none', $written['settings']['inlayParameterHints'] ?? null, 'and the project remembers');
                },
            ],
        );
    }

    /** Each kind of hint offers what can hide it: its own setting, then the mode. */
    public function testAHintsMenuOffersWhatHidesItsKind(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('call.php'),
            [
                // `f(42, $y: Y);` then ` 3 uses` — a type and a hint with no kind.
                100 => static fn (Tui $tui) => Widgets::editor($tui)->decorations->setInlayHints([1 => [
                    new InlayHint(8, ': Y', InlayHintKind::Type),
                    new InlayHint(10, '3 uses', padLeft: true),
                ]]),
                200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'f(42, $y: Y); 3 uses');
                    [$row, $col] = $this->lineOne($ide, Widgets::editor($tui));

                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 9, $row + 1)); // on `: Y`
                    $this->awaitPainted($tui, $terminal, 'Hide Type Hints');
                    $this->assertFalse($this->onScreen($tui, $terminal, 'Hide Parameter Name Hints'), 'not a parameter name');
                    $tui->handleInput("\x1b");

                    $tui->handleInput(\sprintf("\x1b[<2;%d;%dM", $col + 16, $row + 1)); // on `3 uses`
                    $this->awaitPainted($tui, $terminal, 'Hide All Inlay Hints');
                    $this->assertFalse($this->onScreen($tui, $terminal, 'Hide Type Hints'), 'a hint with no kind has only the mode to turn off');
                },
            ],
        );
    }

    private function onScreen(Tui $tui, VirtualTerminal $terminal, string $needle): bool
    {
        return null !== $this->rowOf($this->screenGrid($tui, $terminal), $needle);
    }

    /** A left press and release at a 0-based screen cell. */
    private static function click(Tui $tui, int $row, int $col): void
    {
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));
    }
}
