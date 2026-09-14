<?php

declare(strict_types=1);

namespace Porta\Debug;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\Lsp\InlineValueRequest;
use Porta\Widget\CodeEditorWidget;
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
        private readonly CodeEditorWidget $editor,
    ) {}

    /**
     * The debuggee stopped: ask where the variables are, and forget everything
     * about the frame that just went away.
     */
    public function stoppedAt(string $path, int $line, int $frameId): void
    {
        $this->clear();
        $from = \max(0, $line - self::MARGIN_LINES);
        $to = $line + self::MARGIN_LINES;
        $asked = $this->request->forRange($path, $from, $to, $frameId, $line, function (array $lookups): void {
            $this->lookups = $lookups;
            $this->paint();
        });
        if (!$asked) {
            // The server does not offer `inlineValue` — phpantom_lsp, which is
            // most PHP setups. Its SEMANTIC TOKENS answer the same question
            // exactly: measured, it types `$count` and `$times` as `variable`
            // and `parameter` with ranges whose text INCLUDES the `$`, which is
            // how the debug adapter spells a name. Porta already asks for the
            // whole document's tokens for colouring, so this costs nothing and
            // guesses nothing.
            // No paint here: `clear()` above emptied the values, so a paint
            // now could only ever draw the empty map it just wrote. The next
            // `addVariables()` is what puts anything on screen — and the
            // controller always stops before it loads the frame's scopes.
            $this->lookups = $this->variablesFromSemanticTokens($from, $to);
        }
    }

    /**
     * The variables the colouring pass already found, as inline-value lookups.
     *
     * Only `variable` and `parameter`: a `property` token is `name` in
     * `$this->name` — no `$`, and not a name the frame's variables are keyed
     * by — while the declaration `public string $name` is typed `property` too
     * and is not a local at all. `$this` comes through as a variable and the
     * adapter reports it, so it joins like any other.
     *
     * @return list<array{line: int, endCol: int, name: string}>
     */
    private function variablesFromSemanticTokens(int $fromLine, int $toLine): array
    {
        $document = $this->editor->getDocument();
        $decorations = $this->editor->decorations;

        $lookups = [];
        foreach (\range($fromLine, $toLine) as $line) {
            foreach ($decorations->semanticTokensAt($line) as $token) {
                if ('variable' !== $token['type'] && 'parameter' !== $token['type']) {
                    continue;
                }
                $name = \mb_substr($document->getLine($line), $token['col'], $token['endCol'] - $token['col']);
                if ('' === $name) {
                    continue;
                }
                $lookups[] = ['line' => $line, 'endCol' => $token['endCol'], 'name' => $name];
            }
        }

        return $lookups;
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
            \usort($entries, static fn (array $a, array $b): int => $a['col'] <=> $b['col']);
            $rendered[$line] = \implode(', ', \array_column($entries, 'text'));
        }
        $this->decorations->setDebugValues($rendered);
        $this->tui->requestRender();
    }
}
