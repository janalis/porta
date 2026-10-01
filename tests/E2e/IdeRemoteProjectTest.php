<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Filesystem\ProjectLocation;
use Porta\Filesystem\RemoteFilesystem;
use Porta\Ide;
use Porta\Lsp\LspSessionPool;
use Porta\Presence\Beacon;
use Porta\Presence\PresenceBoard;
use Porta\Search\Everywhere\SearchEverywhereFactory;
use Porta\Search\Everywhere\TextSource;
use Porta\Settings\SettingsSearchLauncher;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\FakeRemoteTransport;
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
        $server = new TempProject(['README.md' => "# served\n\n\n\n\n", 'src/App.php' => "<?php\n\n\n// the line a search found\n"]);
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
                // Not in the locator; Settings' search is the service that holds it.
                $factory = Probe::object(Services::resolve($ide, SettingsSearchLauncher::class), 'pickers');
                $this->assertInstanceOf(SearchEverywhereFactory::class, $factory, 'the launcher builds its pickers with the factory');
                $text = \array_first(\array_filter(
                    Probe::get($factory->create(), 'asyncSources'),
                    static fn (object $source): bool => $source instanceof TextSource,
                ));
                $this->assertSame(Services::resolve($ide, RemoteFilesystem::class), Probe::get(Probe::object($text, 'search'), 'remote'), 'Search Everywhere greps the server too');
                $this->awaitPainted($tui, $terminal, 'README.md');
                $this->assertStringContainsString('src', AnsiUtils::stripAnsiCodes($terminal->getOutput()), 'the server\'s directory is in the tree');
                $this->assertSame('', \file_get_contents($cache->path('README.md')), 'listed, not fetched');
            },
            200 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($cache): void {
                Services::resolve($ide, BufferService::class)->openFile($cache->path('README.md'), focus: OpenFocus::Editor);
                $this->await(static fn (): bool => null !== Services::resolve($ide, BufferManager::class)->get((string) \realpath($cache->path('README.md'))));
                $this->assertSame("# served\n\n\n\n\n", $tui->getById('editor')->getDocument()->getText(), 'the content, not the placeholder');
                $tui->handleInput('X');
                $tui->handleInput("\x13"); // ctrl+s
            },
            300 => function () use ($server): void {
                $this->await(static fn (): bool => "X# served\n\n\n\n\n" === \file_get_contents($server->path('README.md')));
                $this->assertSame("X# served\n\n\n\n\n", \file_get_contents($server->path('README.md')), 'the save reached the server');
                // Where this side is, on the server's board, for whoever else
                // opens the same directory: the file relative, never the cache.
                $mine = static function () use ($server): ?Beacon {
                    $files = \glob($server->path(PresenceBoard::DIR.'/*.json'));

                    return false === $files || [] === $files ? null : Beacon::fromJson((string) \file_get_contents($files[0]));
                };
                $this->await(static fn (): bool => 'README.md' === $mine()?->path, what: 'this side\'s beacon on the server');
                $this->assertSame('ssh', $mine()?->via);
            },
            // A search hit opens its file and jumps to the line, in that order.
            // While the file is still on its way, the jump must not move the
            // caret of the file in front — and must not be lost either.
            400 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($cache): void {
                $transport = Probe::get(Services::resolve($ide, RemoteFilesystem::class), 'transport');
                $this->assertInstanceOf(FakeRemoteTransport::class, $transport, 'the project plugin serves the fake');
                $buffers = Services::resolve($ide, BufferManager::class);
                $readme = $buffers->active();
                Services::resolve($ide, RemoteFilesystem::class)->listed((string) \realpath($cache->path('src')));
                $transport->hold = true;
                Services::resolve($ide, BufferService::class)->openFile($cache->path('src/App.php'), focus: OpenFocus::Editor);
                $tui->getById('editor')->jumpTo(3, 0);
                $this->assertSame(0, $readme?->document->cursorLine, 'the file in front kept its caret');

                $transport->release();
                $this->await(static fn (): bool => \str_ends_with((string) $buffers->active()?->path, 'src/App.php'));
                $this->assertSame(3, $tui->getById('editor')->getDocument()->cursorLine, 'the jump landed in the file once it was here');
            },
        ]);

        $this->assertFileExists($cache->path('.porta/remote-index.json'), 'and what was fetched is remembered');
    }
}
