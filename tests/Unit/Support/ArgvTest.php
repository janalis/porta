<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Porta\Support\Argv;

/**
 * The shared quote-aware command tokenizer behind the three user-configured
 * command seams (LSP server, debug adapter, run command).
 */
final class ArgvTest extends TestCase
{
    public function testKeepsPlainCommandsAsBefore(): void
    {
        $this->assertSame(['php', 'vendor/bin/lsp', '--stdio'], Argv::split('  php   vendor/bin/lsp  --stdio '));
        $this->assertSame([], Argv::split('   '));
        $this->assertSame([], Argv::split(''));
    }

    public function testHonorsQuotedArgumentsWithSpaces(): void
    {
        // The whole reason the tokenizer exists: a binary under a path with
        // spaces used to be broken into two argv entries and never spawn.
        $this->assertSame(['php', '/path with spaces/lsp.php'], Argv::split('php "/path with spaces/lsp.php"'));
        $this->assertSame(['php', '/path with spaces/lsp.php'], Argv::split("php '/path with spaces/lsp.php'"));
    }

    public function testJoinsAdjacentQuotedAndBareRunsIntoOneToken(): void
    {
        $this->assertSame(['ab', 'c d'], Argv::split('"a"\'b\' c" d"'));
        $this->assertSame(['--root=/a b/c'], Argv::split('--root="/a b/c"'));
    }

    public function testUnescapesQuoteAndBackslashInsideDoubleQuotes(): void
    {
        $this->assertSame(['say "hi"', 'a\\b'], Argv::split('"say \\"hi\\"" "a\\\\b"'));
        // Inside single quotes and before other characters, a backslash is literal.
        $this->assertSame(['a\\nb', '\\x'], Argv::split("'a\\nb' \"\\x\""));
    }

    public function testDegradesOnUnterminatedQuotesAndKeepsEmptyQuotedArguments(): void
    {
        // A config seam degrades instead of throwing: the open quote runs out.
        $this->assertSame(['php', 'a b'], Argv::split('php "a b'));
        // A deliberately quoted empty argument survives as one argv entry.
        $this->assertSame(['lsp', ''], Argv::split("lsp ''"));
    }
}
