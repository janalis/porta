<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Boots a real Ide and drives a terminal resize through the live render loop:
 * the file-tree sidebar auto-collapses below the responsive threshold and
 * restores — with the user's tree width intact — when the terminal widens.
 *
 * A resize is reconciled in a loop callback, not in the keystroke, so each
 * stage awaits the layout it expects rather than reading it out of a gap. The
 * await forces a frame per turn: the pane list is a product of the LAYOUT, and
 * a bare tick inside the 60fps cap re-lays nothing out.
 */
final class IdeResponsiveTest extends TestCase
{
    use RunsIde;
    use ScreenReads;

    /** @return list<string> */
    private function mainIds(Tui $tui): array
    {
        return \array_map(static fn (AbstractWidget $w): string => $w->getId(), $tui->getById('main')->all());
    }

    /**
     * The barrier AND the claim: await the pane list, then assert it.
     *
     * @param list<string> $expected
     */
    private function awaitMainIds(Tui $tui, array $expected, string $what): void
    {
        $this->await(
            function () use ($tui, $expected): bool {
                $this->frame($tui);

                return $expected === $this->mainIds($tui);
            },
            what: $what,
        );
        $this->assertSame($expected, $this->mainIds($tui), $what);
    }

    public function testSidebarAutoCollapsesInNarrowTerminalsAndRestores(): void
    {
        $project = new TempProject(['src/Demo.php' => "<?php\n"]);

        $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitMainIds($tui, ['tree', 'separator', 'editor-pane'], 'wide boot shows the sidebar');

                    $terminal->simulateResize(50, 30);
                    $tui->requestRender();
                },
                600 => function (Tui $tui, VirtualTerminal $terminal): void {
                    $this->awaitMainIds($tui, ['editor-pane'], 'narrow: the sidebar collapsed');

                    $terminal->simulateResize(100, 30);
                    $tui->requestRender();
                },
                900 => function (Tui $tui): void {
                    $this->awaitMainIds($tui, ['tree', 'separator', 'editor-pane'], 'wide again: the sidebar restored');
                },
            ],
            columns: 100,
            rows: 30,
        );
    }

    /**
     * A roomy screen gives the workspace an edge row under the menu bar and
     * one above the status bar, each holding its bar's half; one row shorter
     * than the threshold, both go and the editor gets their rows back.
     */
    public function testTheEdgeRulesComeAndGoWithTheScreensDensity(): void
    {
        $project = new TempProject(['src/Demo.php' => "<?php\n"]);
        $editorRows = static fn (Tui $tui, Ide $ide): int => Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('editor'))->rows ?? 0;

        $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($editorRows, &$roomy): void {
                    $this->frame($tui);
                    $grid = $this->screenGrid($tui, $terminal);
                    $divider = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('separator'))->col ?? -1;
                    $this->assertMatchesRegularExpression('/^▀+▄[▀▄]+$/u', \rtrim($grid[1]), 'the row under the menu bar: the bar\'s half, and the shown tab\'s upper half under it');
                    $this->assertSame('▄', \mb_substr($grid[1], $divider, length: 1), 'the divider opens beside the shown first tab: its join is the tab\'s half');
                    $this->assertSame(' ', \mb_substr($grid[2], $divider, length: 1), 'and its cell on the tab\'s row a blank of the tab\'s');
                    $this->assertSame('│', \mb_substr($grid[4], $divider, length: 1), 'running on under the strip');
                    $this->assertMatchesRegularExpression('/^▄+$/u', \rtrim($grid[28]), 'and the one above the status bar, its bar\'s half all the way');
                    $this->assertSame('▄', \mb_substr($grid[28], $divider, length: 1), 'the divider ends at the bar: no join');
                    $roomy = $editorRows($tui, $ide);

                    $terminal->simulateResize(100, 29);
                    $tui->requestRender();
                },
                600 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($editorRows, &$roomy): void {
                    $this->await(function () use ($tui, $ide, $editorRows, $roomy): bool {
                        $this->frame($tui);

                        return $editorRows($tui, $ide) > ($roomy - 1);
                    }, what: 'the compact frame');
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertStringNotContainsString('▀▀▀', $grid[1], 'no edge row under the menu bar');
                    $this->assertSame($roomy - 1 + 4, $editorRows($tui, $ide), 'one row fewer on screen, yet two rules, the strip\'s baseline and the rule under the navigation bar given back');
                },
            ],
            columns: 100,
            rows: 30,
        );
    }

    /**
     * A dropdown over the edge row is left alone: the File menu hangs across
     * the menu bar's half over the divider, and its own border shows there —
     * the bar's half comes back when the menu closes. What is pinned is the
     * screen; the skip of covered cells that also guards a join is pinned in
     * FrameJoinsTest, with a light border.
     */
    public function testADropdownOverAJoinKeepsItsBorder(): void
    {
        $project = new TempProject(['src/Demo.php' => "<?php\n", 'src/Other.php' => "<?php\n"]);
        // The top rule's row: the menu bar is row 0.
        $at = static fn (array $grid, int $col): string => \mb_substr($grid[1], $col, length: 1);

        $this->runIde(
            $project,
            $project->path('src/Demo.php'),
            [
                300 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $at, &$divider): void {
                    // A second tab shown, so the first is at rest and the divider stays shut under the bar.
                    Services::resolve($ide, BufferService::class)->openFile($project->path('src/Other.php'), OpenFocus::Editor);
                    $this->frame($tui);
                    $divider = Services::resolve($ide, Renderer::class)->getWidgetRect($tui->getById('separator'))->col ?? -1;
                    $this->assertSame('▀', $at($this->screenGrid($tui, $terminal), $divider));

                    $tui->handleInput("\x1b[21~"); // F10 arms the bar
                    $tui->handleInput("\r"); // and drops File
                },
                500 => function (Tui $tui, VirtualTerminal $terminal) use ($at, &$divider): void {
                    $this->awaitPainted($tui, $terminal, 'Open Project');
                    $grid = $this->screenGrid($tui, $terminal);
                    $this->assertSame('━', $at($grid, $divider), 'the dropdown\'s top border, not the bar\'s half');

                    $tui->handleInput("\x1b");
                    $tui->handleInput("\x1b");
                },
                700 => function (Tui $tui, VirtualTerminal $terminal) use ($at, &$divider): void {
                    $this->await(fn (): bool => '▀' === $at($this->screenGrid($tui, $terminal), $divider), what: 'the bar\'s half back once the menu closed');
                    $this->assertSame('▀', $at($this->screenGrid($tui, $terminal), $divider));
                },
            ],
            columns: 100,
            rows: 30,
        );
    }
}
