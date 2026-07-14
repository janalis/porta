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
use Porta\Image\ImageData;
use Porta\Tests\Support\TempProject;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
use Revolt\EventLoop;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/** Real git repo, virtual terminal — mirrors GitViewControllerTest's setup. */
final class GitRefresherTest extends TestCase
{
    private TempProject $project;

    private BufferManager $buffers;

    private CodeEditorWidget $editor;

    private function refresher(bool $realRepo = true): GitRefresher
    {
        if ($realRepo && null === self::findGit()) {
            $this->markTestSkipped('git not available');
        }
        $this->project = new TempProject(['a.txt' => "one\ntwo\n"]);
        if ($realRepo) {
            $d = escapeshellarg($this->project->dir);
            exec("git -C {$d} init -q -b trunk && git -C {$d} config user.email t@t && git -C {$d} config user.name t && git -C {$d} add -A && git -C {$d} commit -qm init");
        }
        $this->buffers = new BufferManager();
        $this->editor = new CodeEditorWidget();

        return new GitRefresher(new GitInfo($this->project->dir), $this->buffers, $this->editor, new StatusBarWidget(), new Tui(terminal: new VirtualTerminal(columns: 80, rows: 10)));
    }

    private function await(callable $done, string $why, float $timeout = 3.0): void
    {
        $elapsed = 0.0;
        while (!$done() && $elapsed < $timeout) {
            $suspension = EventLoop::getSuspension();
            EventLoop::delay(0.02, static fn () => $suspension->resume());
            $suspension->suspend();
            $elapsed += 0.02;
        }
        $this->assertTrue($done(), $why);
    }

    public function testRefreshBranchExposesTheBranchForTheStatusBar(): void
    {
        $refresher = $this->refresher();
        $this->assertNull($refresher->branch(), 'no branch before the first refresh');

        $refresher->refreshBranch();
        $this->await(static fn (): bool => null !== $refresher->branch(), 'the async branch lookup resolved');

        $this->assertSame('trunk', $refresher->branch());
    }

    public function testRefreshMarksLoadsChangeMarksAndUpdatesTheActiveEditor(): void
    {
        $refresher = $this->refresher();
        file_put_contents($this->project->path('a.txt'), "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");

        $refresher->refreshActiveMarks();
        $this->await(static fn (): bool => [] !== $buffer->gitMarks, 'the async diff resolved');

        $editorMarks = new \ReflectionProperty($this->editor, 'gitMarks')->getValue($this->editor);
        $this->assertSame($buffer->gitMarks, $editorMarks, 'the active buffer pushed its marks into the editor gutter');
    }

    public function testImageBuffersAndMissingGitShortCircuit(): void
    {
        $refresher = $this->refresher();
        $gd = imagecreatetruecolor(2, 2);
        \assert(false !== $gd);
        $image = $this->buffers->openImage($this->project->path('i.png'), new ImageData($gd, 2, 2, 9));
        $refresher->refreshMarks($image); // image buffers have no git marks

        $unavailable = $this->refresher(realRepo: false);
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
        file_put_contents($this->project->path('a.txt'), "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");

        $refresher->onBufferOpened(new BufferOpened($buffer));
        $this->await(static fn (): bool => [] !== $buffer->gitMarks, 'the open listener diffed the text buffer');

        $help = $this->buffers->openHelp($this->project->path('h.md'), '# hi');
        $refresher->onBufferOpened(new BufferOpened($help));
        $this->assertSame([], $help->gitMarks, 'help tabs are never diffed');

        $buffer->gitMarks = [];
        $refresher->onBufferSaved(new BufferSaved($buffer));
        $this->await(static fn (): bool => [] !== $buffer->gitMarks && null !== $refresher->branch(), 'the save listener refreshed marks and branch');
    }

    public function testRemoteCompletedRefreshesBranchAndActiveMarks(): void
    {
        $refresher = $this->refresher();
        file_put_contents($this->project->path('a.txt'), "one\nchanged\n");
        $buffer = $this->buffers->open($this->project->path('a.txt'), "one\nchanged\n");
        $this->buffers->setActive($buffer->path);

        $refresher->onGitRemoteCompleted(new GitRemoteCompleted('pull', true));
        $this->await(static fn (): bool => [] !== $buffer->gitMarks && null !== $refresher->branch(), 'a completed remote op refreshed branch + active marks');
    }

    private static function findGit(): ?string
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $d) {
            if (is_file($d.'/git') && is_executable($d.'/git')) {
                return $d.'/git';
            }
        }

        return null;
    }
}
