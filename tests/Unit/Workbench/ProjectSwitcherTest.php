<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Config\WorkspaceTrust;
use Porta\Editor\BufferManager;
use Porta\Event\ProjectOpenRequested;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteSpec;
use Porta\History\RecentProjectsStore;
use Porta\I18n\Translator;
use Porta\Tests\Support\EditorState;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Porta\Workbench\ProjectSwitcher;
use Porta\Workbench\UnsavedChangesGuard;
use Symfony\Component\Clock\MockClock;
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
    private BufferManager $buffers;
    private UnsavedChangesGuard $guard;
    private WorkspaceTrust $trust;
    private ?WorkspaceTrust $trustOverride = null;
    private CodeEditorWidget $editor;
    private StatusBarWidget $statusBar;
    /** @var list<string> */
    private array $requested = [];

    private function switcher(string ...$recent): ProjectSwitcher
    {
        $this->home = new TempProject([]);
        \putenv('PORTA_HOME='.$this->home->dir);
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

        $notifier = new Notifier($this->statusBar, TestTui::create());
        $translator = new Translator('en');

        return new ProjectSwitcher(
            $store,
            $this->editor,
            $notifier,
            $translator,
            $events,
            $this->guard = new UnsavedChangesGuard($this->buffers = new BufferManager(), $notifier, $translator, new MockClock()),
            $this->trust = $this->trustOverride ?? new WorkspaceTrust($this->home->dir),
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

        $this->assertSame([(string) \realpath($other->dir)], $this->requested);
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

        $this->assertSame([(string) \realpath($alive->dir)], $this->requested, 'the live one was first, the dead one absent');
    }

    public function testTypingAPathThatIsNotADirectoryIsRefusedBeforeAnythingTearsDown(): void
    {
        $switcher = $this->switcher();

        $switcher->open(); // nothing recent → straight to the prompt
        foreach (\str_split('/no/such/place') as $ch) {
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
        foreach (\str_split($this->here->dir) as $ch) {
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
        \mkdir($target, permissions: 0o755, recursive: true);

        $switcher->open(); // nothing recent → the prompt
        foreach (\str_split('~/projects/alpha') as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");

        $this->assertSame([(string) \realpath($target)], $this->requested);
    }

    private function type(string $text): void
    {
        foreach (\mb_str_split($text) as $ch) {
            $this->editor->handleInput($ch);
        }
        $this->editor->handleInput("\r");
    }

    /**
     * A server's project opens at its cache — made the first time, marked as
     * the server's — from the remote prompt, and from the path prompt too.
     */
    public function testARemoteLocationOpensItsCache(): void
    {
        $switcher = $this->switcher();

        $switcher->openRemote();
        $this->type('deploy@example.org:/srv/app');

        $this->assertCount(1, $this->requested);
        $this->assertSame('ssh://deploy@example.org/srv/app', ProjectLocation::forRoot($this->requested[0])->remote?->url());
        $this->assertStringStartsWith((string) \realpath($this->home->dir).'/.porta/remote/', $this->requested[0]);

        $switcher->open();
        $this->type('ftp://example.org/pub');
        $this->assertSame('ftp', ProjectLocation::forRoot($this->requested[1])->remote?->scheme);
    }

    /** A local directory wins a name that could also be a server's. */
    public function testALocalDirectoryWinsOverARemoteReading(): void
    {
        $switcher = $this->switcher();
        $local = $this->home->dir.'/host:path';
        \mkdir($local, permissions: 0o755, recursive: true);

        $switcher->openRemote();
        $this->type($local);

        $this->assertSame([(string) \realpath($local)], $this->requested);
    }

    /**
     * A URL no transport serves is refused without being looked at: is_dir()
     * on `http://…` is PHP's own HTTP client, which would hang the IDE on a
     * server that does not answer. The host here does not resolve — were it
     * looked up, this test would wait on DNS.
     */
    public function testAUrlNoTransportServesIsRefusedUnlooked(): void
    {
        $switcher = $this->switcher();

        $switcher->openRemote();
        $this->type('http://porta-test.invalid/');

        $this->assertSame([], $this->requested);
        $this->assertStringContainsString('Not a directory: http://porta-test.invalid/', $this->flashText($this->statusBar));
    }

    public function testAnEmptyRemoteLocationRequestsNothing(): void
    {
        $switcher = $this->switcher();

        $switcher->openRemote();
        $this->type('  ');

        $this->assertSame([], $this->requested);
        $this->assertSame('', $this->flashText($this->statusBar), 'blank is not a location to refuse');
    }

    /** A recent remote project is listed by the server it mirrors, not by its cache's path. */
    public function testARecentRemoteProjectIsListedByItsServer(): void
    {
        $home = new TempProject();
        $cache = ProjectLocation::prepare(new RemoteSpec('ssh', 'example.org', '/srv/app', 'deploy'), $home->dir);
        $local = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($local->dir, $cache);

        $switcher->open();

        /** @var array{items: list<array{label: string, hint: string, value: string}>} $list */
        $list = Probe::get(Probe::get($this->editor, 'overlayHost'), 'overlay');
        $this->assertSame(['label' => 'app', 'hint' => 'deploy@example.org:/srv/app', 'value' => $cache], $list['items'][0], 'most recent first');
        $this->assertSame(\basename($local->dir), $list['items'][1]['label'], 'a local one by its folder');
    }

    /**
     * A dirty buffer holds the switch until it is confirmed.
     *
     * Switching ENDS the session, and the IDE has no autosave: the teardown
     * writes the session, the debug state and the bookmarks, never buffer
     * contents. So the edits simply go — and the session IS saved, so coming
     * back reopens the same tabs clean from disk with nothing to say they went.
     *
     * The assertion is that NOTHING WAS REQUESTED, not that a flash appeared: a
     * switcher that warns and dispatches anyway passes a flash-only check while
     * losing the work regardless.
     */
    public function testADirtyBufferHoldsTheSwitchUntilItIsConfirmed(): void
    {
        $other = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($other->dir);
        $this->buffers->open($this->here->path('a.php'), "<?php\n")->document->insert('x');

        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested, 'the first pick is refused');
        $this->assertStringContainsString('Unsaved changes', $this->flashText($this->statusBar));

        // The second ask inside the window is the confirmation.
        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([(string) \realpath($other->dir)], $this->requested);
    }

    /**
     * And the confirm is per-VERB: a press that warned about QUITTING must not
     * arm a project switch. Two verbs sharing one window would make the second
     * press confirm something the first was never asked about.
     */
    public function testAQuitWarningDoesNotArmTheProjectSwitch(): void
    {
        $other = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($other->dir);
        $this->buffers->open($this->here->path('a.php'), "<?php\n")->document->insert('x');

        $this->guard->confirmed('quit', 'press again'); // the user pressed Ctrl+Q once
        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested, 'the switch still needs its own confirmation');
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function projectsWithSomethingToRun(): iterable
    {
        yield 'a .porta.json' => [['.porta.json' => '{}']];
        yield 'plugins of its own' => [['.porta/plugins/x/plugin.php' => '<?php']];
        yield 'a vendor/bin' => [['vendor/bin/phpstan' => '#!/bin/sh']];
    }

    /**
     * Opening a project is running it once it is trusted: one never answered
     * for, holding something to run, is asked about before the switch — and
     * the answer is kept, so the next switch there just goes.
     *
     * @param array<string, string> $files
     */
    #[DataProvider('projectsWithSomethingToRun')]
    public function testAProjectNeverAnsweredForIsAskedAboutFirst(array $files): void
    {
        $other = new TempProject($files);
        $switcher = $this->switcher($other->dir);
        $root = (string) \realpath($other->dir);

        $switcher->open();
        $this->editor->handleInput("\r");
        $this->assertSame([], $this->requested, 'not yet: asked first');
        $this->assertTrue(EditorState::pickerOpen($this->editor), 'trust, or open restricted');
        /** @var array{title: string, items: list<array{label: string, hint: string, value: string}>} $asked */
        $asked = Probe::get(Probe::object($this->editor, 'overlayHost'), 'overlay');
        $this->assertSame('Trust '.\basename($root).'?', $asked['title']);
        $this->assertSame(
            [
                ['Trust and Open',  'its own plugins and tools run'],
                ['Open Restricted', 'nothing of the project\'s own runs'],
            ],
            \array_map(static fn (array $item): array => [$item['label'], $item['hint']], $asked['items']),
            'what each answer means, said beside it',
        );
        $this->editor->handleInput("\x1b[B");
        $this->editor->handleInput("\r"); // Open Restricted

        $this->assertSame([$root], $this->requested);
        $this->assertFalse($this->trust->isTrusted($root));
        $this->assertFalse($this->trust->shouldAsk($root), 'answered: not asked again');

        $switcher->open();
        $this->editor->handleInput("\r");
        $this->assertSame([$root, $root], $this->requested, 'straight through the second time');
    }

    public function testTrustAndOpenTrustsTheProject(): void
    {
        $other = new TempProject(['.porta.json' => '{}']);
        $switcher = $this->switcher($other->dir);

        $switcher->open();
        $this->editor->handleInput("\r");
        $this->editor->handleInput("\r"); // Trust and Open

        $this->assertSame([(string) \realpath($other->dir)], $this->requested);
        $this->assertTrue($this->trust->isTrusted($other->dir));
    }

    public function testAProjectWithNothingToRunIsNotAskedAbout(): void
    {
        $other = new TempProject(['composer.json' => '{}']);
        $switcher = $this->switcher($other->dir);

        $switcher->open();
        $this->editor->handleInput("\r");

        $this->assertSame([(string) \realpath($other->dir)], $this->requested);
        $this->assertFalse(EditorState::pickerOpen($this->editor), 'and nothing asked after the fact');
    }

    public function testAnAnswerThatCannotBeSavedOpensNothing(): void
    {
        $other = new TempProject(['.porta.json' => '{}']);
        $this->trustOverride = new WorkspaceTrust('/dev/null');
        $switcher = $this->switcher($other->dir);

        $switcher->open();
        $this->editor->handleInput("\r");
        $this->editor->handleInput("\r");

        $this->assertSame([], $this->requested);
        $this->assertStringContainsString('Could not save the answer', $this->flashText($this->statusBar));
    }

    /** From inside, either way: the answer is written, and the project reopens to apply it. */
    public function testTrustingAndRestrictingTheCurrentProjectReopensIt(): void
    {
        $switcher = $this->switcher();
        $here = $this->here->dir;

        $switcher->trustCurrent();
        $this->assertTrue($this->trust->isTrusted($here));
        $this->assertSame([$here], $this->requested);
        $switcher->trustCurrent();
        $this->assertStringContainsString('trusted already', $this->flashText($this->statusBar));
        $this->assertSame([$here], $this->requested, 'nothing to reopen for');

        $switcher->restrictCurrent();
        $this->assertFalse($this->trust->isTrusted($here));
        $this->assertSame([$here, $here], $this->requested);
        $switcher->restrictCurrent();
        $this->assertStringContainsString('restricted already', $this->flashText($this->statusBar));
        $this->assertSame([$here, $here], $this->requested);
    }

    /** Reopening ends the session: unsaved edits hold it, and nothing is written meanwhile. */
    public function testADirtyBufferHoldsTheReopenAndTheAnswer(): void
    {
        $switcher = $this->switcher();
        $this->buffers->open($this->here->path('a.php'), "<?php\n")->document->insert('x');

        $switcher->trustCurrent();

        $this->assertSame([], $this->requested);
        $this->assertFalse($this->trust->isTrusted($this->here->dir), 'not written either');
        $this->assertStringContainsString('Unsaved changes', $this->flashText($this->statusBar));
    }

    public function testAnAnswerFromInsideThatCannotBeSavedReopensNothing(): void
    {
        $this->trustOverride = new WorkspaceTrust('/dev/null');
        $switcher = $this->switcher();

        $switcher->trustCurrent();

        $this->assertSame([], $this->requested);
        $this->assertStringContainsString('Could not save the answer', $this->flashText($this->statusBar));
    }
}
