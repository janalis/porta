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
     * It travels as its OWN field rather than concatenated onto the message,
     * because the end of a row is the first thing a narrow panel throws away —
     * and at 100 columns it always did, on exactly the long messages where a
     * rule name is the only thing telling two findings apart. The panel
     * reserves its width and elides the sentence instead.
     *
     * @return list<array{path: string, line: int, text: string, tail: string}>
     */
    public function build(): array
    {
        $results = [];
        foreach ($this->buffers->all() as $buffer) {
            $rel = $this->paths->relative($buffer->path);
            foreach ($buffer->diagnostics as $line => $list) {
                foreach ($list as $diag) {
                    $marker = DiagnosticStyle::glyph($diag['severity']);
                    // Cast now, where it IS observable: the shape says
                    // `int|string` and the field is a string, so a numeric
                    // code from a server that sends one has to arrive as text
                    // rather than as an int the renderer would have to widen.
                    $code = (string) ($diag['code'] ?? '');
                    $results[] = [
                        'path' => $rel,
                        'line' => $line,
                        'text' => $marker.' '.$diag['message'],
                        'tail' => $code,
                    ];
                }
            }
        }

        // Sort by file then line for a stable, scannable list.
        \usort($results, static fn (array $a, array $b): int => [$a['path'], $a['line']] <=> [$b['path'], $b['line']]);

        return $results;
    }
}
