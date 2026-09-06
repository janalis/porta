<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\Lsp\InlineValueRequest;
use Symfony\Component\Tui\Tui;

/**
 * The debugger's variable values, drawn beside the lines that assign them.
 *
 * A JOIN between two protocols that do not know about each other: the language
 * server answers `textDocument/inlineValue` with ranges and variable NAMES and
 * no values, while the debug adapter answers `variables` with names and VALUES
 * and no idea where in the file they were written. Neither half is useful
 * alone, and nothing else in the tree needs either of them joined — which is
 * why this is one small class rather than a method on the controller that
 * already carries eighteen dependencies.
 *
 * It lives on the DEBUG side because that is the side that knows WHEN the join
 * means anything: a variable's value is a fact about one stopped frame, and it
 * stops being true the moment the debuggee moves.
 */
#[AsIdeService]
final class InlineValues
{
    /**
     * Lines fetched either side of the stopped line. The values are only worth
     * drawing where you are looking, and where you are looking after a stop is
     * the stopped line — a viewport away in either direction covers a scroll
     * without a second round-trip.
     */
    private const int MARGIN_LINES = 40;

    /** @var list<array{line: int, endCol: int, name: string}> where the server says the variables are */
    private array $lookups = [];

    /** @var array<string, string> name => value, as the adapter reports them */
    private array $values = [];

    public function __construct(
        private readonly InlineValueRequest $request,
        private readonly EditorDecorationSink $decorations,
        private readonly Tui $tui,
    ) {}

    /**
     * The debuggee stopped: ask where the variables are, and forget everything
     * about the frame that just went away.
     */
    public function stoppedAt(string $path, int $line, int $frameId): void
    {
        $this->clear();
        $this->request->forRange($path, max(0, $line - self::MARGIN_LINES), $line + self::MARGIN_LINES, $frameId, $line, function (array $lookups): void {
            $this->lookups = $lookups;
            $this->paint();
        });
    }

    /**
     * Values from one of the stopped frame's scopes. They arrive a scope at a
     * time and in no particular order, so each delivery adds to what is known
     * and repaints — the alternative is waiting for a "last scope" that the
     * protocol never announces.
     *
     * @param list<array{name: string, value: string, variablesReference: int}> $variables
     */
    public function addVariables(array $variables): void
    {
        foreach ($variables as $variable) {
            $this->values[$variable['name']] = $variable['value'];
        }
        $this->paint();
    }

    /** The debuggee moved or the session ended: what was true is not any more. */
    public function clear(): void
    {
        $this->lookups = [];
        $this->values = [];
        $this->decorations->setDebugValues([]);
    }

    private function paint(): void
    {
        $byLine = [];
        foreach ($this->lookups as $lookup) {
            $value = $this->values[$lookup['name']] ?? null;
            if (null === $value) {
                continue; // named by the server, not reported by the adapter
            }
            $byLine[$lookup['line']][] = ['col' => $lookup['endCol'], 'text' => $lookup['name'].' = '.$value];
        }

        $rendered = [];
        foreach ($byLine as $line => $entries) {
            // Left to right, so a line assigning two variables reads in the
            // order the code does.
            usort($entries, static fn (array $a, array $b): int => $a['col'] <=> $b['col']);
            $rendered[$line] = implode(', ', array_column($entries, 'text'));
        }
        $this->decorations->setDebugValues($rendered);
        $this->tui->requestRender();
    }
}
