<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
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
     * A roomy screen rules the workspace off under the menu bar and above the
     * status bar; one row shorter than the threshold, both rules go and the
     * editor gets their rows back.
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
                    $this->assertMatchesRegularExpression('/^─+┬─+$/u', \rtrim($grid[1]), 'the rule under the menu bar, turning down into the divider');
                    $this->assertSame('┬', \mb_substr($grid[1], $divider, length: 1), 'at the divider\'s own column');
                    $this->assertSame('│', \mb_substr($grid[2], $divider, length: 1));
                    $this->assertMatchesRegularExpression('/^─+┴─+$/u', \rtrim($grid[28]), 'and the one above the status bar, turning up into it');
                    $this->assertSame('┴', \mb_substr($grid[28], $divider, length: 1));
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
                    $this->assertStringNotContainsString('───', $grid[1], 'no rule under the menu bar');
                    $this->assertSame($roomy - 1 + 3, $editorRows($tui, $ide), 'one row fewer on screen, yet two rules and the strip\'s lid given back');
                },
            ],
            columns: 100,
            rows: 30,
        );
    }
}
