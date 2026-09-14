<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TestTui;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\UnsavedChangesGuard;
use Symfony\Component\Clock\MockClock;

/**
 * The confirm in front of anything that ends a session with unsaved edits.
 *
 * It had no unit test at all while it lived inside `WorkbenchActions::quit()` —
 * two E2e cases covered quitting and nothing covered the window, which is how
 * three separate paths came to skip the guard entirely without a red test.
 */
final class UnsavedChangesGuardTest extends TestCase
{
    use StatusReads;

    private BufferManager $buffers;
    private StatusBarWidget $statusBar;
    private MockClock $clock;

    private function guard(): UnsavedChangesGuard
    {
        $this->buffers = new BufferManager();
        $this->statusBar = new StatusBarWidget();
        $this->clock = new MockClock();

        return new UnsavedChangesGuard(
            $this->buffers,
            new Notifier($this->statusBar, TestTui::create()),
            new Translator('en'),
            $this->clock,
        );
    }

    private function dirty(): void
    {
        $this->buffers->open('/tmp/a.php', "<?php\n")->document->insert('x');
    }

    public function testNothingDirtyProceedsWithoutSayingAnything(): void
    {
        $guard = $this->guard();

        $this->assertTrue($guard->confirmed('quit', 'press again'));
        $this->assertSame('', $this->flashText($this->statusBar));
    }

    public function testADirtyBufferRefusesOnceThenConfirms(): void
    {
        $guard = $this->guard();
        $this->dirty();

        $this->assertFalse($guard->confirmed('quit', 'press again'), 'the first ask is refused');
        $this->assertStringContainsString('Unsaved changes', $this->flashText($this->statusBar));
        $this->assertStringContainsString('press again', $this->flashText($this->statusBar), 'and says how to confirm');

        $this->assertTrue($guard->confirmed('quit', 'press again'), 'the second inside the window proceeds');
    }

    /**
     * The window EXPIRES. A third ask minutes later is a fresh intent, not a
     * confirmation of something the user has long forgotten — and nothing
     * tested this before, in either the guard or the quit path it came from.
     */
    public function testTheConfirmationWindowExpires(): void
    {
        $guard = $this->guard();
        $this->dirty();
        $this->assertFalse($guard->confirmed('quit', 'press again'));

        $this->clock->modify('+4 seconds');

        $this->assertFalse($guard->confirmed('quit', 'press again'), 'past the window it warns again');
    }

    /** Just inside the window still confirms — the control for the case above. */
    public function testJustInsideTheWindowStillConfirms(): void
    {
        $guard = $this->guard();
        $this->dirty();
        $this->assertFalse($guard->confirmed('quit', 'press again'));

        $this->clock->modify('+2 seconds');

        $this->assertTrue($guard->confirmed('quit', 'press again'));
    }

    /**
     * Each verb keeps its OWN window. A press that warned about quitting must
     * not arm a project switch: the second press has to confirm the thing the
     * first one was refused for, or the confirm is a trap.
     */
    public function testOneVerbsWarningDoesNotConfirmAnother(): void
    {
        $guard = $this->guard();
        $this->dirty();

        $this->assertFalse($guard->confirmed('quit', 'press again'));

        $this->assertFalse($guard->confirmed('switch-project', 'press again'), 'the other verb is still unconfirmed');
        $this->assertTrue($guard->confirmed('switch-project', 'press again'), 'and confirms on its own second ask');
    }

    /** The count is the file count, and it is worded for one file and for many. */
    public function testTheWarningCountsTheFiles(): void
    {
        $guard = $this->guard();
        $this->dirty();
        $guard->confirmed('quit', 'press again');
        $this->assertStringContainsString('1 file has', $this->flashText($this->statusBar));

        $guard = $this->guard();
        $this->dirty();
        $this->buffers->open('/tmp/b.php', "<?php\n")->document->insert('y');
        $guard->confirmed('quit', 'press again');
        $this->assertStringContainsString('2 files have', $this->flashText($this->statusBar));
    }
}
