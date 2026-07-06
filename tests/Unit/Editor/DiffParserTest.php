<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiffParser;

final class DiffParserTest extends TestCase
{
    public function testClassifiesEveryRowKind(): void
    {
        $raw = <<<'DIFF'
            diff --git a/a.txt b/a.txt
            index 111..222 100644
            --- a/a.txt
            +++ b/a.txt
            @@ -1,3 +1,3 @@
             context line
            -removed line
            +added line
            \ No newline at end of file
            DIFF;

        $rows = DiffParser::parse($raw);
        $kinds = array_column($rows, 'kind');

        $this->assertSame(
            [
                'meta', // diff --git
                'meta', // index
                'meta', // --- a/a.txt
                'meta', // +++ b/a.txt
                'hunk', // @@ ...
                'context', // " context line"
                'del', // -removed line
                'add', // +added line
                'meta', // \ No newline
            ],
            $kinds,
        );
        $this->assertSame('-removed line', $rows[6]['text']);
    }

    public function testEmptyOrWhitespaceDiffIsNoRows(): void
    {
        $this->assertSame([], DiffParser::parse(''));
        $this->assertSame([], DiffParser::parse("   \n  \n"));
    }

    public function testTrailingNewlineDoesNotAddBlankRow(): void
    {
        $rows = DiffParser::parse("@@ -1 +1 @@\n+x\n");
        $this->assertCount(2, $rows);
        $this->assertSame('hunk', $rows[0]['kind']);
        $this->assertSame('add', $rows[1]['kind']);
    }

    public function testRenameAndModeHeadersAreMeta(): void
    {
        $rows = DiffParser::parse("old mode 100644\nnew mode 100755\nrename from x\nrename to y");
        $this->assertSame(['meta', 'meta', 'meta', 'meta'], array_column($rows, 'kind'));
    }
}
