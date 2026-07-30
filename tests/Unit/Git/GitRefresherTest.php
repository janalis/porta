<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Event\BufferOpened;
use Porta\Event\BufferSaved;
use Porta\Event\GitRemoteCompleted;
use Porta\Git\GitInfo;
use Porta\Git\GitRefresher;
use Porta\Git\GitSettings;
use Porta\ImageViewer\ImageData;
use Porta\Tests\Support\GitBinary;
use Porta\Tests\Support\Presence;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/** Real git repo, virtual terminal — mirrors GitViewControllerTest's setup. */
final class GitRefresherTest extends TestCase
{
    use GitBinary;
    use PumpsLoop;

    private TempProject $project;

    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private function refresher(Presence $realRepo = Presence::With, ?GitSettings $gitSettings = null): GitRefresher
    {
        if (Presence::With === $realRepo && null === $this->whichGit()) {
            $this->markTestSkipped('git not available');
        }
        $this->project = new TempProject(['a.txt' => "one\ntwo\n"]);
        if (Presence::With === $realRepo) {
            $this->project->initGit(branch: 'trunk'); // 'trunk' is asserted as the branch
            $this->project->git('config', 'user.email', 't@t');
            $this->project->git('config', 'user.name', 't');
            $this->project->git('config', 'commit.gpgsign', 'false');
            $this->project->git('add', '-A');
            $this->project->git('commit', '-q', '-m', 'init');
        }
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new GitRefresher(new GitInfo($this->project->dir), $this->buffers, $this->editor, new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10)), $gitSettings);
    }

    public function testRefreshBranchExposesTheBranchForTheStatusBar(): void
    {
        $refresher = $this->refresher();
        $this->assertNull($refresher->branch(), 'no branch before the first refresh');

        $refresher->refreshBranch();
        $this->await(static function () use (&$refresher): bool {
            return null !== $refresher->branch();
        }, what: 'the async branch lookup resolved');

        $this->assertSame('trunk', $refresher->branch());
    }

    public function testRefreshMarksLoadsChangeMarksAndUpdatesTheActiveEditor(): void
    {
        $refresher = $this->refresher();
        file_put_contents($this->project->path('a.txt'), data: "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");

        $refresher->refreshActiveMarks();
        $this->await(static function () use (&$buffer): bool {
            return [] !== $buffer->gitMarks;
        }, what: 'the async diff resolved');

        $editorMarks = Probe::get($this->editor, 'decorations')
            ->gitMarks();
        $this->assertSame($buffer->gitMarks, $editorMarks, 'the active buffer pushed its marks into the editor gutter');
    }

    public function testGutterMarksDisabledSkipsTheDiff(): void
    {
        $refresher = $this->refresher(gitSettings: new GitSettings(['gitGutterMarks' => false]));
        file_put_contents($this->project->path('a.txt'), data: "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");

        $refresher->refreshActiveMarks(); // gated off → no async diff is scheduled
        $this->assertSame([], $buffer->gitMarks, 'no marks computed when the gutter is disabled');
    }

    public function testImageBuffersAndMissingGitShortCircuit(): void
    {
        $refresher = $this->refresher();
        $gd = imagecreatetruecolor(2, height: 2);
        \assert(false !== $gd, description: 'the truecolor image was created');
        $image = $this->buffers->openPane($this->project->path('i.png'), 'image', new ImageData($gd, 2, 2, 9));
        $refresher->refreshMarks($image); // image buffers have no git marks

        $unavailable = $this->refresher(realRepo: Presence::Without);
        $unavailable->refreshBranch();
        $unavailable->refreshActiveMarks();
        $this->assertNull($unavailable->branch(), 'no repo: the refresh is a silent no-op');
    }

    public function testRefreshActiveMarksWithoutAnActiveBufferIsANoOp(): void
    {
        $refresher = $this->refresher();
        $refresher->refreshActiveMarks();

        $this->addToAssertionCount(1);
    }

    public function testBufferEventListenersRefreshMarksForTextBuffersOnly(): void
    {
        $refresher = $this->refresher();
        file_put_contents($this->project->path('a.txt'), data: "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");

        $refresher->onBufferOpened(new BufferOpened($buffer));
        $this->await(static function () use (&$buffer): bool {
            return [] !== $buffer->gitMarks;
        }, what: 'the open listener diffed the text buffer');

        $help = $this->buffers->openPane($this->project->path('h.md'), 'help', '# hi');
        $refresher->onBufferOpened(new BufferOpened($help));
        $this->assertSame([], $help->gitMarks, 'help tabs are never diffed');

        $buffer->gitMarks = [];
        $refresher->onBufferSaved(new BufferSaved($buffer));
        $this->await(static function () use (&$buffer, &$refresher): bool {
            return [] !== $buffer->gitMarks && null !== $refresher->branch();
        }, what: 'the save listener refreshed marks and branch');
    }

    public function testRemoteCompletedRefreshesBranchAndActiveMarks(): void
    {
        $refresher = $this->refresher();
        file_put_contents($this->project->path('a.txt'), data: "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");
        $this->buffers->setActive($buffer->path);

        $refresher->onGitRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(static function () use (&$buffer, &$refresher): bool {
            return [] !== $buffer->gitMarks && null !== $refresher->branch();
        }, what: 'a completed remote op refreshed branch + active marks');
        $this->addToAssertionCount(1); // the throwing await above is the assertion
    }
}
