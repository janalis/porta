<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Filesystem\ProjectLocation;
use Porta\Ide;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A remote project end to end: Porta opened on a cache marked as a server's,
 * the server served by a project plugin over {@see \Porta\Tests\Support\FakeRemoteTransport}.
 *
 * The join is the point. The tree is built before plugins activate, so the
 * root's first listing has to come from the poll `run()` starts; the editor
 * has to wait for a placeholder's content instead of opening it empty; and a
 * save has to reach the server, which only happens if the container hands
 * every consumer the remote filesystem rather than the local one.
 */
final class IdeRemoteProjectTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    public function testTheTreeListsTheServerAndAnOpenedFileIsFetchedEditedAndUploaded(): void
    {
        \putenv('PATH=/nonexistent');
        $server = new TempProject(['README.md' => "# served\n", 'src/App.php' => "<?php\n"]);
        $cache = new TempProject([
            ProjectLocation::MARKER => '{"url": "ssh://example.org/srv/app"}',
            '.porta/plugins/fakeremote/plugin.php' => \sprintf(<<<'PHP'
                <?php
                use Porta\Filesystem\RemoteSpec;
                use Porta\Filesystem\RemoteTransport;
                use Porta\Plugin\Api\AbstractPlugin;
                use Porta\Plugin\Api\PluginApi;
                use Porta\Plugin\Api\RemoteProjectPlugin;
                use Porta\Tests\Support\FakeRemoteTransport;

                return new class('fakeremote') extends AbstractPlugin implements RemoteProjectPlugin {
                    public function activate(PluginApi $api): void {}

                    public function remoteTransport(RemoteSpec $spec): ?RemoteTransport
                    {
                        return 'ssh' === $spec->scheme ? new FakeRemoteTransport(%s) : null;
                    }
                };
                PHP, \var_export($server->dir, return: true)),
        ]);

        $this->runIde($cache, null, [
            100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($cache): void {
                $this->assertTrue(Probe::get(Services::resolve($ide, LspSessionPool::class), 'disabled'), 'no language server over a cache of what was opened');
                $this->awaitPainted($tui, $terminal, 'README.md');
                $this->assertStringContainsString('src', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the server\'s directory is in the tree');
                $this->assertSame('', \file_get_contents($cache->path('README.md')), 'listed, not fetched');
            },
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($cache): void {
                Services::resolve($ide, BufferService::class)->openFile($cache->path('README.md'), focus: OpenFocus::Editor);
                $this->await(static fn (): bool => null !== Services::resolve($ide, BufferManager::class)->get((string) \realpath($cache->path('README.md'))));
                $this->assertSame("# served\n", $tui->getById('editor')->getDocument()->getText(), 'the content, not the placeholder');
                $tui->handleInput('X');
                $tui->handleInput("\x13"); // ctrl+s
            },
            300 => function () use ($server): void {
                $this->await(static fn (): bool => "X# served\n" === \file_get_contents($server->path('README.md')));
                $this->assertSame("X# served\n", \file_get_contents($server->path('README.md')), 'the save reached the server');
            },
        ]);

        $this->assertFileExists($cache->path('.porta/remote-index.json'), 'and what was fetched is remembered');
    }
}
