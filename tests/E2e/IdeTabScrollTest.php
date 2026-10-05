<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Widgets;
use Porta\Widget\TabBarWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Render\WidgetRect;
use Symfony\Component\Tui\Tui;

/**
 * Drives the scrollable tab strip through a real Ide: with more open tabs than
 * fit, a mouse wheel over the strip pages the window (horizontally on the top
 * strip, vertically on a left strip), and under an RTL locale the horizontal
 * wheel direction follows the mirrored on-screen order.
 */
final class IdeTabScrollTest extends TestCase
{
    use RunsIde;

    private const int FILES = 10;

    /** More tabs than the 24-row terminal has vertical list rows. */
    private const int TALL_FILES = 30;

    /** @return array<string, string> */
    private static function files(int $count): array
    {
        $files = [];
        for ($i = 0; $i < $count; ++$i) {
            $files[\sprintf('chapter-file-%02d.php', $i)] = "<?php\n";
        }

        return $files;
    }

    private static function openAll(mixed $ide, TempProject $project, int $count): void
    {
        /** @var BufferService $buffers */
        $buffers = Services::resolve($ide, BufferService::class);
        for ($i = 0; $i < $count; ++$i) {
            $buffers->openFile($project->path(\sprintf('chapter-file-%02d.php', $i)), OpenFocus::Keep);
        }
    }

    private static function tabsRect(mixed $ide, Tui $tui): WidgetRect
    {
        $rect = Services::resolve($ide, Renderer::class)
            ->getWidgetRect($tui->getById('tabs'));
        Assert::assertInstanceOf(WidgetRect::class, $rect, 'the strip rendered, so its rect exists');

        return $rect;
    }

    /** An SGR wheel event over the middle of the strip (1-based coords on the wire). */
    private static function wheel(Tui $tui, WidgetRect $rect, int $code, int $times = 1): void
    {
        for ($i = 0; $i < $times; ++$i) {
            $tui->handleInput(\sprintf("\x1b[<%d;%d;%dM", $code, $rect->col + 3, $rect->row + 1));
        }
    }

    /** A left click (press + release) at a widget-relative cell of the strip. */
    private static function click(Tui $tui, WidgetRect $rect, int $row, int $col): void
    {
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dM", $rect->col + $col + 1, $rect->row + $row + 1));
        $tui->handleInput(\sprintf("\x1b[<0;%d;%dm", $rect->col + $col + 1, $rect->row + $row + 1));
    }

    public function testWheelScrollsTheHorizontalStripAndRtlFlipsTheDirection(): void
    {
        $project = new TempProject(
            self::files(self::FILES)
            + [
                '.porta.json' => (string) \json_encode(['settings' => ['language' => 'he']]),
            ],
        );

        $this->runIde($project, null, [
            100 => static function (Tui $tui, $terminal, $ide) use ($project): void {
                self::openAll($ide, $project, self::FILES);
            },
            300 => function (Tui $tui, $terminal, $ide): void {
                $this->frame($tui);
                /** @var TabBarWidget $tabs */
                $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                $this->assertNotNull($tabs->tabAnchor(self::FILES - 1), 'the strip followed the last-opened (active) tab');
                $this->assertNull($tabs->tabAnchor(0), 'the first tab is scrolled out at 160 cols with 10 wide tabs');

                // Vertical wheel up pages back one tab per notch.
                self::wheel($tui, self::tabsRect($ide, $tui), 64, times: self::FILES);
                $this->frame($tui);
                $this->assertNotNull($tabs->tabAnchor(0), 'wheel-up walked back to the first tab');

                self::wheel($tui, self::tabsRect($ide, $tui), 65, times: 3);
                $this->frame($tui);
                $this->assertNull($tabs->tabAnchor(0), 'wheel-down pages forward again');

                // Hebrew locale: the strip renders mirrored, so the horizontal
                // wheel's visual direction flips — wheel-right (67) pages back
                // toward the first tab instead of forward.
                self::wheel($tui, self::tabsRect($ide, $tui), 67, times: 3);
                $this->frame($tui);
                $this->assertNotNull($tabs->tabAnchor(0), 'RTL: wheel-right follows the mirrored order');
            },
        ]);
    }

    public function testWheelScrollsTheVerticalList(): void
    {
        $project = new TempProject(
            self::files(self::TALL_FILES)
            + [
                '.porta.json' => (string) \json_encode(['settings' => ['tabBarPosition' => 'left']]),
            ],
        );

        $this->runIde($project, null, [
            100 => static function (Tui $tui, $terminal, $ide) use ($project): void {
                self::openAll($ide, $project, self::TALL_FILES);
            },
            300 => function (Tui $tui, $terminal, $ide): void {
                $this->frame($tui);
                /** @var TabBarWidget $tabs */
                $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                $this->assertNotNull($tabs->tabAnchor(self::TALL_FILES - 1), 'the list followed the active tab');
                $this->assertNull($tabs->tabAnchor(0), 'the first tab is scrolled out above');

                // One notch scrolls three rows on the vertical list.
                self::wheel($tui, self::tabsRect($ide, $tui), 64, times: 4);
                $this->frame($tui);
                $this->assertNotNull($tabs->tabAnchor(0), 'wheel-up reached the first tab');
                $this->assertNull($tabs->tabAnchor(self::TALL_FILES - 1), 'the last tab scrolled out below');

                // The bottom ▼ indicator row is clickable and pages forward.
                $rect = self::tabsRect($ide, $tui);
                self::click($tui, $rect, $rect->rows - 1, 2);
                $this->frame($tui);
                $this->assertNull($tabs->tabAnchor(0), 'clicking ▼ scrolled the first tab out');
                $this->assertNotNull($tabs->tabAnchor(3), 'clicking ▼ paged three rows forward');
            },
        ]);
    }

    /**
     * On a roomy screen a side tab is three rows, so a notch — three rows —
     * moves the list one folder tab, not three: the same distance on screen
     * as the one-row list's.
     */
    public function testAWheelNotchMovesTheSideTabsOneTab(): void
    {
        $project = new TempProject(
            self::files(self::TALL_FILES)
            + [
                '.porta.json' => (string) \json_encode(['settings' => ['tabBarPosition' => 'left']]),
            ],
        );

        $this->runIde(
            $project,
            null,
            [
                100 => static function (Tui $tui, $terminal, $ide) use ($project): void {
                    self::openAll($ide, $project, self::TALL_FILES);
                },
                300 => function (Tui $tui, $terminal, $ide): void {
                    $this->frame($tui);
                    /** @var TabBarWidget $tabs */
                    $tabs = Widgets::byId($tui, 'tabs', TabBarWidget::class);
                    $this->assertSame(1, $tabs->tabAnchor(self::TALL_FILES - 1)['rows'] ?? null, 'forty rows: a tab is a row, with a gap under it');
                    $top = static fn (): ?int => $tabs->tabIndexAt(1, 3); // the first tab under the ▲ row

                    $before = $top();
                    self::wheel($tui, self::tabsRect($ide, $tui), 64);
                    $this->frame($tui);
                    $this->assertSame($before - 1, $top(), 'one notch up, one tab');
                },
            ],
            rows: 40,
        );
    }
}
