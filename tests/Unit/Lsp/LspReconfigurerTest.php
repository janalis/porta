<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Lsp;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Lsp\BufferReopener;
use Porta\Lsp\LanguageServerProvider;
use Porta\Lsp\LspReconfigurer;
use Porta\Lsp\LspSessionPool;
use Porta\Tests\Support\FixedActiveFile;

final class LspReconfigurerTest extends TestCase
{
    private int $reopened = 0;

    private function reconfigurer(LspSessionPool $pool, ?string $lspCommand): LspReconfigurer
    {
        $this->reopened = 0;
        $reopened = &$this->reopened;

        return new LspReconfigurer(
            $pool,
            new class($reopened) implements BufferReopener {
                public function __construct(
                    private int &$reopened,
                ) {}

                public function reopenAllInLsp(): void
                {
                    ++$this->reopened;
                }
            },
            new class implements LanguageServerProvider {
                public function languageServers(): array
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
}
