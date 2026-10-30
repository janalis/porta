<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FixedActiveFile;

final class LspReconfigurerTest extends TestCase
{
    private int $reopened = 0;

    /** @var array<string, non-empty-list<array{command: list<string>, name: string}>> the plugins' servers run elsewhere */
    private array $relayed = [];

    private function reconfigurer(LspSessionPool $pool, ?string $lspCommand): LspReconfigurer
    {
        $this->reopened = 0;
        $reopened = &$this->reopened;

        return new LspReconfigurer(
            $pool,
            static function () use (&$reopened): void {
                ++$reopened;
            },
            new readonly class(fn (): array => $this->relayed) implements LanguageServerProvider {
                /** @param \Closure(): array<string, non-empty-list<array{command: list<string>, name: string}>> $relayed */
                public function __construct(
                    private \Closure $relayed,
                ) {}

                public function languageServers(): array
                {
                    return ['php' => [['command' => ['php-ls'], 'name' => 'php-ls']]];
                }

                public function relayedServers(): array
                {
                    return ($this->relayed)();
                }

                public function missingServers(): array
                {
                    return [];
                }
            },
            '/tmp/proj',
            $lspCommand,
        );
    }

    public function testIsOverriddenRequiresANonEmptyCommand(): void
    {
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile(), new Translator('en'));
        $this->assertFalse($this->reconfigurer($pool, null)->isOverridden(), 'no override at all');
        $this->assertFalse($this->reconfigurer($pool, '')->isOverridden(), 'an empty string is not a real override');
        $this->assertTrue($this->reconfigurer($pool, 'my-server --stdio')->isOverridden());
    }

    public function testReconfigureResolvesTheServersAndReopensBuffers(): void
    {
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile(), new Translator('en'));
        $pool->configure([], static function (string $line): void {}, static function (array $params): void {}, static fn (array $edit): bool => false);
        $reconfigurer = $this->reconfigurer($pool, 'my-server --stdio');

        $reconfigurer->reconfigure();

        $this->assertSame(1, $this->reopened, 'buffers reopen so the new server relaunches');
        $this->assertSame('my-server', $pool->statusFor('/tmp/proj/a.php'), 'the resolved override server was applied to the pool');
    }

    public function testAReconfigureThatResolvesTheSameMapDoesNotReopenBuffers(): void
    {
        // The provider-setting hook fires for EVERY setting of a provider
        // plugin; when nothing about the resolved servers changed, reopening
        // every buffer would relaunch every session for a toggle the LSP
        // never sees.
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile(), new Translator('en'));
        $pool->configure([], static function (string $line): void {}, static function (array $params): void {}, static fn (array $edit): bool => false);
        $reconfigurer = $this->reconfigurer($pool, 'my-server --stdio');

        $reconfigurer->reconfigure();
        $this->assertSame(1, $this->reopened, 'the first resolve applies');

        $reconfigurer->reconfigure();
        $this->assertSame(1, $this->reopened, 'the identical second resolve is a no-op');
    }

    /**
     * The install-a-binary path relaunches through an identical map.
     *
     * That is the difference between {@see LspReconfigurer::relaunch()} and
     * {@see LspReconfigurer::reconfigure()}, and the whole reason the former
     * exists: an UPGRADE lands the new server at the same path, so the argv is
     * byte-identical, the reconfigure skip declines to act, and the old process
     * keeps answering while the installer says it succeeded. Written because a
     * mutation swapping relaunch() back to reconfigure() passed every other
     * test in the suite.
     */
    public function testRelaunchReopensBuffersEvenWhenTheMapIsUnchanged(): void
    {
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile(), new Translator('en'));
        $pool->configure([], static function (string $line): void {}, static function (array $params): void {}, static fn (array $edit): bool => false);
        $reconfigurer = $this->reconfigurer($pool, 'my-server --stdio');

        $reconfigurer->reconfigure();
        $this->assertSame(1, $this->reopened);

        $reconfigurer->relaunch();

        $this->assertSame(2, $this->reopened, 'the same map still relaunches');
        $this->assertSame('my-server', $pool->statusFor('/tmp/proj/a.php'), 'and the map survives the forced drop');
    }

    /**
     * A restricted session's map is still only the servers run elsewhere —
     * as the boot handed it — and follows them: assist turned off takes its
     * relay away, turned on brings it, a relaunch the same.
     */
    public function testARestrictedSessionIsHandedOnlyTheServersRunElsewhere(): void
    {
        $pool = new LspSessionPool('/tmp/proj', new FixedActiveFile(), new Translator('en'));
        $pool->restrict();
        $pool->configure([], static function (string $line): void {}, static function (array $params): void {}, static fn (array $edit): bool => false);
        $reconfigurer = $this->reconfigurer($pool, null);

        $this->relayed = ['php' => [['command' => ['porta', 'assist-lsp', 'php'], 'name' => 'assist']]];
        $reconfigurer->reconfigure();
        $this->assertSame('assist', $pool->statusFor('/tmp/proj/a.php'), 'never the php-ls the plugins also name');

        $this->relayed = [];
        $reconfigurer->reconfigure();
        $this->assertSame('off — project not trusted', $pool->statusFor('/tmp/proj/a.php'));

        $this->relayed = ['php' => [['command' => ['porta', 'assist-lsp', 'php'], 'name' => 'assist']]];
        $reconfigurer->relaunch();
        $this->assertSame('assist', $pool->statusFor('/tmp/proj/a.php'));
        $this->assertSame(3, $this->reopened, 'each change reopened the buffers');
    }
}
