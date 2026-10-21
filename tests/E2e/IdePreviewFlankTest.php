<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\EditorPanes;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestPane;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\EditorArea;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * What core does for a preview with a flank, a pane it has never heard of —
 * the merge view's shape, ours | result | theirs: the flank seated on the
 * editor's other side, a press and a wheel notch over it reaching it, and the
 * focus ring walking it as it sits.
 */
final class IdePreviewFlankTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    /** A project plugin previewing `.flank` files in a {@see TestPane} with a flank of its own. */
    private function project(): TempProject
    {
        \putenv('PATH=/nonexistent');
        $project = new TempProject(['doc.flank' => "one\ntwo\n"]);
        $dir = $project->path('.porta/plugins/flanked');
        \mkdir($dir, permissions: 0o777, recursive: true);
        \file_put_contents($dir.'/plugin.php', data: <<<'PHP'
            <?php

            declare(strict_types=1);

            use Porta\Editor\PaneServices;
            use Porta\Plugin\Api\AbstractPlugin;
            use Porta\Plugin\Api\PluginApi;
            use Porta\Tests\Support\TestPane;
            use Porta\Widget\EditorPaneWidget;

            return new class('flanked') extends AbstractPlugin {
                public function activate(PluginApi $api): void
                {
                    $api->registerPreview('theirs', ['flank']);
                    $api->registerPane(
                        'theirs',
                        static fn (PaneServices $services): EditorPaneWidget => new TestPane('theirs', new TestPane('ours')),
                        static fn (string $path): string => (string) file_get_contents($path),
                    );
                }
            };
            PHP);

        return $project;
    }

    private static function pane(Ide $ide): TestPane
    {
        $pane = Services::resolve($ide, EditorPanes::class)->paneFor('theirs');
        self::assertInstanceOf(TestPane::class, $pane);

        return $pane;
    }

    private static function flank(Ide $ide): TestPane
    {
        $flank = self::pane($ide)->flank();
        self::assertInstanceOf(TestPane::class, $flank);

        return $flank;
    }

    private static function rect(Ide $ide, object $widget): WidgetRect
    {
        $rect = Services::resolve($ide, Renderer::class)->getWidgetRect($widget);
        self::assertInstanceOf(WidgetRect::class, $rect);

        return $rect;
    }

    public function testTheFlankSitsBesideTheEditorAndThePointerAndTheKeysReachIt(): void
    {
        $project = $this->project();

        $this->runIde(
            $project,
            $project->path('doc.flank'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide): void {
                    $this->awaitPainted($tui, $terminal, 'showing doc.flank');
                    $editor = Services::resolve($ide, CodeEditorWidget::class);
                    $flank = self::flank($ide);
                    $pane = self::pane($ide);
                    $this->assertLessThan(self::rect($ide, $editor)->col, self::rect($ide, $flank)->col, 'the flank on the editor\'s other side');
                    $this->assertLessThan(self::rect($ide, $pane)->col, self::rect($ide, $editor)->col);
                    $this->assertSame([$flank, $editor, $pane], Services::resolve($ide, EditorArea::class)->members(), 'the focus ring walks them as they sit');

                    // SGR cells are 1-based: the flank's cell (1, 3) is this.
                    $rect = self::rect($ide, $flank);
                    [$col, $row] = [$rect->col + 4, $rect->row + 2];
                    $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $col, $row));
                    $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $col, $row));
                    $this->assertSame(
                        ['scroll:0,6', 'press:1,3', 'select:1,3'],
                        \array_values(\array_filter($flank->calls, static fn (string $call): bool => !\str_starts_with($call, 'theme'))),
                        'a notch and a press over the flank are the flank\'s',
                    );
                    $this->assertSame($flank, $tui->getFocus(), 'and the press gave it the keys');
                    $this->assertSame($flank, Services::resolve($ide, EditorArea::class)->widget(), 'so it is the side the status bar and the tab lid speak for');
                    $this->assertSame(
                        [],
                        \array_values(\array_filter($pane->calls, static fn (string $call): bool => \str_starts_with($call, 'press') || \str_starts_with($call, 'scroll'))),
                        'none of it reached the pane',
                    );

                    // Too narrow for three: the flank steps aside, and the
                    // pointer no longer finds it where it was.
                    $flank->calls = [];
                    $terminal->simulateResize(84, 24);
                    $this->await(static fn (): bool => null === $flank->getContext(), what: 'the flank stepping aside');
                    $tui->handleInput(\sprintf("\x1b[<65;%d;%dM", $col, $row));
                    $this->assertSame([], $flank->calls, 'nothing reaches a flank off screen');
                    $this->assertNotSame($flank, $tui->getFocus());
                },
            ],
        );
    }

    public function testAnotherFileTakesTheKeysTheFlankHad(): void
    {
        $project = $this->project();
        \file_put_contents($project->path('a.php'), data: "<?php\n");

        $this->runIde(
            $project,
            $project->path('doc.flank'),
            [
                0 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->awaitPainted($tui, $terminal, 'showing doc.flank');
                    $tui->setFocus(self::flank($ide));

                    Services::resolve($ide, BufferService::class)->openFile($project->path('a.php'), OpenFocus::Keep);

                    $this->assertNull(self::flank($ide)->getContext(), 'the flank left with its pane');
                    $this->assertSame(Services::resolve($ide, CodeEditorWidget::class), $tui->getFocus(), 'and the keys went to the editor, not with it');
                },
            ],
        );
    }
}
