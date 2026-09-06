<?php

declare(strict_types=1);

namespace Porta\Search;

use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticStyle;
use Porta\Support\ProjectPaths;

/**
 * Builds the Problems tool's rows: every open buffer's diagnostics flattened
 * into one path/line-sorted list with its severity's glyph
 * ({@see DiagnosticStyle::glyph}) prepended to the message. Pure over the
 * buffer state, so the initial Show Problems open and the live refresh render
 * identical rows.
 */
final readonly class ProblemRows
{
    public function __construct(
        private BufferManager $buffers,
        private ProjectPaths $paths,
    ) {}

    /**
     * The rows for the Problems panel.
     *
     * The rule's own name rides at the end when the source gave one —
     * `worse.unused_import`, `no-empty-catch-clause`. It is what you search for
     * to suppress a rule, what you paste into an issue, and the only way to
     * tell two identically-worded findings apart. It is NOT in the message:
     * three bundled analyser plugins used to prefix it there by hand, which put
     * it in front of the sentence you were trying to read.
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    public function build(): array
    {
        $results = [];
        foreach ($this->buffers->all() as $buffer) {
            $rel = $this->paths->relative($buffer->path);
            foreach ($buffer->diagnostics as $line => $list) {
                foreach ($list as $diag) {
                    $marker = DiagnosticStyle::glyph($diag['severity']);
                    // Not cast: the shape says `int|string`, and both compare
                    // false against '' and concatenate the same. A cast here
                    // would be a step nothing could observe.
                    $code = $diag['code'] ?? '';
                    $results[] = [
                        'path' => $rel,
                        'line' => $line,
                        'text' => $marker.' '.$diag['message'].('' === $code ? '' : '  '.$code),
                    ];
                }
            }
        }

        // Sort by file then line for a stable, scannable list.
        usort($results, static fn (array $a, array $b) => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $results;
    }
}
