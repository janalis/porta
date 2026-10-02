<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Editor\TabSide;
use Porta\Tests\Support\BootsIde;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\Toasts;
use Symfony\Component\Tui\Tui;

/**
 * PhpStorm's Close to the Left / Close to the Right.
 *
 * The side is the TAB STRIP's order, not the order the tabs were visited in,
 * which is the whole point of the verb — it clears the run you can see beside
 * this one. Every assertion names the surviving paths in order, since a verb
 * that closes the right COUNT from the wrong end still closes the right count.
 */
final class CloseTabsToTheSideTest extends TestCase
{
    use BootsIde;

    #[\Override]
    protected function setUp(): void
    {
        $this->bootIde([
            'composer.json' => '{}',
            'a.php' => "<?php\n// a\n",
            'b.php' => "<?php\n// b\n",
            'c.php' => "<?php\n// c\n",
            'd.php' => "<?php\n// d\n",
        ]);
    }

    /** Opens a.php … d.php in that order and returns the buffer service. */
    private function withFourTabs(): BufferService
    {
        $buffers = Services::resolve($this->ide, BufferService::class);
        foreach (['a.php', 'b.php', 'c.php', 'd.php'] as $name) {
            $buffers->openFile($this->project->path($name), focus: OpenFocus::Editor);
        }

        return $buffers;
    }

    /**
     * The path a tab was actually OPENED with, which is what the tab strip
     * hands the menu — not the same string as the project's own path when the
     * root is a symlink, as macOS temp dirs are.
     */
    private function openedPath(string $name): string
    {
        foreach (Services::resolve($this->ide, BufferManager::class)->all() as $buffer) {
            if (\basename($buffer->path) === $name) {
                return $buffer->path;
            }
        }

        return $this->project->path($name);
    }

    /** @return list<string> the open tabs, in strip order */
    private function tabs(): array
    {
        return \array_map(
            static fn (object $buffer): string => \basename($buffer->path),
            Services::resolve($this->ide, BufferManager::class)->all(),
        );
    }

    public function testCloseToTheRightClearsOnlyWhatFollowsInTheStrip(): void
    {
        $buffers = $this->withFourTabs();

        $buffers->closeTabsToTheSide($this->openedPath('b.php'), TabSide::Right);

        $this->assertSame(['a.php', 'b.php'], $this->tabs());
    }

    public function testCloseToTheLeftClearsOnlyWhatPrecedesIt(): void
    {
        $buffers = $this->withFourTabs();

        $buffers->closeTabsToTheSide($this->openedPath('c.php'), TabSide::Left);

        $this->assertSame(['c.php', 'd.php'], $this->tabs());
    }

    public function testTheClickedTabSurvivesAndBecomesTheActiveOne(): void
    {
        // Closing beside a tab you are not on must not leave you looking at a
        // buffer that is no longer open.
        $buffers = $this->withFourTabs();

        $buffers->closeTabsToTheSide($this->openedPath('a.php'), TabSide::Right);

        $this->assertSame(['a.php'], $this->tabs());
        $this->assertSame('a.php', \basename((string) Services::resolve($this->ide, BufferManager::class)->active()?->path));
    }

    public function testClosingLeftOfTheFIRSTTabClosesNothing(): void
    {
        $buffers = $this->withFourTabs();

        $buffers->closeTabsToTheSide($this->openedPath('a.php'), TabSide::Left);

        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php'], $this->tabs());
    }

    public function testAPathThatIsNotOpenClosesNothingAtAll(): void
    {
        // A stale menu closure naming a tab that has since gone must not be
        // read as "no index, so close everything".
        $buffers = $this->withFourTabs();

        $buffers->closeTabsToTheSide($this->project->path('composer.json'), TabSide::Right);

        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php'], $this->tabs());
    }

