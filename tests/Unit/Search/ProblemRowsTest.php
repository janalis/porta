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
}
