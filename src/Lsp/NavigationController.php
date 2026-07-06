<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\BufferManager;
use Porta\Support\ProjectPaths;
use Porta\Ui\PanelManager;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/**
 * Read-only navigation features: go-to definition/implementation/type, hover,
 * find-usages, peek, file structure and workspace-symbol search — plus the
 * jump-back stack that go-to pushes onto. Presents multi-result lists through
 * the {@see PanelManager} and opens targets via the injected openFile callback.
 */
final class NavigationController extends AbstractLspController
{
    /** @var list<array{path: string, line: int, col: int}> positions to return to after jumps */
    private array $jumpStack = [];

    /**
     * @param \Closure(string, bool): void $openFile fn(path, focusEditor)
     */
    public function __construct(
        LspSession $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        StatusBarWidget $statusBar,
        private readonly BufferManager $buffers,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly PanelManager $panels,
        private readonly string $rootDir,
        private readonly \Closure $openFile,
    ) {
        parent::__construct($lsp, $editor, $tui, $statusBar);
    }

    public function requestDefinition(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/definition', 'No definition found', $line, $utf16Col);
    }

    public function requestImplementation(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/implementation', 'No implementation found', $line, $utf16Col);
    }

    public function requestTypeDefinition(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/typeDefinition', 'No type definition found', $line, $utf16Col);
    }

    /**
     * Shared "go to X" navigation: jumps to the single target, or offers a
     * chooser in the results panel when the server returns several.
     */
    private function requestGoTo(string $method, string $notFound, int $line, int $utf16Col): void
    {
        if (null === ($path = $this->ready(flush: false))) {
            return;
        }

        $this->lsp->request(
            $method,
            $this->positionParams($path, $line, $utf16Col),
            function (mixed $result, ?array $error) use ($notFound): void {
                $locations = LspMapper::allLocations($result);
                if (null !== $error || [] === $locations) {
                    $this->flash($notFound);

                    return;
                }
                if (\count($locations) > 1) {
                    $this->showLocationChooser($locations, $notFound);

                    return;
                }
                $this->jumpToLocation($locations[0]);
            },
        );
    }

    /** Push the current position onto the jump stack and navigate to a location. */
    private function jumpToLocation(array $location): void
    {
        $target = Uri::toPath((string) $location['uri']);
        if (null === $target) {
            $this->flash('Target outside the filesystem');

            return;
        }

        $from = $this->editor->getFilePath();
        $doc = $this->editor->getDocument();
        if (null !== $from) {
            $this->jumpStack[] = ['path' => $from, 'line' => $doc->getCursorLine(), 'col' => $doc->getCursorCol()];
            if (\count($this->jumpStack) > 50) {
                array_shift($this->jumpStack);
            }
        }

        $line = (int) ($location['range']['start']['line'] ?? 0);
        $character = (int) ($location['range']['start']['character'] ?? 0);
        if ($target !== $this->editor->getFilePath()) {
            ($this->openFile)($target, true);
        }
        $this->editor->jumpTo($line, $character);
        $this->tui->requestRender();
    }

    /**
     * Present several candidate targets in the results panel to choose from.
     *
     * @param list<array{uri: string, range: array}> $locations
     */
    private function showLocationChooser(array $locations, string $notFound): void
    {
        $results = $this->locationRows($locations);
        if ([] === $results) {
            $this->panels->flash($notFound);

            return;
        }

        $this->panels->showList(ResultsPanelMode::Search, 'Choose target', $results);
    }

