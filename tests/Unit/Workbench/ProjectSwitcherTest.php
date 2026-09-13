<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Event\ProjectOpenRequested;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\ProjectSwitcher;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Switching project, driven through the REAL overlay rather than a double:
 * the switcher's whole job is to put a list in front of someone and turn what
 * they choose into one event, and a recorded `openListOverlay` call would
 * assert the first half against a callback nobody invoked.
 */
final class ProjectSwitcherTest extends TestCase
{
    use PreservesPortaHome;
    use StatusReads;

    private TempProject $home;
    private TempProject $here;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;
    /** @var list<string> */
    private array $requested = [];

    private function switcher(string ...$recent): ProjectSwitcher
    {
        $this->home = new TempProject([]);
        putenv('PORTA_HOME='.$this->home->dir);
        $this->here = new TempProject(['composer.json' => '{}']);

        $store = new RecentProjectsStore();
        foreach ($recent as $root) {
            $store->record($root);
        }

        $this->editor = new CodeEditorWidget();
        $this->statusBar = new StatusBarWidget();
        $events = new EventDispatcher();
        $events->addListener(ProjectOpenRequested::class, function (ProjectOpenRequested $e): void {
            $this->requested[] = $e->rootDir;
        });

        return new ProjectSwitcher(
            $store,
            $this->editor,
            new Notifier($this->statusBar, TestTui::create()),
            new Translator('en'),
            $events,
            $this->here->dir,
        );
    }

    public function testPickingARecentProjectRequestsItByRoot(): void
    {
        $other = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($other->dir);

        $switcher->open();
        $this->assertTrue(EditorState::pickerOpen($this->editor), 'the list is up');
        $this->editor->handleInput("\r"); // the first entry

        $this->assertSame([(string) realpath($other->dir)], $this->requested);
    }

    /**
     * The project you are IN is not somewhere to switch to. With it filtered
     * out there is nothing left to list, so the switcher asks for a path
     * instead of opening a menu whose only entry says "type something".
     */
    public function testTheCurrentProjectIsNotOfferedAndAloneLeavesNothingToList(): void
    {
        $switcher = $this->switcher();
        $store = new RecentProjectsStore();
        $store->record($this->here->dir);

        $switcher->open();

        $this->assertFalse(EditorState::pickerOpen($this->editor), 'no list');
        $this->assertTrue(EditorState::promptOpen($this->editor), 'the path prompt instead');
    }

    /** A root that has been moved or deleted is an entry that answers a keystroke with an error. */
    public function testAVanishedProjectIsDroppedFromTheList(): void
    {
        $gone = new TempProject([]);
        $goneDir = $gone->dir;
        $alive = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($goneDir, $alive->dir);
        unset($gone); // TempProject's destructor removes the directory

        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([(string) realpath($alive->dir)], $this->requested, 'the live one was first, the dead one absent');
    }

    public function testTypingAPathThatIsNotADirectoryIsRefusedBeforeAnythingTearsDown(): void
    {
        $switcher = $this->switcher();

        $switcher->open(); // nothing recent → straight to the prompt
        foreach (str_split('/no/such/place') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested, 'nothing was requested');
        $this->assertStringContainsString('Not a directory', $this->flashText($this->statusBar));
    }

    /** Reopening where you already are is a no-op that says so. */
    public function testTypingTheCurrentProjectSaysItIsAlreadyOpen(): void
    {
        $switcher = $this->switcher();

        $switcher->open();
        foreach (str_split($this->here->dir) as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested);
        $this->assertStringContainsString('Already open', $this->flashText($this->statusBar));
    }

    /** An empty prompt is a cancel, not a request for the empty path. */
    public function testAnEmptyPathRequestsNothing(): void
    {
        $switcher = $this->switcher();

        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested);
        $this->assertSame('', $this->flashText($this->statusBar), 'and says nothing either');
    }

    /**
     * The list always ends with a way to name a project it does not contain —
     * otherwise the switcher can only ever return you somewhere you have been,
     * and the first time you want a NEW project is exactly when the list is
     * least likely to hold it.
     */
    public function testTheListOffersAPathPromptAsItsLastEntry(): void
    {
        $other = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($other->dir);

        $switcher->open();
        $this->editor->handleInput("\x1b[B"); // past the one recent project
        $this->editor->handleInput("\r"); // onto "Open a path…"

        $this->assertSame([], $this->requested, 'choosing it opens no project by itself');
        $this->assertTrue(EditorState::promptOpen($this->editor), 'it asks for a path');
    }

    /**
     * `~` is what a person types. Nothing else expands it here — the shell is
     * not involved, because the prompt is inside the IDE.
     */
    public function testATildePathIsExpandedAgainstTheHome(): void
    {
        $switcher = $this->switcher();
        $target = $this->home->dir.'/projects/alpha';
        mkdir($target, permissions: 0o755, recursive: true);

        $switcher->open(); // nothing recent → the prompt
        foreach (str_split('~/projects/alpha') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $this->assertSame([(string) realpath($target)], $this->requested);
    }
}
