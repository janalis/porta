<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\Paint;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The centered pickers, read off the composed screen.
 *
 * A picker is a box, a window into a list, and a source that decides how much
 * of the list the window is allowed to see. Those three disagree silently: the
 * box seats twelve rows, the window will fill them, and a source that hands up
 * five leaves the rest of the box empty with no error anywhere. Only the
 * rendered frame says so.
 */
final class IdePickerTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    /**
     * Go to File is a files-only Search Everywhere, and Search Everywhere caps
     * each category at five so no one source crowds the other five off the
     * list. Alone in its own picker there is nobody to crowd, and the cap was
     * costing rows the box already had: forty matches rendered five and a
     * "35 more results" row inside a frame with room for twelve.
     */
    public function testGoToFileFillsItsBoxRatherThanStoppingAtTheSharedCap(): void
    {
        $files = ['composer.json' => '{}'];
        foreach (\range(start: 1, end: 40) as $n) {
            $files[\sprintf('src/Widget/Thing%02d.php', $n)] = "<?php\n";
        }
        $project = new TempProject($files);

        $this->runIde(
            $project,
            $project->path('composer.json'),
            [
                100 => static function ($tui): void {
                    $tui->handleInput("\x10"); // ctrl+p
                    foreach (\str_split('Thing') as $ch) {
                        $tui->handleInput($ch);
                    }
                },
                400 => function (Tui $tui, VirtualTerminal $terminal): void {
                    // The eleventh row is the one the shared cap made
                    // unreachable without activating a grow row first.
                    $this->await(
                        // Stripped: the query's matched characters are marked.
                        static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes(\implode("\n", Paint::lines($tui->getById('editor'), new RenderContext(120, 30)))), 'Thing11.php'),
                        what: 'the picker to fill its box',
                    );

                    $grid = $this->screenGrid($tui, $terminal);
                    $shown = \count(\array_filter($grid, static fn (string $row): bool => \str_contains($row, '.php    src/Widget')));

                    $this->assertGreaterThan(5, $shown, 'past the shared per-category cap');
                    $this->assertSame(11, $shown, 'the box seats twelve rows: a group header and eleven hits');
                },
            ],
            columns: 120,
            rows: 30,
        );
    }

    /**
     * Go to File is the IDE's own key, as PhpStorm's is: from the file tree
     * too, not only from the editor. It used to be the editor's alone, and
     * Ctrl+P with the tree focused did nothing at all.
     */
    public function testGoToFileOpensFromTheTree(): void
    {
        $project = new TempProject(['composer.json' => '{}', 'src/Thing.php' => "<?php\n"]);

        $this->runIde($project, $project->path('composer.json'), [
            100 => function (Tui $tui): void {
                $tui->setFocus($tui->getById('tree'));
                $this->assertSame('tree', $tui->getFocus()?->getId(), 'the premise: the tree has the keys');
                $tui->handleInput("\x10"); // ctrl+p
            },
            300 => function (Tui $tui): void {
                $this->await(static fn (): bool => Widgets::editor($tui)->isAnyPickerOpen(), what: 'Go to File to open from the tree');
                $this->assertTrue(Widgets::editor($tui)->isAnyPickerOpen());
            },
        ]);
    }
}
