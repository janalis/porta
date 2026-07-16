<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;

/**
 * Builds the Problems tool's rows: every open buffer's diagnostics flattened
 * into one path/line-sorted list with a severity marker (✗ errors, ▲ the
 * rest) prepended to the message. Pure over the buffer state, so the initial
 * Show Problems open and the live refresh render identical rows.
 */
final readonly class ProblemRows
{
    public function __construct(
        private BufferManager $buffers,
        private string $rootDir,
    ) {}

    /** @return list<array{path: string, line: int, text: string}> */
    public function build(): array
    {
        $results = [];
        foreach ($this->buffers->all() as $buffer) {
            $rel = ProjectPaths::relative($this->rootDir, $buffer->path);
            foreach ($buffer->diagnostics as $line => $list) {
                foreach ($list as $diag) {
                    $marker = 1 === $diag['severity'] ? '✗' : '▲';
                    $results[] = [
                        'path' => $rel,
                        'line' => $line,
                        'text' => $marker.' '.$diag['message'],
                    ];
                }
            }
        }

        // Sort by file then line for a stable, scannable list.
        usort($results, static fn (array $a, array $b) => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $results;
    }
}
