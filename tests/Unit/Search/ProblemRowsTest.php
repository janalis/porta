<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Search\ProblemRows;
use Porta\Support\ProjectPaths;

final class ProblemRowsTest extends TestCase
{
    public function testFlattensMarksAndSortsOpenBuffersDiagnostics(): void
    {
        $buffers = new BufferManager();
        $b = $buffers->open('/p/src/B.php', "<?php\n");
        $a = $buffers->open('/p/src/A.php', "<?php\n");
        $b->diagnostics = [
            0 => [
                ['severity' => 2, 'message' => 'warn here', 'col' => 0, 'endCol' => 1],
                ['severity' => 3, 'message' => 'info here', 'col' => 0, 'endCol' => 1],
                ['severity' => 4, 'message' => 'hint here', 'col' => 0, 'endCol' => 1],
            ],
        ];
        $a->diagnostics = [
            2 => [['severity' => 1, 'message' => 'later error', 'col' => 0, 'endCol' => 1]],
            0 => [['severity' => 1, 'message' => 'top error', 'col' => 0, 'endCol' => 1]],
        ];

        $rows = new ProblemRows($buffers, new ProjectPaths('/p'))->build();

        $this->assertSame(
            [
                ['path' => 'src/A.php', 'line' => 0, 'text' => '✗ top error'],
                ['path' => 'src/A.php', 'line' => 2, 'text' => '✗ later error'],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '▲ warn here'],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '◦ info here'],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '· hint here'],
            ],
            $rows,
            'sorted by path then line, every severity keeps its own glyph',
        );
    }

    public function testNoOpenBuffersYieldNoRows(): void
    {
        $this->assertSame([], new ProblemRows(new BufferManager(), new ProjectPaths('/p'))->build());
    }

    public function testTheRULEThatFiredRidesAtTheEndOfTheRow(): void
    {
        // At the END, not in front: this is the thing you search for to
        // suppress a rule or to tell two identically-worded findings apart, and
        // it is not the thing you read the row FOR. Three bundled analyser
        // plugins used to prefix it into the message by hand, which put it
        // between the reader and the sentence.
        $buffers = new BufferManager();
        $buffer = $buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [
            0 => [['severity' => 2, 'message' => 'Name "Other" is imported but not used', 'col' => 0, 'endCol' => 1, 'code' => 'worse.unused_import']],
            1 => [['severity' => 1, 'message' => 'no rule given', 'col' => 0, 'endCol' => 1]],
        ];

        $rows = new ProblemRows($buffers, new ProjectPaths('/p'))->build();

        $this->assertSame('▲ Name "Other" is imported but not used  worse.unused_import', $rows[0]['text']);
        $this->assertSame('✗ no rule given', $rows[1]['text'], 'and nothing is appended when the source named no rule');
    }

    public function testANUMERICRuleIsShownToo(): void
    {
        // The spec allows an integer code, and "1005" is as much a handle as
        // "worse.unused_import" — a numeric one must not be mistaken for absent.
        $buffers = new BufferManager();
        $buffer = $buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 1, 'code' => 1005]]];

        $this->assertSame('✗ boom  1005', new ProblemRows($buffers, new ProjectPaths('/p'))->build()[0]['text']);
    }
}
