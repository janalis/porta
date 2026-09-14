<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticSeverity;
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
                ['severity' => DiagnosticSeverity::Warning, 'message' => 'warn here', 'col' => 0, 'endCol' => 1],
                ['severity' => DiagnosticSeverity::Information, 'message' => 'info here', 'col' => 0, 'endCol' => 1],
                ['severity' => DiagnosticSeverity::Hint, 'message' => 'hint here', 'col' => 0, 'endCol' => 1],
            ],
        ];
        $a->diagnostics = [
            2 => [['severity' => DiagnosticSeverity::Error, 'message' => 'later error', 'col' => 0, 'endCol' => 1]],
            0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'top error', 'col' => 0, 'endCol' => 1]],
        ];

        $rows = new ProblemRows($buffers, new ProjectPaths('/p'))->build();

        $this->assertSame(
            [
                ['path' => 'src/A.php', 'line' => 0, 'text' => '✗ top error', 'tail' => ''],
                ['path' => 'src/A.php', 'line' => 2, 'text' => '✗ later error', 'tail' => ''],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '▲ warn here', 'tail' => ''],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '◦ info here', 'tail' => ''],
                ['path' => 'src/B.php', 'line' => 0, 'text' => '· hint here', 'tail' => ''],
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
            0 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'Name "Other" is imported but not used', 'col' => 0, 'endCol' => 1, 'code' => 'worse.unused_import']],
            1 => [['severity' => DiagnosticSeverity::Error, 'message' => 'no rule given', 'col' => 0, 'endCol' => 1]],
        ];

        $rows = new ProblemRows($buffers, new ProjectPaths('/p'))->build();

        // Its own field, not concatenated: the end of a row is the first thing
        // a narrow panel throws away, and the panel can only protect what it
        // can see separately from the sentence.
        $this->assertSame('▲ Name "Other" is imported but not used', $rows[0]['text']);
        $this->assertSame('worse.unused_import', $rows[0]['tail']);
        $this->assertSame('✗ no rule given', $rows[1]['text'], 'and nothing rides along when the source named no rule');
        $this->assertSame('', $rows[1]['tail']);
    }

    public function testANUMERICRuleIsShownToo(): void
    {
        // The spec allows an integer code, and "1005" is as much a handle as
        // "worse.unused_import" — a numeric one must not be mistaken for absent.
        $buffers = new BufferManager();
        $buffer = $buffers->open('/p/src/A.php', "<?php\n");
        $buffer->diagnostics = [0 => [['severity' => DiagnosticSeverity::Error, 'message' => 'boom', 'col' => 0, 'endCol' => 1, 'code' => 1005]]];

        // Cast where it is now observable: the field is a string, so a
        // numeric code has to arrive as text rather than as an int.
        $this->assertSame('1005', new ProblemRows($buffers, new ProjectPaths('/p'))->build()[0]['tail']);
    }
}