    public function testAPinnedTabSurvivesEveryBulkClose(): void
    {
        // The whole point of pinning: the tabs you deliberately keep are
        // exactly the ones a careless "close the rest" would take first.
        $buffers = $this->withFourTabs();
        $buffers->togglePinned($this->openedPath('b.php'));

        $buffers->closeTabsToTheSide($this->openedPath('a.php'), TabSide::Right);
        $this->assertSame(['a.php', 'b.php'], $this->tabs(), 'close to the right spares it');

        $buffers->closeTabsExcept(null);
        $this->assertSame(['b.php'], $this->tabs(), 'and so does close all');
    }

    public function testPinningAsksForARepaint(): void
    {
        // The glyph appears in the tab strip; without a frame it would not show
        // until something else happened to redraw.
        $buffers = $this->withFourTabs();
        $tui = Services::resolve($this->ide, Tui::class);
        Probe::set($tui, 'renderRequested', false);

        $buffers->togglePinned($this->openedPath('b.php'));

        $this->assertTrue((bool) Probe::get($tui, 'renderRequested'));
    }

    public function testPinningIsAToggleAndReportsItsState(): void
    {
        $buffers = $this->withFourTabs();
        $path = $this->openedPath('c.php');

        $this->assertFalse($buffers->isPinned($path));

        $buffers->togglePinned($path);
        $this->assertTrue($buffers->isPinned($path));

        $buffers->togglePinned($path);
        $this->assertFalse($buffers->isPinned($path));
    }

    public function testPinningAPathThatIsNotOpenDoesNothing(): void
    {
        // A stale menu closure naming a tab that has since gone.
        $buffers = $this->withFourTabs();

        $buffers->togglePinned($this->project->path('composer.json'));

        $this->assertFalse($buffers->isPinned($this->project->path('composer.json')));
        $this->assertSame(['a.php', 'b.php', 'c.php', 'd.php'], $this->tabs());
    }

    public function testAnUnsavedTabOnThatSideIsKeptAndReported(): void
    {
        // The rule every bulk close here follows: never silently discard edits.
        $buffers = $this->withFourTabs();
        Services::resolve($this->ide, BufferManager::class)->get($this->openedPath('c.php'))?->document->insert('x');

        $buffers->closeTabsToTheSide($this->openedPath('a.php'), TabSide::Right);

        $this->assertSame(['a.php', 'c.php'], $this->tabs());
        $this->assertStringContainsString('unsaved tab kept open', Toasts::last($this->ide));
    }

    /** The tab menu's Close Others and Close All, from the keyboard. */
    public function testCtrlAltWClosesTheOthersAndWithShiftTheLot(): void
    {
        $this->withFourTabs();
        $tui = Services::resolve($this->ide, Tui::class);
        $buffers = Services::resolve($this->ide, BufferManager::class);

        $tui->handleInput("\x1b\x17"); // Ctrl+Alt+W
        $this->assertSame([$this->openedPath('d.php')], \array_map(static fn (Buffer $buffer): string => $buffer->path, $buffers->all()), 'the active tab stays');

        $tui->handleInput("\x1b[119;8u"); // Ctrl+Alt+Shift+W, as a kitty-protocol terminal sends it
        $this->assertSame([], $buffers->all());
    }

    /** The tab menu's other verbs, as commands on the active tab — Find Action reaches them. */
    public function testTheSideClosesAndThePinAreCommandsOnTheActiveTab(): void
    {
        $commands = Services::resolve($this->ide, CommandRegistry::class);
        $commands->run('close_tabs_left'); // no tab open: nothing to aim at, nothing done
        $service = $this->withFourTabs();
        $service->openFile($this->project->path('b.php'), focus: OpenFocus::Editor);
        $buffers = Services::resolve($this->ide, BufferManager::class);
        $open = static fn (): array => \array_map(static fn (Buffer $buffer): string => \basename($buffer->path), $buffers->all());

        $commands->run('toggle_pin_tab');
        $this->assertTrue($service->isPinned($this->openedPath('b.php')));

        $commands->run('close_tabs_right');
        $this->assertSame(['a.php', 'b.php'], $open());
        $commands->run('close_tabs_left');
        $this->assertSame(['b.php'], $open());
    }
}
