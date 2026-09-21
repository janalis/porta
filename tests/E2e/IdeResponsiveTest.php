<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
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
}
