<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Git;

use PHPUnit\Framework\TestCase;
use Porta\Git\ChangeEntry;
use Porta\Git\GitInfo;
use Porta\Git\GitStagingService;
use Porta\I18n\Translator;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Notifier;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class GitStagingServiceTest extends TestCase
{
    private function service(string $rootDir, StatusBarWidget $status): GitStagingService
    {
        $translator = new Translator('en');

        return new GitStagingService(
            new GitInfo($rootDir),
            $rootDir,
            new CommitBoxWidget($translator),
            new Notifier($status, new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24))),
            $translator,
        );
    }

    private function flashText(StatusBarWidget $status): string
    {
        return $status->activeFlash() ?? '';
    }

    public function testDiscardingAnUnremovableUntrackedFileStillFlashes(): void
    {
        $project = new TempProject();
        // An untracked file inside a directory we make read-only, so the unlink
        // fails and Filesystem::remove throws — the delete is best-effort and the
        // "Deleted" flash still fires (the IOException is swallowed).
        $project->write('locked/x.txt', 'data');
        chmod($project->path('locked'), permissions: 0o500); // no write on the parent → unlink denied

        $status = new StatusBarWidget();
        $service = $this->service($project->dir, $status);
        $entry = new ChangeEntry('locked/x.txt', '?', '?'); // untracked

        $refreshed = false;
        $service->discard($entry, static function () use (&$refreshed): void {
            $refreshed = true;
        });

        chmod($project->path('locked'), permissions: 0o755); // restore so cleanup can remove the tree

        $this->assertTrue($refreshed, 'the status refresh callback still ran');
        $this->assertStringContainsString('Deleted', $this->flashText($status));
    }
}
