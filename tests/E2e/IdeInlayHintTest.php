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

    /** A left press and release at a 0-based screen cell. */
    private static function click(Tui $tui, int $row, int $col): void
    {
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col + 1, $row + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col + 1, $row + 1));
    }
}
