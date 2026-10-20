<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\Merge\MergePaneWidget;
use Porta\Editor\EditorPanes;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The merge view as the user meets it: a conflicted file opens as ours |
 * result | theirs, a click on a side's label keeps it as one undo step, a key
 * from a side does the same, and the status says how many are left.
 */
final class MergeViewE2eTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    private const string CONFLICTED = "<?php\n<<<<<<< HEAD\n\$a = 'ours';\n||||||| base\n\$a = 'base';\n=======\n\$a = 'theirs';\n>>>>>>> topic\n\$b = 1;\n<<<<<<< HEAD\n\$c = 'ours';\n=======\n\$c = 'theirs';\n>>>>>>> topic\n";

    private function project(): TempProject
    {
        \putenv('PATH=/nonexistent');

        return new TempProject(['m.php' => self::CONFLICTED, '.porta.json' => '{"enabledPlugins":["diff"]}']);
    }

    private static function theirs(Ide $ide): MergePaneWidget
    {
        $pane = Services::resolve($ide, EditorPanes::class)->paneFor(MergePaneWidget::PANE_ID);
        self::assertInstanceOf(MergePaneWidget::class, $pane);

        return $pane;
    }

    private static function ours(Ide $ide): MergePaneWidget
    {
        $flank = self::theirs($ide)->flank();
        self::assertInstanceOf(MergePaneWidget::class, $flank);

        return $flank;
    }

    private static function rect(Ide $ide, object $widget): WidgetRect
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($widget);
        self::assertInstanceOf(WidgetRect::class, $rect);

        return $rect;
    }

    /** A left click on the widget's cell ($row, $col), 0-based within it. */
    private static function click(Tui $tui, Ide $ide, object $widget, int $row, int $col): void
    {
        $rect = self::rect($ide, $widget);
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 1, $rect->row + $row + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $col + 1, $rect->row + $row + 1));
    }

    private static function text(Ide $ide): string
    {
        return Services::resolve($ide, CodeEditorWidget::class)->getDocument()->getText();
    }

    public function testAConflictedFileOpensAsOursResultAndTheirs(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('m.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, '⇤ topic');
                    $grid = \implode("\n", $this->screenGrid($tui, $terminal));
                    $this->assertStringContainsString('⇥ HEAD', $grid, 'ours, labelled by the opener');
                    $this->assertStringContainsString('<<<<<<< HEAD', $grid, 'the result in the editor, markers and all');
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $this->assertLessThan(self::rect($ide, $editor)->col, self::rect($ide, self::ours($ide))->col, 'ours on the left');
                    $this->assertLessThan(self::rect($ide, self::theirs($ide))->col, self::rect($ide, $editor)->col, 'theirs on the right');
                    $this->assertSame('Conflict 1 of 2  a keep theirs  b both  n/p next/prev', self::theirs($ide)->statusRight());
                    $this->assertSame('Conflict 1 of 2  a keep ours  b both  n/p next/prev', self::ours($ide)->statusRight());
                },
            ],
        );
    }

    public function testAClickOnASidesLabelKeepsItAsOneUndoStep(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('m.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, '⇤ topic');

                    self::click($tui, $ide, self::theirs($ide), 1, 2); // the first conflict's label
                    $this->assertSame(
                        "<?php\n\$a = 'theirs';\n\$b = 1;\n<<<<<<< HEAD\n\$c = 'ours';\n=======\n\$c = 'theirs';\n>>>>>>> topic\n",
                        self::text($ide),
                        'theirs kept, the base gone with the markers',
                    );

                    $tui->setFocus(Services::resolve($ide, CodeEditorWidget::class));
                    $tui->handleInput("\x1a"); // Ctrl+Z
                    $this->assertSame(self::CONFLICTED, self::text($ide), 'one undo puts the conflict back');
                },
            ],
        );
    }

    public function testKeysFromASideKeepItOrBothAndWalkTheConflicts(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('m.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, '⇥ HEAD');
                    $ours = self::ours($ide);
                    $tui->setFocus($ours);

                    $tui->handleInput('n');
                    $this->assertSame(1, Services::resolve($ide, CodeEditorWidget::class)->getDocument()->cursorLine, 'n: the first conflict below the caret');
                    $tui->handleInput('n');
                    $this->assertSame(9, Services::resolve($ide, CodeEditorWidget::class)->getDocument()->cursorLine, 'n again: the next one');
                    $tui->handleInput('a');
                    $this->assertSame(
                        "<?php\n<<<<<<< HEAD\n\$a = 'ours';\n||||||| base\n\$a = 'base';\n=======\n\$a = 'theirs';\n>>>>>>> topic\n\$b = 1;\n\$c = 'ours';\n",
                        self::text($ide),
                        'a: ours kept for the conflict the caret is on',
                    );

                    $tui->handleInput('p');
                    $this->assertSame(1, Services::resolve($ide, CodeEditorWidget::class)->getDocument()->cursorLine, 'p: back to the first');
                    $tui->handleInput('b');
                    $this->assertSame("<?php\n\$a = 'ours';\n\$a = 'theirs';\n\$b = 1;\n\$c = 'ours';\n", self::text($ide), 'b: both');
                    $this->await(static fn (): bool => 'No conflicts left — save the result' === $ours->statusRight(), what: 'the status once none is left');
                },
            ],
        );
    }

    public function testANotchOverASideScrollsAllThreeAsOne(): void
    {
        \putenv('PATH=/nonexistent');
        $tail = \implode("\n", \array_map(static fn (int $n): string => \sprintf('$x%d = %d;', $n, $n), \range(1, end: 60)));
        $project = new TempProject(['m.php' => self::CONFLICTED.$tail."\n", '.porta.json' => '{"enabledPlugins":["diff"]}']);

        $this->runIde(
            $project,
            $project->path('m.php'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, '⇥ HEAD');
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $ours = self::rect($ide, self::ours($ide));

                    foreach (\range(1, end: 5) as $notch) { // five notches down over ours, past the conflicts
                        $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $ours->col + 3, $ours->row + 3));
                    }

                    $top = $editor->viewport()->vScroll;
                    $this->assertGreaterThan(0, $top, 'the editor scrolled');
                    $this->assertSame(0, $editor->getDocument()->cursorLine, 'its caret did not move');
                    $this->assertGreaterThan(14, $top, 'past both conflicts');
                    $first = \sprintf('$x%d = %d;', $top - 13, $top - 13); // line 14 is $x1
                    $this->await(fn (): bool => \str_starts_with(
                        \trim(\mb_substr($this->screenGrid($tui, $terminal)[$ours->row], $ours->col, $ours->columns)),
                        $first,
                    ), what: 'ours drawn from the editor\'s new top line');
                },
            ],
        );
    }
}
