<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\Event\BufferSaved;
use Porta\Event\BufferSwitched;
use Porta\Git\BlameAnnotations;
use Porta\Git\GitInfo;
use Porta\I18n\Translator;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Revolt\EventLoop;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class BlameAnnotationsTest extends TestCase
{
    use PumpsLoop;
    use StatusReads;

    private CodeEditorWidget $editor;
    private StatusBarWidget $status;
    private TempProject $project;

    private function blame(Presence $withRepo = Presence::With): BlameAnnotations
    {
        if (null === new \Symfony\Component\Process\ExecutableFinder()->find('git')) {
            $this->markTestSkipped('git not available');
        }
        $this->project = new TempProject(['a.php' => "<?php\n\$x = 1;\n"]);
        if (Presence::With === $withRepo) {
            $this->project->git('init', '-q');
            $this->project->git('config', 'user.email', 't@t');
            $this->project->git('config', 'user.name', 'tester'); // asserted as the blame author
            $this->project->git('config', 'commit.gpgsign', 'false');
            $this->project->git('add', '-A');
            $this->project->git('commit', '-q', '-m', 'init');
        }
        $this->editor = new CodeEditorWidget();
        $this->status = new StatusBarWidget();
        $tui = new Tui(terminal: new VirtualTerminal(columns: 120, rows: 24), keybindings: new Keybindings([]), renderer: new Renderer(new StyleSheet()));

        return new BlameAnnotations(new GitInfo($this->project->dir), $this->editor, $this->editor, new Notifier($this->status, $tui), new Translator('en'), $tui);
    }

    private function editorScreen(): string
    {
        return AnsiUtils::stripAnsiCodes(implode("\n", $this->editor->render(new RenderContext(120, 10))));
    }

    private function showFile(string $name = 'a.php'): void
    {
        $this->editor->showDocument($this->project->path($name), new Document((string) file_get_contents($this->project->path($name))));
    }

    public function testToggleAnnotatesThenClears(): void
    {
        $blame = $this->blame();
        $this->showFile();

        $blame->toggle();
        $this->assertStringContainsString('Blame annotations shown', $this->statusRow($this->status, 120));
        $this->await(fn (): bool => str_contains($this->editorScreen(), 'tester'));
        $this->assertStringContainsString(date('Y-m-d'), $this->editorScreen(), 'sha author date rendered');

        $blame->toggle();
        $this->assertStringContainsString('Blame annotations hidden', $this->statusRow($this->status, 120));
        $this->assertStringNotContainsString('tester', $this->editorScreen());
    }

    public function testWithoutARepositoryOrFileTheToggleFlashes(): void
    {
        $blame = $this->blame(withRepo: Presence::Without);
        $this->showFile();
        $blame->toggle();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->status, 120));

        $blame = $this->blame(); // repo, but nothing open
        $blame->toggle();
        $this->assertStringContainsString('Git not available', $this->statusRow($this->status, 120));
    }

    public function testBufferEventsReapplyOnlyWhileEnabled(): void
    {
        $blame = $this->blame();
        $this->showFile();
        $buffer = new BufferManager()->open($this->project->path('a.php'), "<?php\n");

        $blame->onBufferSwitched(new BufferSwitched($buffer));
        $this->assertStringNotContainsString('tester', $this->editorScreen(), 'disabled → events are inert');

        $blame->toggle();
        $this->await(fn (): bool => str_contains($this->editorScreen(), 'tester'));

        // A switch clears the widget (showDocument) and the event re-applies.
        $this->showFile();
        $this->assertStringNotContainsString('tester', $this->editorScreen());
        $blame->onBufferSwitched(new BufferSwitched($buffer));
        $this->await(fn (): bool => str_contains($this->editorScreen(), 'tester'));

        // A save re-syncs too.
        $this->showFile();
        $blame->onBufferSaved(new BufferSaved($buffer));
        $this->await(fn (): bool => str_contains($this->editorScreen(), 'tester'));
    }

    public function testARefreshWithNothingOpenIsANoOp(): void
    {
        $blame = $this->blame();
        // Enabled (e.g. toggled on earlier), then every buffer closed: the
        // save listener must not blame a null path.
        new \ReflectionProperty($blame, 'enabled')->setValue($blame, true);
        $buffer = new BufferManager()->open($this->project->path('a.php'), "<?php\n");
        $blame->onBufferSaved(new BufferSaved($buffer));
        $this->assertStringNotContainsString('tester', $this->editorScreen());
    }

    public function testAStaleResponseAfterToggleOffIsDropped(): void
    {
        $blame = $this->blame();
        $this->showFile();

        $blame->toggle(); // starts the async blame
        $blame->toggle(); // off again before the subprocess lands
        // Pump long enough for the blame subprocess to complete and be dropped.
        $deadline = microtime(true) + 0.6;
        $this->await(static function () use (&$deadline): bool {
            return microtime(true) > $deadline;
        }, 2.0);
        $this->assertStringNotContainsString('tester', $this->editorScreen(), 'the stale response annotated nothing');
    }
}
