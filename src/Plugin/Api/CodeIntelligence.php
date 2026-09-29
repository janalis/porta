<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Editor\BufferManager;
use Porta\Lsp\LspQuery;

/**
 * What the IDE knows about the code, for a plugin: the diagnostics every
 * language server and analyser published on the open buffers, and questions
 * put to the language server for any file of the project — where a symbol is
 * declared, where it is used, what it is, which symbols match a name.
 *
 * Reached through {@see PluginApi::codeIntelligence()}, which is an action
 * method; this object holds the live IDE seams and asks at call time. One
 * accessor rather than five verbs on the API, which is at its size limit, and
 * because these belong together: all of them read what the servers say.
 *
 * Lines and columns are 0-based, columns in UTF-16 code units as the protocol
 * counts them. The server questions answer LATER, on the loop, with an error
 * that is null on success; a tool handler waits for one with
 * {@see \Porta\Support\FiberWait::for()}.
 */
final readonly class CodeIntelligence
{
    public function __construct(
        private LspQuery $query,
        private BufferManager $buffers,
    ) {}

    /**
     * What every source — language servers and analyser plugins alike — has
     * said about the OPEN buffers, merged the way the Problems panel shows it.
     * One file with `$path`, every open one without. A file with no open
     * buffer has none to read: diagnostics live on buffers.
     *
     * Lines and columns are 0-based; `severity` is the LSP scale, 1 = error
     * to 4 = hint.
     *
     * @return list<array{path: string, line: int, column: int, severity: int, message: string, source?: string, code?: string}>
     */
    public function diagnostics(?string $path = null): array
    {
        $rows = [];
        foreach ($this->buffers->all() as $buffer) {
            if (null !== $path && $path !== $buffer->path) {
                continue;
            }
            foreach ($buffer->diagnostics as $line => $diagnostics) {
                foreach ($diagnostics as $diagnostic) {
                    $row = [
                        'path' => $buffer->path,
                        'line' => $line,
                        'column' => $diagnostic['col'],
                        'severity' => $diagnostic['severity']->value,
                        'message' => $diagnostic['message'],
                    ];
                    if (\array_key_exists('source', $diagnostic)) {
                        $row['source'] = $diagnostic['source'];
                    }
                    if (\array_key_exists('code', $diagnostic)) {
                        $row['code'] = (string) $diagnostic['code'];
                    }
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Where the symbol at a position is DECLARED, asked of the language server
     * for that file — any file of the project, open or not; nothing is shown
     * and no tab moves. `$onDone` gets the locations (absolute path, 0-based
     * line and UTF-16 column, the line's text) and an error that is null on
     * success: no server for the language, or one that refused.
     *
     * The answer arrives later, on the loop. A tool handler waits for it with
     * `Porta\Support\FiberWait::for()`.
     *
     * @param \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void $onDone
     */
    public function definition(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->query->definition($path, $line, $column, $onDone);
    }

    /**
     * Every place the symbol at a position is USED, its declaration included —
     * {@see definition()}'s shape and rules.
     *
     * @param \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void $onDone
     */
    public function references(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->query->references($path, $line, $column, $onDone);
    }

    /**
     * What the language server says about the symbol at a position — its
     * signature and documentation — as plain text, or null when it says
     * nothing. {@see definition()}'s rules.
     *
     * @param \Closure(?string, ?string): void $onDone the text, and the error
     */
    public function hover(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->query->hover($path, $line, $column, $onDone);
    }

    /**
     * The project's symbols whose name matches $query, from the language
     * server already running (`workspace/symbol`); `kind` is the protocol's
     * SymbolKind number. With no server running there is nobody to ask, and
     * the error says so.
     *
     * @param \Closure(list<array{path: string, line: int, name: string, kind: int}>, ?string): void $onDone
     */
    public function symbols(string $query, \Closure $onDone): void
    {
        $this->query->workspaceSymbols($query, $onDone);
    }
}
