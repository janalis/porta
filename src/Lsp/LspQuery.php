<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\LineTextReader;
use Porta\Support\ProjectPaths;

/**
 * The language servers as a source of ANSWERS rather than of editor actions:
 * where a symbol is declared, where it is used, what it is — about any file
 * of the project, with nothing shown and no tab moved.
 *
 * {@see NavigationController} answers the same questions for the user, about
 * the active tab, by jumping and listing. This is for a caller that wants the
 * data — a plugin, through {@see \Porta\Plugin\Api\PluginApi}, and so an
 * agent through the mcp plugin — and asks about files the user is not
 * looking at, which the editor verbs cannot.
 *
 * A server only answers about documents it was told are open. An open buffer
 * already is — its pending edit is flushed first, so the answer is about the
 * text on screen. Any other file is LENT to the server for the one question:
 * opened from disk, asked, closed again. Lent files are counted, so two
 * questions about one file in flight open and close it once; and a file the
 * user opens meanwhile is left open, because the document is theirs now.
 *
 * Lines and columns are 0-based, columns in UTF-16 code units as the
 * protocol counts them. Every answer comes with an error that is null on
 * success: a server can be missing, starting, or refuse.
 */
#[AsIdeService]
final class LspQuery
{
    /** @var array<string, int> path => questions in flight about a file lent to the servers */
    private array $lent = [];

    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly BufferManager $buffers,
        private readonly LineTextReader $lines,
        private readonly ProjectPaths $paths,
    ) {}

    /** @param \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void $onDone */
    /**
     * @return array<string, array{command: list<string>, name: string, initializationOptions?: array<string, mixed>}>
     *
     * @see LspSessionPool::primaryServers()
     */
    public function primaryServers(): array
    {
        return $this->lsp->primaryServers();
    }

    public function definition(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->locations('textDocument/definition', $path, $line, $column, [], $onDone);
    }

    /** @param \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void $onDone */
    public function references(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->locations('textDocument/references', $path, $line, $column, ['context' => ['includeDeclaration' => true]], $onDone);
    }

    /** @param \Closure(?string, ?string): void $onDone the documentation as plain text, null when there is none */
    public function hover(string $path, int $line, int $column, \Closure $onDone): void
    {
        $this->ask($path, 'textDocument/hover', ['position' => ['line' => $line, 'character' => $column]], static function (mixed $result, ?string $error) use ($onDone): void {
            $onDone(null === $error ? LspMapper::hoverToText($result) : null, $error);
        });
    }

    /**
     * The symbols one file declares — its outline — outermost first, as
     * {@see LspMapper::outline()} rows; `kind` is the protocol's SymbolKind.
     *
     * @param \Closure(list<array{name: string, kind: int, line: int, column: int, depth: int}>, ?string): void $onDone
     */
    public function documentSymbols(string $path, \Closure $onDone): void
    {
        $this->ask($path, 'textDocument/documentSymbol', [], static function (mixed $result, ?string $error) use ($onDone): void {
            /** @var list<array<array-key, mixed>|scalar|null> $symbols */
            $symbols = \is_array($result) ? \array_values($result) : [];
            $onDone(LspMapper::outline($symbols), $error);
        });
    }

    /**
     * The WorkspaceEdit that renames the symbol at a position, as the server
     * computed it — NOT applied: applying is an editor action, and this class
     * only asks. Null with the reason when the server cannot rename there.
     *
     * @param \Closure(array<string, mixed>|null, ?string): void $onDone
     */
    public function rename(string $path, int $line, int $column, string $newName, \Closure $onDone): void
    {
        $this->ask($path, 'textDocument/rename', ['position' => ['line' => $line, 'character' => $column], 'newName' => $newName], static function (mixed $result, ?string $error) use ($onDone): void {
            if (null === $error && !\is_array($result)) {
                $error = 'The language server cannot rename anything at this position';
            }
            /** @var array<string, mixed>|null $edit */
            $edit = null === $error ? $result : null;
            $onDone($edit, $error);
        });
    }

    /**
     * The text edits that format a file by $options, as the server computed
     * them against the text it has — NOT applied. None when it is already
     * formatted.
     *
     * @param array{tabSize: int, insertSpaces: bool}                                                                                                  $options
     * @param \Closure(list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}>, ?string): void $onDone
     */
    public function formatting(string $path, array $options, \Closure $onDone): void
    {
        $this->ask($path, 'textDocument/formatting', ['options' => $options], static function (mixed $result, ?string $error) use ($onDone): void {
            /** @var list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $edits */
            $edits = \is_array($result) ? \array_values($result) : [];
            $onDone($edits, $error);
        });
    }

    /**
     * The project's symbols matching $query, from the server already running —
     * workspace-wide, so no file names which one; with none running there is
     * nobody to ask.
     *
     * @param \Closure(list<array{path: string, line: int, name: string, kind: int}>, ?string): void $onDone
     */
    public function workspaceSymbols(string $query, \Closure $onDone): void
    {
        if (!$this->lsp->isActive()) {
            $why = 'No language server is running — open a file of the project first';
            $onDone([], $why);

            return;
        }
        $paths = $this->paths;
        $this->lsp->whenReady(function () use ($query, $onDone, $paths): void {
            $this->lsp->request('workspace/symbol', ['query' => $query], static function (mixed $result, ?array $error) use ($onDone, $paths): void {
                if (null !== $error) {
                    $onDone([], self::message($error));

                    return;
                }
                $symbols = [];
                foreach (WorkspaceSymbolClient::map($result, $paths) as $symbol) {
                    $symbols[] = ['path' => self::canonical($paths->absolute($symbol['path']))] + $symbol;
                }
                $fine = null;
                $onDone($symbols, $fine);
            });
        });
    }

    /**
     * @param array<string, mixed>                                                                  $extra
     * @param \Closure(list<array{path: string, line: int, column: int, text: string}>, ?string): void $onDone
     */
    private function locations(string $method, string $path, int $line, int $column, array $extra, \Closure $onDone): void
    {
        $this->ask($path, $method, ['position' => ['line' => $line, 'character' => $column]] + $extra, function (mixed $result, ?string $error) use ($onDone): void {
            if (null !== $error) {
                $onDone([], $error);

                return;
            }
            $rows = [];
            foreach (LspMapper::allLocations($result) as $location) {
                $target = Uri::toPath($location['uri']);
                if (null === $target) {
                    continue;
                }
                $target = self::canonical($target);
                /** @var array{line?: int, character?: int} $start */
                $start = \is_array($location['range']['start'] ?? null) ? $location['range']['start'] : [];
                $at = (int) ($start['line'] ?? 0);
                $rows[] = ['path' => $target, 'line' => $at, 'column' => (int) ($start['character'] ?? 0), 'text' => \trim($this->lines->forPath($target)($at))];
            }
            $fine = null;
            $onDone($rows, $fine);
        });
    }

    /**
     * @param array<string, mixed>              $params without the textDocument, which is added here
     * @param \Closure(mixed, ?string): void    $onDone
     */
    private function ask(string $path, string $method, array $params, \Closure $onDone): void
    {
        if (!$this->lsp->serves($path)) {
            $onDone(null, \sprintf('No language server is configured for %s', $this->paths->relative($path)));

            return;
        }
        $buffer = $this->buffers->get($path);
        $open = false === $buffer?->isViewer() ? $buffer : null;
        if (null !== $open) {
            $this->lsp->flushPendingChange($path, $open->document);
        }
        $lend = null === $open;
        if ($lend && !\is_file($path)) {
            $onDone(null, \sprintf('%s is not a file', $this->paths->relative($path)));

            return;
        }
        if ($lend) {
            $this->lend($path);
        }
        $this->lsp->requestFor($path, $method, ['textDocument' => Uri::textDocument($path)] + $params, function (mixed $result, ?array $error) use ($path, $lend, $onDone): void {
            if ($lend) {
                $this->giveBack($path);
            }
            $onDone($result, null === $error ? null : self::message($error));
        });
    }

    private function lend(string $path): void
    {
        if (0 === ($this->lent[$path] ?? 0)) {
            $this->lsp->notifyDidOpen($path, (string) \file_get_contents($path), ProjectPaths::languageId($path), 0);
        }
        $this->lent[$path] = ($this->lent[$path] ?? 0) + 1;
    }

    private function giveBack(string $path): void
    {
        $left = ($this->lent[$path] ?? 1) - 1;
        if ($left > 0) {
            $this->lent[$path] = $left;

            return;
        }
        unset($this->lent[$path]);
        // Opened by the user while the question was out: theirs now, and
        // closing it would leave their buffer talking to a server that
        // believes it closed.
        if (null === $this->buffers->get($path)) {
            $this->lsp->notifyDidClose($path);
        }
    }

    /**
     * The one spelling of a path the rest of the IDE uses — buffers are keyed
     * by realpath. A server echoes URIs under the root as IT was given it, and
     * on macOS the temp directories are behind a symlink, so without this one
     * file came back under two names.
     */
    private static function canonical(string $path): string
    {
        $real = \realpath($path);

        return false === $real ? $path : $real;
    }

    /** @param array<array-key, mixed> $error a JSON-RPC error object */
    private static function message(array $error): string
    {
        return \is_string($error['message'] ?? null) && '' !== $error['message'] ? $error['message'] : 'The language server refused the request';
    }
}
