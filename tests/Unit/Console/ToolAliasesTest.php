<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\ToolAliases;

final class ToolAliasesTest extends TestCase
{
    public function testTheToolWordsAreTheirOptions(): void
    {
        $this->assertSame(['porta', '--diff', 'a.php', 'b.php'], ToolAliases::expand(['porta', 'diff', 'a.php', 'b.php']));
        $this->assertSame(['porta', '--merge', 'L', 'R', 'B', 'M'], ToolAliases::expand(['porta', 'merge', 'L', 'R', 'B', 'M']));
    }

    public function testAWordAloneStillBecomesItsOptionForTheCommandToRefuse(): void
    {
        // The command then says what --diff needs, rather than opening a folder named diff.
        $this->assertSame(['porta', '--diff'], ToolAliases::expand(['porta', 'diff']));
    }

    public function testAnythingElseIsLeftAlone(): void
    {
        foreach ([['porta'], ['porta', 'src/'], ['porta', './diff'], ['porta', 'Diff'], ['porta', 'src', 'diff'], ['porta', 'review', 'list']] as $argv) {
            $this->assertSame($argv, ToolAliases::expand($argv));
        }
    }
}
