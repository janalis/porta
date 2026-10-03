<?php

declare(strict_types=1);

namespace Porta\Plugin\Api;

use Porta\Editor\BufferManager;
use Porta\Editor\DocumentReplacer;
use Porta\Lsp\LspQuery;
use Porta\Lsp\WorkspaceEditor;
use Porta\Support\JsonRpcTransport;

/**
 * What the IDE knows about the code, for a plugin: the diagnostics every
 * language server and analyser published on the open buffers, and questions
 * put to the language server for any file of the project — where a symbol is
 * declared, where it is used, what it is, which symbols match a name, what a
 * file declares — and the two changes it computes: a rename and a format.
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
        private WorkspaceEditor $edits,
        private DocumentReplacer $documents,
    ) {}

    /**
     * The language servers this IDE starts, one per language — the first it
     * would ask — with the argv that launches each and the initialization
     * options it is given: for a plugin that runs one the same way on someone
     * else's behalf, a collaborator's, say. Keyed by language id.
     *
     * @return array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>
     */
    public function languageServers(): array
    {
        return $this->query->primaryServers();
    }

    /**
     * The transport that runs $server — one of {@see languageServers()} — the
     * way this IDE runs its own, in $rootDir, and that ends with the IDE
     * however the IDE ends, as the IDE's own servers do. Not started: its
     * owner starts and stops it. $peerName is how its log lines name it.
     *
     * @param array{command: list<string>, ...} $server
     */
    public function serverTransport(array $server, string $rootDir, string $peerName): JsonRpcTransport
    {
        return $this->query->serverTransport($server, $rootDir, $peerName);
    }

    /**
     * What every source — language servers and analyser plugins alike — has
     * said about the OPEN buffers, merged the way the Problems panel shows it.
     * One file with `$path`, every open one without. A file with no open
     * buffer has none to read: diagnostics live on buffers.
     *
     * Lines and columns are 0-based; `endColumn` is where the finding ends
     * when it ends on its own line. `severity` is the LSP scale, 1 = error
     * to 4 = hint.
     *
     * @return list<array{path: string, line: int, column: int, endColumn?: int, severity: int, message: string, source?: string, code?: string}>
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
                    $row = ['path' => $buffer->path, 'line' => $line, 'column' => $diagnostic['col']];
                    // One that runs onto later lines is kept to the end of its first.
                    $end = $diagnostic['endCol'] ?? \PHP_INT_MAX;
                    if (\PHP_INT_MAX !== $end) {
                        $row['endColumn'] = $end;
                    }
                    $row += ['severity' => $diagnostic['severity']->value, 'message' => $diagnostic['message']];
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

    /**
     * What one file declares — its classes, their methods and properties,
     * its functions — outermost first, each with how deep it is nested and
     * where it is named. {@see definition()}'s rules; `kind` is the protocol's
     * SymbolKind number.
     *
     * @param \Closure(list<array{name: string, kind: int, line: int, column: int, depth: int}>, ?string): void $onDone
     */
    public function outline(string $path, \Closure $onDone): void
    {
        $this->query->documentSymbols($path, $onDone);
    }

    /**
     * RENAME the symbol at a position wherever the language server finds it,
     * and apply that as the editor's own Rename does: an open file's buffer
     * is edited and left unsaved, a file that is not open is REWRITTEN on
     * disk, and Undo Refactoring takes the whole rename back. `$onDone` gets
     * how many files changed and which could not be written, or the error.
     *
     * @param \Closure(array{files: int, skipped: list<string>}|null, ?string): void $onDone
     */
    public function rename(string $path, int $line, int $column, string $newName, \Closure $onDone): void
    {
        $edits = $this->edits;
        $this->query->rename($path, $line, $column, $newName, static function (?array $edit, ?string $error) use ($edits, $onDone): void {
            if (null === $edit) {
                $onDone(null, $error);

                return;
            }
            /** @var array<string, mixed> $edit */
            $outcome = $edits->applyWorkspaceEdit($edit);
            if ($outcome->isRefused()) {
                $onDone(null, $outcome->refused);

                return;
            }
            $fine = null;
            $onDone(['files' => $outcome->touched, 'skipped' => $outcome->skipped], $fine);
        });
    }

    /**
     * FORMAT an open file as its language server would, by the file's own
     * indentation, as one edit the user undoes — not saved. `$onDone` gets
     * how many of the server's edits it applied — none when the file was
     * already formatted — or the error: the file is not open, the server
     * refused, or the text changed while it worked, which makes its edits
     * wrong.
     *
     * @param \Closure(int, ?string): void $onDone
     */
    public function format(string $path, \Closure $onDone): void
    {
        $buffer = $this->buffers->get($path);
        if (null === $buffer || $buffer->isViewer()) {
            $onDone(0, \sprintf('%s is not open as text', $path));

            return;
        }
        $documents = $this->documents;
        $version = $buffer->document->version;
        $this->query->formatting($path, $buffer->document->formattingOptions(), static function (array $edits, ?string $error) use ($buffer, $version, $documents, $onDone): void {
            $why = match (true) {
                null !== $error => $error,
                $buffer->document->version !== $version => 'The file changed while the language server formatted it — ask again',
                [] !== $edits && !$documents->applyEdits($buffer, $edits) => 'The language server sent overlapping edits; nothing changed',
                default => null,
            };
            $onDone(null === $why ? \count($edits) : 0, $why);
        });
    }
}
