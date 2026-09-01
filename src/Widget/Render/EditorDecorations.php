<?php

declare(strict_types=1);

namespace Porta\Widget\Render;

use Porta\Editor\Caret;

/**
 * The per-line decoration inputs for one editor frame, gathered once before the
 * row loop: whether multiple carets are active (which suppresses the range
 * selection and the hardware cursor), the primary selection range, every caret
 * record, the same-identifier occurrences in view, and the matching-bracket
 * pair. Passed as one value so the row-assembly helper does not take five more
 * positional arguments.
 */
final readonly class EditorDecorations
{
    /**
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $selection    null while multiple carets are active
     * @param list<Caret>    $carets
     * @param list<array{0: int, 1: int, 2: int, 3: bool}>                            $occurrences [line, startDisplayCol, endDisplayCol, isWrite]
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null       $brackets
     */
    public function __construct(
        public bool $multiActive,
        public ?array $selection,
        public array $carets,
        public array $occurrences,
        public ?array $brackets,
    ) {}
}