    /**
     * Map LSP Location[] to results-panel rows carrying the target line's text.
     * Locations outside the filesystem are dropped.
     *
     * @param list<array{uri?: string, range?: array}> $locations
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    private function locationRows(array $locations): array
    {
        $rows = [];
        foreach ($locations as $location) {
            $target = Uri::toPath($location['uri'] ?? '');
            if (null === $target) {
                continue;
            }
            $line = (int) ($location['range']['start']['line'] ?? 0);
            $rows[] = [
                'path' => ProjectPaths::relative($this->rootDir, $target),
                'line' => $line,
                'text' => trim($this->lineAt($target, $line)),
            ];
        }

        return $rows;
    }

    public function navigateBack(): void
    {
        $previous = array_pop($this->jumpStack);
        if (null === $previous) {
            $this->flash('Nowhere to go back to');

            return;
        }

        if ($previous['path'] !== $this->editor->getFilePath()) {
            ($this->openFile)($previous['path'], true);
        }
        $this->editor->getDocument()->setCursor($previous['line'], $previous['col']);
        $this->editor->invalidate(); // render pass scrolls the cursor back into view
        $this->tui->requestRender();
    }

    public function requestHover(int $line, int $col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/hover',
            $this->positionParams($path, $line, $col),
            function (mixed $result, ?array $error): void {
                $text = LspMapper::hoverToText($result);
                if (null !== $error || null === $text) {
                    $this->flash('No documentation found');

                    return;
                }
                $lines = \array_slice(explode("\n", $text), 0, 12);
                $items = array_map(static fn (string $l) => ['label' => $l, 'hint' => '', 'value' => ''], $lines);
                $this->editor->openListOverlay('Documentation', $items, static function (): void {});
                $this->tui->requestRender();
            },
        );
    }

    public function requestReferences(int $line, int $col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/references',
            $this->positionParams($path, $line, $col, ['context' => ['includeDeclaration' => true]]),
            function (mixed $result, ?array $error): void {
                if (null !== $error || !\is_array($result) || [] === $result) {
                    $this->panels->flash('No usages found');

                    return;
                }

                /** @var list<array{uri?: string, range?: array<array-key, mixed>}> $result */
                $this->panels->showList(ResultsPanelMode::Search, 'Usages', $this->locationRows($result));
            },
        );
    }

    public function requestPeek(int $line, int $col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/definition',
            $this->positionParams($path, $line, $col),
            function (mixed $result, ?array $error): void {
                $location = LspMapper::firstLocation($result);
                if (null !== $error || null === $location) {
                    $this->flash('Nothing to peek');

                    return;
                }

                $target = Uri::toPath($location['uri']);
                if (null === $target) {
                    $this->flash('Peek target outside the filesystem');

                    return;
                }

                $targetLine = (int) ($location['range']['start']['line'] ?? 0);
                $items = [];
                // A few lines of context around the definition.
                for ($i = max(0, $targetLine - 1); $i <= ($targetLine + 4); ++$i) {
                    $text = rtrim($this->lineAt($target, $i));
                    if ('' === $text && $i > $targetLine) {
                        continue;
                    }
                    $items[] = [
                        'label' => ($i === $targetLine ? '▶ ' : '  ').$text,
                        'hint' => (string) ($i + 1),
                        'value' => $target.':'.$i,
                    ];
                }
                // The target line is always in range, so $items is never empty.

                $title = 'Peek: '.ProjectPaths::relative($this->rootDir, $target).':'.($targetLine + 1);
                $this->editor->openListOverlay($title, $items, function (string $value): void {
                    [$file, $lineNo] = [substr($value, 0, (int) strrpos($value, ':')), (int) substr($value, (int) strrpos($value, ':') + 1)];
                    ($this->openFile)($file, true);
                    $this->editor->jumpTo($lineNo, 0);
                    $this->tui->requestRender();
                });
                $this->tui->requestRender();
            },
        );
    }

    public function requestFileStructure(): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/documentSymbol',
            [
                'textDocument' => ['uri' => $this->uri($path)],
            ],
            function (mixed $result, ?array $error): void {
                if (null !== $error || !\is_array($result) || [] === $result) {
                    $this->flash('No symbols in this file');

                    return;
                }

                $items = [];
                /** @var list<array<array-key, mixed>> $result */
                LspMapper::flattenSymbols($result, 0, $items);
                $this->editor->openListOverlay('File Structure', $items, function (string $pos): void {
                    [$line, $character] = array_map('intval', explode(':', $pos));
                    $this->editor->jumpTo($line, $character);
                });
                $this->tui->requestRender();
            },
        );
    }

    public function requestWorkspaceSymbols(string $query): void
    {
        if (!$this->lsp->isInitialized()) {
            $this->resultsPanel->setResults([]);
            $this->tui->requestRender();

            return;
        }

        $this->lsp->request('workspace/symbol', ['query' => $query], function (mixed $result) use ($query): void {
            if (!$this->resultsPanel->isOpen() || ResultsPanelMode::Symbol !== $this->panels->mode()) {
                return;
            }
            /** @var list<array{location?: array{uri?: mixed, range?: array{start?: array<string, mixed>}}, kind?: mixed, name?: mixed}> $symbols */
            $symbols = \is_array($result) ? $result : [];
            $results = [];
            foreach ($symbols as $symbol) {
                $target = Uri::toPath((string) ($symbol['location']['uri'] ?? ''));
                if (null === $target) {
                    continue;
                }
                $start = $symbol['location']['range']['start'] ?? ['line' => 0];
                $kind = LspMapper::SYMBOL_KINDS[$symbol['kind'] ?? 0] ?? '';
                $results[] = [
                    'path' => ProjectPaths::relative($this->rootDir, $target),
                    'line' => (int) ($start['line'] ?? 0),
                    'text' => trim(($kind ? "[{$kind}] " : '').(string) ($symbol['name'] ?? '')),
                ];
            }
            $this->resultsPanel->setResults($results);
            $this->tui->requestRender();
        });
    }

    private function lineAt(string $path, int $line): string
    {
        $buffer = $this->buffers->get($path) ?? $this->buffers->get(realpath($path) ?: $path);
        if (null !== $buffer) {
            return $buffer->document->getLine($line);
        }
        $lines = @file($path, \FILE_IGNORE_NEW_LINES);

        return \is_array($lines) ? $lines[$line] ?? '' : '';
    }
}
