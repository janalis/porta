<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Ide;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Terminal\VirtualTerminal;

/**
 * FileCommands' open_as_text gate: true only for a viewer tab (one opened
 * via BufferManager::openPane, e.g. an image/graph/svg), never for no
 * active buffer or a plain text one. BufferManager is a final class the
 * project's hard rules forbid mocking, so this boots a real IDE.
 */
final class FileCommandsTest extends TestCase
{
    /**
     * Held for the run rather than left to the expression: TempProject's
     * destructor removes its directory, so a project freed at the end of boot()
     * takes the fixture with it — harmless for the cases that only touch
     * BufferManager, fatal for one that opens a file from disk.
     */
    private TempProject $project;

    private function boot(): Ide
    {
        $this->project = new TempProject(['a.php' => "<?php\n"]);
        new OnboardingState($this->project->dir)->markWelcomeSeen();

        return new Ide($this->project->dir, null, new VirtualTerminal(columns: 100, rows: 30));
    }

    private function closeIde(Ide $ide): void
    {
        Services::resolve($ide, ViewRegistry::class)->closeAll();
    }

    public function testOpenAsTextIsDisabledWithNoActiveBuffer(): void
    {
        $ide = $this->boot();
        try {
            $commands = Services::resolve($ide, CommandRegistry::class);

            $this->assertFalse($commands->isEnabled('open_as_text'), 'no active buffer at all — a null-safe read, not a crash');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testOpenAsTextIsDisabledForAPlainTextBuffer(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $buffers->open('a.php', "<?php\n");

            $this->assertFalse($commands->isEnabled('open_as_text'), 'a plain text buffer is not a viewer — the predicate must be exactly true===..., not its negation');
        } finally {
            $this->closeIde($ide);
        }
    }

    public function testOpenAsTextIsEnabledOnlyForAViewerBuffer(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);

            $buffers->openPane('a.png', 'image', payload: null);

            $this->assertTrue($commands->isEnabled('open_as_text'), 'a viewer tab must enable open_as_text');
        } finally {
            $this->closeIde($ide);
        }
    }

    /**
     * `ide_open_project` opens a list that composites over the EDITOR pane, and
     * that widget is detached while a viewer tab owns the slot. Without
     * `ensureTextEditorActive()` the overlay is stored on a widget nothing
     * renders and the key does nothing whatsoever — the same dead-key shape the
     * previous round spent itself removing, reintroduced by the command that
     * added the large-file preview's own viewer type.
     *
     * Asserted through the REAL editor's overlay state rather than by recording
     * the call: the bug is that the picker does not reach the screen, and only
     * the screen-side state can say whether it did.
     */
    public function testOpeningTheProjectSwitcherFromAViewerTabStillShowsThePicker(): void
    {
        $ide = $this->boot();
        try {
            $buffers = Services::resolve($ide, BufferManager::class);
            $commands = Services::resolve($ide, CommandRegistry::class);
            $editor = Services::resolve($ide, CodeEditorWidget::class);
            // A text tab to hop back to: with none open the guard correctly
            // refuses and flashes instead, which is a different case.
            Services::resolve($ide, BufferService::class)->openFile($this->project->path('a.php'));
            $buffers->openPane('a.png', 'image', payload: null);

            $commands->run('ide_open_project');

            // The EFFECT, not the stored state. `pickerOpen()` reads the overlay
            // host's field, which is set whether or not the widget holding it is
            // on screen — so it cannot tell the bug from the fix, and said so by
            // passing with the guard removed. What the guard actually does is
            // put the editor back in the slot, and that is observable.
            $active = $buffers->active();
            $this->assertNotNull($active);
            $this->assertFalse($active->isViewer(), 'the switcher hopped back to a text tab, so its overlay has a widget that renders');
            $this->assertTrue(EditorState::pickerOpen($editor) || EditorState::promptOpen($editor), 'and the picker is up');
        } finally {
            $this->closeIde($ide);
        }
    }
}
