<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Support\NativeDocs;
use Porta\Support\ProjectPaths;
use Porta\Ui\Notifier;
use Porta\Ui\PanelManager;
use Porta\Ui\ResultsPanelMode;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorNavigationHandler;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * Read-only navigation features: go-to definition/implementation/type, hover,
 * find-usages, peek, file structure and workspace-symbol search — plus the
 * jump-back stack that go-to pushes onto. Presents multi-result lists through
 * the {@see PanelManager} and opens targets via the injected openFile callback.
 */
final class NavigationController extends AbstractLspController implements EditorNavigationHandler
{
    /** @var list<array{path: string, line: int, col: int}> positions to return to after jumps */
    private array $jumpStack = [];

    private readonly WorkspaceSymbolClient $symbolClient;

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly BufferManager $buffers,
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly PanelManager $panels,
        private readonly string $rootDir,
        private readonly FileOpener $files,
        private readonly DocsUrlOpener $docsUrls,
        ?WorkspaceSymbolClient $symbolClient = null,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
        $this->symbolClient = $symbolClient ?? new WorkspaceSymbolClient($lsp, $rootDir);
    }

    public function requestDefinition(int $line, int $utf16Col): void
    {
        // Native symbols have no project declaration to jump to — open their
        // official docs instead. Decided before any LSP involvement, so this
        // works even without a language server.
        if (null !== ($url = $this->nativeDocsUrl($line, $utf16Col))) {
            $this->docsUrls->open($url);

            return;
        }

        $this->requestGoTo('textDocument/definition', $this->trans('No definition found'), $line, $utf16Col, usagesWhenOnDeclaration: true);
    }

    /** Docs URL when the word at the requested position is a native symbol of the buffer's language. */
    private function nativeDocsUrl(int $line, int $utf16Col): ?string
    {
        $doc = $this->editor->getDocument();
        $byteCol = $doc->utf16ColToByteCol($line, $utf16Col);
        $range = $doc->wordRangeAt($line, $byteCol);
        if (null === $range) {
            return null;
        }
        $word = substr($doc->getLine($line), $range['startCol'], $range['endCol'] - $range['startCol']);

        return NativeDocs::urlFor($this->editor->getLanguage(), $word);
    }

    public function requestImplementation(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/implementation', $this->trans('No implementation found'), $line, $utf16Col);
    }

    public function requestTypeDefinition(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/typeDefinition', $this->trans('No type definition found'), $line, $utf16Col);
    }

    /**
     * Shared "go to X" navigation: jumps to the single target, or offers a
     * chooser in the results panel when the server returns several. With
     * $usagesWhenOnDeclaration, a target that contains the requested position
     * means the caret already sits on the declaration — PhpStorm's "Go to
     * Declaration or Usages" then shows the symbol's usages instead.
     */
    private function requestGoTo(string $method, string $notFound, int $line, int $utf16Col, bool $usagesWhenOnDeclaration = false): void
    {
        if (null === ($path = $this->ready(flush: false))) {
            return;
        }

        $this->lsp->request(
            $method,
            $this->positionParams($path, $line, $utf16Col),
            function (mixed $result, ?array $error) use ($notFound, $path, $line, $utf16Col, $usagesWhenOnDeclaration): void {
                $locations = LspMapper::allLocations($result);
                if (null !== $error || [] === $locations) {
                    $this->flash($notFound);

                    return;
                }
                if ($usagesWhenOnDeclaration && $this->caretOnDeclaration($locations, $path, $line, $utf16Col)) {
                    $this->requestReferences($line, $utf16Col);

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

    /**
     * Whether any returned target contains the requested position — i.e. the
     * request was made from the declaration itself. Compared against the
     * requested path/position (not the live cursor): the caret may have moved
     * while the request was in flight.
     *
     * @param list<array{uri: string, range: array}> $locations
     */
    private function caretOnDeclaration(array $locations, string $path, int $line, int $utf16Col): bool
    {
        foreach ($locations as $location) {
            $target = Uri::toPath($location['uri']);
            if (null === $target || $target !== $path) {
                continue;
            }
            if (LspMapper::rangeContains($location['range'], $line, $utf16Col)) {
                return true;
            }
        }

        return false;
    }

    /** Push the current position onto the jump stack and navigate to a location. */
    private function jumpToLocation(array $location): void
    {
        $target = Uri::toPath((string) $location['uri']);
        if (null === $target) {
            $this->flash($this->trans('Target outside the filesystem'));

            return;
        }

        $this->pushCurrentLocation();

        $line = (int) ($location['range']['start']['line'] ?? 0);
        $character = (int) ($location['range']['start']['character'] ?? 0);
        if ($target !== $this->editor->getFilePath()) {
            $this->files->openFile($target, focusEditor: true);
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

        $this->panels->showList(ResultsPanelMode::Search, $this->trans('Choose target'), $results);
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

    /** Record the current position so navigate-back can return to it. */
    public function pushCurrentLocation(): void
    {
        $from = $this->editor->getFilePath();
        if (null === $from) {
            return;
        }
        $doc = $this->editor->getDocument();
        $this->jumpStack[] = ['path' => $from, 'line' => $doc->getCursorLine(), 'col' => $doc->getCursorCol()];
        if (\count($this->jumpStack) > 50) {
            array_shift($this->jumpStack);
        }
    }

    public function navigateBack(): void
    {
        $previous = array_pop($this->jumpStack);
        if (null === $previous) {
            $this->flash($this->trans('Nowhere to go back to'));

            return;
        }

        if ($previous['path'] !== $this->editor->getFilePath()) {
            $this->files->openFile($previous['path'], focusEditor: true);
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
                    $this->flash($this->trans('No documentation found'));

                    return;
                }
                $lines = \array_slice(explode("\n", $text), 0, 12);
                $items = array_map(static fn (string $l) => ['label' => $l, 'hint' => '', 'value' => ''], $lines);
                $this->editor->openListOverlay($this->trans('Documentation'), $items, static function (): void {});
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
                    $this->panels->flash($this->trans('No usages found'));

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
                    $this->flash($this->trans('Nothing to peek'));

                    return;
                }

                $target = Uri::toPath($location['uri']);
                if (null === $target) {
                    $this->flash($this->trans('Peek target outside the filesystem'));

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
                    $this->files->openFile($file, focusEditor: true);
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
                    $this->flash($this->trans('No symbols in this file'));

                    return;
                }

                $items = [];
                /** @var list<array<array-key, mixed>> $result */
                LspMapper::flattenSymbols($result, 0, $items);
                $this->editor->openListOverlay($this->trans('File Structure'), $items, function (string $pos): void {
                    [$line, $character] = array_map('intval', explode(':', $pos));
                    $this->editor->jumpTo($line, $character);
                });
                $this->tui->requestRender();
            },
        );
    }

    public function requestWorkspaceSymbols(string $query): void
    {
        $this->symbolClient->search($query, function (array $symbols): void {
            if (!$this->resultsPanel->isOpen() || ResultsPanelMode::Symbol !== $this->panels->mode()) {
                return;
            }
            $results = array_map(
                static fn (array $symbol): array => ['path' => $symbol['path'], 'line' => $symbol['line'], 'text' => $symbol['label']],
                $symbols,
            );
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
