<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\FileOpener;
use Porta\Editor\JumpHistory;
use Porta\Editor\LineTextReader;
use Porta\Editor\OpenFocus;
use Porta\I18n\Translator;
use Porta\Support\DocsUrlOpener;
use Porta\Support\NativeDocs;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorNavigationHandler;
use Porta\Widget\Support\ResultsPanelFields;
use Porta\Workbench\Dock\BottomDock;
use Porta\Workbench\Dock\DockToolTab;
use Porta\Workbench\Dock\ResultsPanelMode;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Read-only navigation features: go-to definition/implementation/type, hover,
 * find-usages, peek, file structure and workspace-symbol search — plus the
 * jump-back stack that go-to pushes onto. Presents multi-result lists through
 * the {@see BottomDock} and opens targets via the injected openFile callback.
 */
#[AsIdeService]
final class NavigationController extends AbstractLspController implements EditorNavigationHandler
{
    /**
     * Lines of the definition shown after its first, so Quick Definition shows
     * a declaration rather than a teaser. The list overlay windows twelve rows
     * at a time and scrolls the rest.
     */
    private const int PEEK_LINES_AFTER = 30;

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly BottomDock $panels,
        private readonly ProjectPaths $paths,
        private readonly FileOpener $files,
        private readonly DocsUrlOpener $docsUrls,
        private readonly JumpHistory $history,
        private readonly LineTextReader $text,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
    }

    #[\Override]
    public function requestDefinition(int $line, int $utf16Col): void
    {
        // Native symbols have no project declaration to jump to — open their
        // official docs instead. Decided before any LSP involvement, so this
        // works even without a language server.
        if (null !== ($url = $this->nativeDocsUrl($line, $utf16Col))) {
            $this->docsUrls->open($url);

            return;
        }

        $this->requestGoTo('textDocument/definition', $this->trans('No definition found'), $line, $utf16Col, NavigationMode::UsagesOnDeclaration);
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
        $lineText = $doc->getLine($line);
        $word = \substr($lineText, $range['startCol'], $range['endCol'] - $range['startCol']);
        if ($this->boundToProjectSymbol($lineText, $range['startCol'], $range['endCol'], $word)) {
            return null;
        }

        return NativeDocs::urlFor($this->editor->getLanguage(), $word);
    }

    /**
     * Whether the surrounding SYNTAX says the word cannot be the native symbol
     * whose name it shares, so the LSP must answer instead: a member access on
     * a receiver ($c->count(), Foo::date(), df.all()), a qualified-name
     * segment (App\Exception — a bare leading \ is the explicit global and
     * stays native), a declaration site (function count), or a name this file
     * rebinds with a qualified `use` import or alias. The native-docs
     * precedence itself stands: php.net for a bare count() is the feature;
     * hijacking a project symbol that merely shares the name was the bug.
     */
    private function boundToProjectSymbol(string $lineText, int $startCol, int $endCol, string $word): bool
    {
        $before = \rtrim(\substr($lineText, offset: 0, length: $startCol));
        if (\str_ends_with($before, '->') || \str_ends_with($before, '::')) {
            return true;
        }
        // `.` is member access in these languages; in PHP it is concatenation,
        // where `'a'.count($x)` really is the global function.
        $language = $this->editor->getLanguage();
        if (\in_array($language, ['python', 'javascript', 'typescript'], strict: true) && \str_ends_with($before, '.')) {
            return true;
        }
        // A backslash flanked by identifier text is a namespace-qualified name.
        if (1 === \preg_match('/\w\\\\$/', $before) || \str_starts_with(\substr($lineText, $endCol), '\\')) {
            return true;
        }
        if (1 === \preg_match('/\b(?:function|class|interface|trait|enum|const|def)$/', $before)) {
            return true;
        }

        // A qualified `use App\...\Word;` (or `... as Word;`) rebinds the bare
        // name for the whole file, so the import's target must answer.
        return 1 === \preg_match(
            '/^[ \t]*use\s+(?:function\s+|const\s+)?(?:[\w\\\\]*\\\\'.\preg_quote($word, delimiter: '/').'|[^;\n]*\bas\s+'.\preg_quote($word, delimiter: '/').')\s*;/mi',
            $this->editor->getDocument()->getText(),
        );
    }

    #[\Override]
    public function requestImplementation(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/implementation', $this->trans('No implementation found'), $line, $utf16Col);
    }

    #[\Override]
    public function requestTypeDefinition(int $line, int $utf16Col): void
    {
        $this->requestGoTo('textDocument/typeDefinition', $this->trans('No type definition found'), $line, $utf16Col);
    }

    /**
     * Shared "go to X" navigation: jumps to the single target, or offers a
     * chooser in the results panel when the server returns several. With
     * {@see NavigationMode::UsagesOnDeclaration}, a target that contains the
     * requested position means the caret already sits on the declaration —
     * PhpStorm's "Go to Declaration or Usages" then shows the usages instead.
     */
    private function requestGoTo(string $method, string $notFound, int $line, int $utf16Col, NavigationMode $mode = NavigationMode::Jump): void
    {
        if (null === ($path = $this->readyOrExplain(flush: FlushMode::Keep))) {
            return;
        }

        $this->lsp->request(
            $method,
            $this->positionParams($path, $line, $utf16Col),
            function (mixed $result, ?array $error) use ($notFound, $path, $line, $utf16Col, $mode): void {
                $locations = LspMapper::allLocations($result);
                if (null !== $error || [] === $locations) {
                    $this->flash($notFound);

                    return;
                }
                if (NavigationMode::UsagesOnDeclaration === $mode && $this->caretOnDeclaration($locations, $path, $line, $utf16Col)) {
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
            $this->files->openFile($target, focus: OpenFocus::Editor);
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
                'path' => $this->paths->relative($target),
                'line' => $line,
                'text' => \trim($this->lineAt($target, $line)),
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
        $this->history->push($from, $doc->getCursorLine(), $doc->getCursorCol());
    }

    #[\Override]
    public function navigateBack(): void
    {
        $previous = $this->history->pop();
        if (null === $previous) {
            $this->flash($this->trans('Nowhere to go back to'));

            return;
        }

        if ($previous['path'] !== $this->editor->getFilePath()) {
            $this->files->openFile($previous['path'], focus: OpenFocus::Editor);
        }
        $this->editor->getDocument()->setCursor($previous['line'], $previous['col']);
        $this->editor->invalidate(); // render pass scrolls the cursor back into view
        $this->tui->requestRender();
    }

    /**
     * The scaffold every navigation verb shares: the {@see ready} guard,
     * caret position params, the request, and one funnel for the two
     * indistinguishable-to-the-user failures — a transport $error and a
     * result $handle rejects (returns false on) both land in $notFound.
     * $notFound is a closure, not a message: the verbs flash through two
     * different sinks (the status bar vs the results panel).
     *
     * @param \Closure(mixed): bool $handle   presents the result; false = nothing to show
     * @param \Closure(): void      $notFound
     * @param array<string, mixed>  $extra
     */
    private function requestAt(string $method, int $line, int $col, \Closure $handle, \Closure $notFound, array $extra = []): void
    {
        if (null === ($path = $this->readyOrExplain())) {
            return;
        }
        $this->send($method, $this->positionParams($path, $line, $col, $extra), $handle, $notFound);
    }

    /**
     * The {@see requestAt} variant for document-scoped requests (no caret position).
     *
     * @param \Closure(mixed): bool $handle
     * @param \Closure(): void      $notFound
     */
    private function requestForDocument(string $method, \Closure $handle, \Closure $notFound): void
    {
        if (null === ($path = $this->readyOrExplain())) {
            return;
        }
        $this->send($method, ['textDocument' => ['uri' => $this->uri($path)]], $handle, $notFound);
    }

    /**
     * Fire a request whose error-or-rejected outcome is $notFound. Also the
     * chaining seam: a second hop (callHierarchy/incomingCalls) enters here
     * directly, deliberately NOT re-running the {@see ready} guard.
     *
     * @param array<string, mixed>  $params
     * @param \Closure(mixed): bool $handle
     * @param \Closure(): void      $notFound
     */
    private function send(string $method, array $params, \Closure $handle, \Closure $notFound): void
    {
        $this->lsp->request($method, $params, static function (mixed $result, ?array $error) use ($handle, $notFound): void {
            if (null !== $error || !$handle($result)) {
                $notFound();
            }
        });
    }

    #[\Override]
    public function requestHover(int $line, int $col): void
    {
        $this->requestAt(
            'textDocument/hover',
            $line,
            $col,
            function (mixed $result): bool {
                $text = LspMapper::hoverToText($result);
                if (null === $text) {
                    return false;
                }
                // NOT truncated. The list overlay already scrolls — it renders
                // a window of at most twelve rows and moves it with the
                // selection — so slicing the text to twelve threw away
                // documentation the surface was perfectly able to show, and
                // did it silently.
                $items = \array_map(static fn (string $l): array => ['label' => $l, 'hint' => '', 'value' => ''], \explode("\n", $text));
                $this->editor->openListOverlay($this->trans('Documentation'), $items, static function (): void {});
                $this->tui->requestRender();

                return true;
            },
            fn () => $this->flash($this->trans('No documentation found')),
        );
    }

    #[\Override]
    public function requestReferences(int $line, int $col): void
    {
        $this->requestAt(
            'textDocument/references',
            $line,
            $col,
            function (mixed $result): bool {
                if (!\is_array($result) || [] === $result) {
                    return false;
                }

                /** @var list<array{uri?: string, range?: array<array-key, mixed>}> $result */
                // Grouped and filterable, for the reason
                // {@see ResultsPanelFields::problemList()} states about
                // diagnostics — and it applies harder here. Usages of a common
                // symbol run to dozens of rows across a dozen files, which is
                // the list you least want flat.
                $this->panels->showList(ResultsPanelMode::Search, $this->trans('Usages'), $this->locationRows($result), ResultsPanelFields::searchResults());

                return true;
            },
            fn () => $this->panels->flash($this->trans('No usages found')),
            ['context' => ['includeDeclaration' => true]],
        );
    }

    /**
     * The callers of the symbol at the caret: prepareCallHierarchy resolves the
     * symbol, incomingCalls lists its call sites — rendered like Find Usages.
     */
    #[\Override]
    public function requestCallHierarchy(int $line, int $col): void
    {
        $this->requestCalls($line, $col, 'callHierarchy/incomingCalls', 'from', 'Callers of %symbol%', 'No callers found');
    }

    /** The other direction: what the symbol at the caret calls. */
    #[\Override]
    public function requestOutgoingCalls(int $line, int $col): void
    {
        $this->requestCalls($line, $col, 'callHierarchy/outgoingCalls', 'to', 'Calls from %symbol%', 'No outgoing calls found');
    }

    /**
     * Both directions of the call hierarchy. They differ only in which request
     * is sent and which end of each call carries the interesting symbol — `from`
     * for a caller, `to` for a callee.
     */
    private function requestCalls(int $line, int $col, string $method, string $side, string $title, string $empty): void
    {
        // Gated, because the honest answer differs from the empty one and both
        // bundled PHP servers give it: neither phpantom nor phpactor advertises
        // callHierarchyProvider, and an ungated request flashes "No callers
        // found" — which reads as "this symbol has none" when the truth is that
        // nothing was asked. Measured against both servers, 2026-09-02.
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a CallHierarchyOptions object */
        $capability = $this->lsp->serverCapability($this->editor->getFilePath(), 'callHierarchyProvider');
        if (null === $capability || false === $capability) {
            $this->flash($this->trans('This language server has no call hierarchy'));

            return;
        }
        $this->requestAt(
            'textDocument/prepareCallHierarchy',
            $line,
            $col,
            function (mixed $result) use ($method, $side, $title, $empty): bool {
                if (!\is_array($result) || !\is_array($result[0] ?? null)) {
                    return false;
                }
                /** @var array{name?: string, uri?: string} $item */
                $item = $result[0];
                $this->send(
                    $method,
                    ['item' => $item],
                    function (mixed $calls) use ($item, $side, $title): bool {
                        $locations = [];
                        /** @var list<array<string, mixed>|scalar|null> $callList */
                        $callList = \is_array($calls) ? $calls : [];
                        foreach ($callList as $call) {
                            if (!\is_array($call) || !\is_array($call[$side] ?? null)) {
                                continue;
                            }

                            /** @var array{uri?: string, selectionRange?: array<array-key, mixed>} $endpoint */
                            $endpoint = $call[$side];
                            $locations[] = ['uri' => $endpoint['uri'] ?? '', 'range' => $endpoint['selectionRange'] ?? []];
                        }
                        if ([] === $locations) {
                            return false;
                        }
                        $name = \is_string($item['name'] ?? null) ? $item['name'] : '?';
                        $this->panels->showList(ResultsPanelMode::Search, $this->trans($title, ['%symbol%' => $name]), $this->locationRows($locations), ResultsPanelFields::searchResults());

                        return true;
                    },
                    fn () => $this->panels->flash($this->trans($empty)),
                );

                return true;
            },
            fn () => $this->flash($this->trans($empty)),
        );
    }

    #[\Override]
    public function requestPeek(int $line, int $col): void
    {
        $this->requestAt(
            'textDocument/definition',
            $line,
            $col,
            function (mixed $result): bool {
                $location = LspMapper::firstLocation($result);
                if (null === $location) {
                    return false;
                }

                $target = Uri::toPath($location['uri']);
                if (null === $target) {
                    // A distinct failure with its own message — handled, not "not found".
                    $this->flash($this->trans('Peek target outside the filesystem'));

                    return true;
                }

                $targetLine = (int) ($location['range']['start']['line'] ?? 0);
                $items = [];
                // Enough to hold a whole small declaration, not the six lines
                // this used to show: the overlay renders twelve rows and
                // scrolls, so a window that stopped at six was cutting off
                // bodies the surface could have paged through.
                $read = $this->text->forPath($target); // one read for the whole window
                for ($i = \max(0, $targetLine - 1); $i <= ($targetLine + self::PEEK_LINES_AFTER); ++$i) {
                    $items[] = [
                        'label' => ($i === $targetLine ? Glyphs::NAV_CURRENT.' ' : '  ').\rtrim($read($i)),
                        'hint' => (string) ($i + 1),
                        'value' => $target.':'.$i,
                    ];
                }
                // Blank rows at the END are either the gap after the
                // declaration or past the end of the file — the reader answers
                // '' for both. Interior blanks stay: they are the code's shape.
                //
                // The null arm is unreachable rather than defensive: the target
                // line is always inside the window and its row always carries
                // the marker glyph, so the walk always stops on a non-blank row
                // long before the list could empty. It is written this way
                // because `array_key_last()` is nullable, not to guard.
                while (null !== ($last = \array_key_last($items)) && '' === \trim($items[$last]['label'])) {
                    \array_pop($items);
                }
                // The target line is always in range, so $items is never empty.

                $title = $this->trans('Peek: %location%', ['%location%' => $this->paths->relative($target).':'.($targetLine + 1)]);
                $this->editor->openListOverlay($title, $items, function (string $value): void {
                    [$file, $lineNo] = [\substr($value, offset: 0, length: (int) \strrpos($value, needle: ':')), (int) \substr($value, (int) \strrpos($value, needle: ':') + 1)];
                    $this->files->openFile($file, focus: OpenFocus::Editor);
                    $this->editor->jumpTo($lineNo, 0);
                    $this->tui->requestRender();
                });
                $this->tui->requestRender();

                return true;
            },
            fn () => $this->flash($this->trans('Nothing to peek')),
        );
    }

    #[\Override]
    public function requestFileStructure(): void
    {
        $this->requestForDocument(
            'textDocument/documentSymbol',
            function (mixed $result): bool {
                if (!\is_array($result) || [] === $result) {
                    return false;
                }

                $items = [];
                /** @var list<array<array-key, mixed>> $result */
                LspMapper::flattenSymbols($result, 0, $items);
                $this->editor->openListOverlay($this->trans('File Structure'), $items, function (string $pos): void {
                    [$line, $character] = \array_map(intval(...), \explode(':', $pos));
                    $this->editor->jumpTo($line, $character);
                });
                $this->tui->requestRender();

                return true;
            },
            fn () => $this->flash($this->trans('No symbols in this file')),
        );
    }

    /**
     * The PANEL counterpart of {@see requestFileStructure}: the same
     * `documentSymbol` answer, published as a dock tool tab that stays open
     * across buffer switches instead of a popup that closes on the first jump.
     * PhpStorm ships both, and so does this.
     */
    public function requestStructurePanel(): void
    {
        $title = $this->trans('Structure');
        $this->requestStructure(
            // Grouped as well, which is the opposite of what it looks like it
            // should be. One file gets ONE header — and the alternative is not
            // "no header", it is the same path repeated on every row, because
            // an ungrouped row prints `path:line` in front of its text. The
            // header says it once; the filter is how you reach a method in a
            // forty-method class.
            fn (array $rows): DockToolTab => $this->panels->showList(ResultsPanelMode::Structure, $title, $rows, ResultsPanelFields::searchResults()),
            fn () => $this->flash($this->trans('No symbols in this file')),
        );
    }

    /**
     * Republish into a Structure tab that is already open: rows replaced in
     * place, no reveal and no focus move, so a buffer switch can never yank the
     * dock open. With no tab standing it costs no round trip at all.
     */
    public function refreshStructurePanel(): void
    {
        if (!$this->panels->hasList(ResultsPanelMode::Structure)) {
            return;
        }
        $this->requestStructure(
            fn (array $rows) => $this->panels->refreshList(ResultsPanelMode::Structure, static fn (): array => $rows),
            // A file the server has no symbols for still has to CLEAR the
            // panel, or it keeps showing the previous file's tree.
            fn () => $this->panels->refreshList(ResultsPanelMode::Structure, static fn (): array => []),
        );
    }

    /**
     * @param \Closure(list<array{path: string, line: int, text: string}>): mixed $publish
     * @param \Closure(): void                                                    $empty
     */
    private function requestStructure(\Closure $publish, \Closure $empty): void
    {
        $path = $this->editor->getFilePath();
        $this->requestForDocument(
            'textDocument/documentSymbol',
            function (mixed $result) use ($publish, $path): bool {
                if (!\is_array($result) || [] === $result) {
                    return false;
                }
                /** @var list<array<array-key, mixed>> $result */
                $publish($this->structureRows($result, $path));
                $this->tui->requestRender();

                return true;
            },
            $empty,
        );
    }

    /**
     * Flatten the symbol tree into dock rows: the indented label plus its kind,
     * every row carrying the active file's own path so Enter jumps within it.
     *
     * @param list<array<array-key, mixed>> $symbols
     *
     * @return list<array{path: string, line: int, text: string}>
     */
    private function structureRows(array $symbols, ?string $path): array
    {
        $items = [];
        LspMapper::flattenSymbols($symbols, 0, $items);

        $relative = null === $path ? '' : $this->paths->relative($path);
        $rows = [];
        foreach ($items as $item) {
            [$line] = \array_map(intval(...), \explode(':', $item['value']));
            $hint = '' === $item['hint'] ? '' : '  '.$item['hint'];
            $rows[] = ['path' => $relative, 'line' => $line, 'text' => $item['label'].$hint];
        }

        return $rows;
    }

    private function lineAt(string $path, int $line): string
    {
        return $this->text->forPath($path)($line);
    }
}
