<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\Event\FileDeleted;
use Porta\Event\FileRenamed;
use Porta\I18n\Translator;
use Porta\Lsp\LspPathListener;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FakeLspClient;
use Porta\Tests\Support\FixedActiveFile;

/**
 * The two facts that retire a document at the server.
 *
 * Both are asserted from the CLIENT's outbound notifications rather than from
 * the pool's own state: what matters is that the server was told, and the pool
 * believing it sent something is not that.
 */
final class LspPathListenerTest extends TestCase
{
    private FakeLspClient $client;

    /** @return array{0: LspPathListener, 1: LspSessionPool} */
    private function listener(): array
    {
        $pool = new LspSessionPool(
            '/tmp/proj',
            new FixedActiveFile(),
            new Translator('en'),
            fn (array $command, string $rootDir, \Closure $onLog): FakeLspClient => $this->client = new FakeLspClient(),
        );
        $pool->configure(
            ['php' => ['command' => ['php-ls'], 'name' => 'php-ls']],
            static function (string $line): void {},
            static function (array $params): void {},
            static fn (array $edit): bool => false,
        );

        return [new LspPathListener($pool), $pool];
    }

    /** @return list<string> every URI the client was asked to close, in order */
    private function closed(): array
    {
        $uris = [];
        foreach ($this->client->notifications as $sent) {
            if ('textDocument/didClose' !== $sent['method']) {
                continue;
            }
            $uris[] = (string) $sent['params']['textDocument']['uri'];
        }

        return $uris;
    }

    /**
     * The OLD path is closed and the new one is left alone — the mover has
     * already opened it, and closing it here would undo that.
     */
    public function testARenameClosesTheOldPathAndNotTheNewOne(): void
    {
        [$listener, $pool] = $this->listener();
        $pool->notifyDidOpen('/tmp/proj/old.php', '<?php', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/src/old.php', '<?php', 'php', 1); // where the mover put it

        $listener->onFileRenamed(new FileRenamed('/tmp/proj/old.php', '/tmp/proj/src/old.php'));

        $this->assertSame(['file:///tmp/proj/old.php'], $this->closed());
    }

    /** A renamed FOLDER retires every file that was open inside it. */
    public function testARenamedFolderClosesTheFilesThatWereInsideIt(): void
    {
        [$listener, $pool] = $this->listener();
        $pool->notifyDidOpen('/tmp/proj/Old/a.php', '<?php', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/Old/deep/b.php', '<?php', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/Kept/c.php', '<?php', 'php', 1);

        $listener->onFileRenamed(new FileRenamed('/tmp/proj/Old', '/tmp/proj/New'));

        $this->assertSame(['file:///tmp/proj/Old/a.php', 'file:///tmp/proj/Old/deep/b.php'], $this->closed());
    }

    public function testADeleteClosesThePathToo(): void
    {
        [$listener, $pool] = $this->listener();
        $pool->notifyDidOpen('/tmp/proj/gone.php', '<?php', 'php', 1);
        $pool->notifyDidOpen('/tmp/proj/stays.php', '<?php', 'php', 1);

        $listener->onFileDeleted(new FileDeleted('/tmp/proj/gone.php'));

        $this->assertSame(['file:///tmp/proj/gone.php'], $this->closed());
    }

    /** No server running at all: the facts still arrive, and nothing may throw. */
    public function testBothFactsAreInertWithNoSessionStarted(): void
    {
        [$listener, $pool] = $this->listener();

        $listener->onFileRenamed(new FileRenamed('/tmp/proj/a.php', '/tmp/proj/b.php'));
        $listener->onFileDeleted(new FileDeleted('/tmp/proj/a.php'));

        $this->assertFalse($pool->isActive(), 'confirms no session existed, so the two calls above were inert');
    }
}
