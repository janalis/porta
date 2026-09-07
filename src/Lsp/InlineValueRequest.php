<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;

/**
 * Asks the server WHERE the variables are on the lines around a stopped
 * debugger, so their values can be drawn beside them.
 *
 * The split is the point: `textDocument/inlineValue` answers with ranges and
 * variable NAMES and no values at all — the values live in the debug adapter,
 * which is a different protocol, a different process and, here, a different
 * namespace. So this class answers the LSP half and nothing else, and
 * {@see \Porta\Debug\DebugController} does the join, because it is the side
 * that knows when the join is even meaningful.
 *
 * Measured on phpactor, stopped inside a method with three locals: one
 * `InlineValueVariableLookup` per variable, `{range, variableName,
 * caseSensitiveLookup}`. phpantom_lsp does not advertise the capability, and
 * the gate below means it is never asked.
 */
#[AsIdeService]
final readonly class InlineValueRequest
{
    public function __construct(
        private LspSessionPool $lsp,
    ) {}

    /**
     * Ask for the variables between $fromLine and $toLine (0-based, inclusive).
     *
     * $deliver is called only on a usable answer: an empty result means the
     * server had nothing to say about this range, which is not the same as the
     * previous answer still being true, but it IS the same as having nothing to
     * draw — and the caller clears either way.
     *
     * Returns whether the server was ASKED, so a caller that has another way to
     * find the variables knows when to use it: phpantom_lsp does not offer this
     * request at all, and silence from here is indistinguishable from an answer
     * that has not arrived yet.
     *
     * @param \Closure(list<array{line: int, endCol: int, name: string}>): void $deliver
     */
    public function forRange(string $path, int $fromLine, int $toLine, int $frameId, int $stoppedLine, \Closure $deliver): bool
    {
        if (true !== $this->lsp->serverCapability($path, 'inlineValueProvider')) {
            return false;
        }
        $this->lsp->request(
            'textDocument/inlineValue',
            [
                'textDocument' => ['uri' => Uri::fromPath($path)],
                'range' => [
                    'start' => ['line' => $fromLine, 'character' => 0],
                    'end' => ['line' => $toLine, 'character' => 0],
                ],
                // The stopped location is what makes the answer frame-specific: a
                // server may report only the variables already assigned by the time
                // execution reached this line.
                'context' => [
                    'frameId' => $frameId,
                    'stoppedLocation' => [
                        'start' => ['line' => $stoppedLine, 'character' => 0],
                        'end' => ['line' => $stoppedLine, 'character' => 0],
                    ],
                ],
            ],
            static function (mixed $result) use ($deliver): void {
                $lookups = LspMapper::inlineValueLookups($result);
                if ([] !== $lookups) {
                    $deliver($lookups);
                }
            },
        );

        return true;
    }
}
