<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Config\LanguageIndent;
use Porta\Container\AsIdeService;
use Porta\Container\AsWidget;
use Porta\Editor\ActiveDocumentProvider;
use Porta\Editor\ActiveFileProvider;
use Porta\Editor\AutoPairService;
use Porta\Editor\BreadcrumbScanner;
use Porta\Editor\CaretMotion;
use Porta\Editor\ClipboardRing;
use Porta\Editor\CommentSyntax;
use Porta\Editor\CompletionItem;
use Porta\Editor\DecorationStore;
use Porta\Editor\Document;
use Porta\Editor\EditorActions;
use Porta\Editor\EditorCompletion;
use Porta\Editor\EditorDisplayOptions;
use Porta\Editor\EditorHost;
use Porta\Editor\EditorIndentTarget;
use Porta\Editor\EditorInvalidator;
use Porta\Editor\EditorKeymapProvider;
use Porta\Editor\EditorMouse;
use Porta\Editor\EditorScroll;
use Porta\Editor\EditorStructural;
use Porta\Editor\EditorTextAccess;
use Porta\Editor\EditorViewOptions;
use Porta\Editor\EditorViewport;
use Porta\Editor\FoldModel;
use Porta\Editor\IndentKind;
use Porta\Editor\LineNumberMode;
use Porta\Editor\MemberSeparators;
use Porta\Editor\ModalHost;
use Porta\Editor\MultiCaret;
use Porta\Editor\OccurrenceHighlights;
use Porta\Editor\ScopeRescan;
use Porta\Editor\SignatureHint;
use Porta\Editor\StatementSyntax;
use Porta\Editor\TemplateCatalog;
use Porta\Editor\WrapLayout;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\TempestHighlightProvider;
use Porta\I18n\Translator;
use Porta\Input\EditorActionRouter;
use Porta\Lsp\DocumentScopes;
use Porta\Overlay\State\FindInFilesModal;
use Porta\Overlay\State\OverlayHost;
use Porta\Search\Everywhere\SearchOverlay;
use Porta\Search\Everywhere\SearchRow;
use Porta\Support\AnsiText;
use Porta\Support\FlashSink;
use Porta\Support\IntMath;
use Porta\Support\PerfCollector;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Ui\CursorStyle;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\ShortcutFormatter;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorPopupGeometry;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\EditorRowAssembler;
use Porta\Widget\Render\ErrorStripe;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\PaneFocus;
use Porta\Widget\Render\StripeMarks;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\StylesDefault;
use Porta\Workbench\Menu\EditorCommandTarget;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\BracketedPasteTrait;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;
use Tempest\Highlight\TerminalTheme;

/**
 * IDE-style code editor: line-number gutter, syntax highlighting,
 * vertical + horizontal scrolling, LSP diagnostics and a completion popup.
 */
#[AsIdeService]
#[AsWidget('editor')]
final class CodeEditorWidget extends AbstractWidget implements
    ActiveDocumentProvider,
    ActiveFileProvider,
    EditorCommandTarget,
    EditorHost,
    EditorIndentTarget,
    EditorInvalidator,
    EditorKeymapProvider,
    EditorTextAccess,
    EditorViewOptions,
    FocusableInterface,
    LtrPane,
    MenuOverlayHost,
    ModalHost,
    SearchOverlay,
    VerticallyExpandableInterface
{
    use BracketedPasteTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use StylesDefault;

    private Document $document;
    private CodeHighlighter $highlighter;
    private ?string $filePath = null;
    /**
     * Per-language indent config (languageId => preference). A language absent
     * from the map (or an 'auto' value) defers to the file's .editorconfig,
     * falling back to unix-style 2 spaces.
     *
     * @var array<string, LanguageIndent>
     */
    private array $languageIndents = [];
    /** The current document's resolved .editorconfig, for the 'auto' indent branch. */
    private ?ResolvedEditorConfig $currentEditorConfig = null;

    /** Viewport scroll state + caret-visibility clamp math, rebuilt per document. */
    private EditorScroll $scroll;
    private int $lastGutterWidth = 6;
    private int $lastRenderRows = 24; // full editor height of the last render (for stripe click mapping)
    private int $lastStripeCol = \PHP_INT_MAX; // display column of the error stripe; PHP_INT_MAX = no stripe (empty state)

    /** @var list<int> 0-based doc lines pinned by sticky scroll at the top of the last render */
    private array $stickyLines = [];
    /** @var list<array{label: string, startLine: int, endLine: int, kind: string}> memoized structure scan, keyed by document identity + version */
    private array $scopeCache = [];
    private string $scopeKey = '';

    /** Same-document edits rescan after this much idle, not per keystroke. */
    private const float SCOPE_DEBOUNCE_SECONDS = 0.15;

    /** Pending debounced scope rescan; null when none is armed. */
    private ?ScopeRescan $scopeRescan = null;

    /** @var array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null memoized bracket pair, keyed by version + caret */
    private ?array $bracketsMemo = null;
    private string $bracketsKey = '';

    /** @var array{key: string, spans: list<array{0: int, 1: int, 2: int, 3: bool}>} memoized identifier occurrences, keyed by version + caret + focus + selection + viewport */
    private array $occurrencesMemo = ['key' => '', 'spans' => []];

    /** Mouse hit-testing + selection, rebuilt per document. */
    private EditorMouse $mouse;

    private MultiCaret $multiCaret;

    /** Structural selection (expand/shrink/surround) + merge-conflict nav, rebuilt per document. */
    private EditorStructural $structural;

    /** The six display toggles; the public verbs below delegate here. */
    private readonly EditorDisplayOptions $display;

    private readonly AutoPairService $autoPair;

    /** LSP completion + snippet machinery, rebuilt per document. */
    private EditorCompletion $completion;
    private FoldModel $foldModel;

    /** Outbound hooks into the coordinator, replaced wholesale by wiring/tests. */
    private EditorCallbacks $callbacks;

    /** The buffer's decoration overlays (git marks, diagnostics, breakpoints, …). */
    private readonly DecorationStore $decorations;

    /** @var array{int, int}|null caret (line, col) at the last render, to gate hScroll following */
    private ?array $lastFollowedCursor = null;

    /** One-line signature hint shown above the cursor, cleared on movement. */
    private ?SignatureHint $signatureHint = null;

    /** Editing-verb dispatcher (movement/clipboard/line ops), rebuilt per document. */
    private EditorActions $actions;

    /** Modal list-overlay + prompt chrome composited over the editor pane. */
    private readonly OverlayHost $overlayHost;

    /** The PhpStorm-style Find-in-Files search dialog composited over the editor pane. */
    private readonly FindInFilesModal $findModal;

    /** Editor-level action dispatch (keymap actions + menu items). */
    private readonly EditorActionRouter $actionRouter;

    public function __construct(
        ?Styles $styles = null,
        private readonly TerminalTheme $syntaxTheme = new DarkTerminalTheme(),
        private readonly CursorStyle $cursorStyle = new CursorStyle(),
        private readonly Translator $translator = new Translator('en'),
        private readonly ?ViewRegistry $views = null,
        ?OverlayHost $overlayHost = null,
        private readonly ?PerfCollector $perf = null,
        private readonly ?Scheduler $scheduler = null,
        private readonly ?FlashSink $flash = null,
        private readonly OccurrenceHighlights $occurrences = new OccurrenceHighlights(),
        /** The server's scopes, for every language the offline PHP scan cannot read. */
        private readonly DocumentScopes $documentScopes = new DocumentScopes(),
    ) {
        $this->initStyles($styles);
        $this->callbacks = new EditorCallbacks();
        $this->display = new EditorDisplayOptions($this->invalidate(...));
        $this->decorations = new DecorationStore($this->invalidate(...));
        // Injected when the container builds it, so the pickers outlive this
        // widget being detached; direct construction (tests) still gets one.
        $this->overlayHost = $overlayHost ?? new OverlayHost(null, $this->styles, $this->cursorStyle);
        $this->findModal = new FindInFilesModal($this->invalidate(...), $this->styles, $this->cursorStyle, $this->translator);
        $this->document = new Document('');
        $this->highlighter = $this->placeholderHighlighter();
        $this->foldModel = new FoldModel($this->document);
        $this->scroll = new EditorScroll($this->document, $this->foldModel, $this->invalidate(...));
        $this->multiCaret = $this->newMultiCaret($this->document);
        $this->completion = $this->newEditorCompletion($this->document);
        $this->autoPair = new AutoPairService();
        $this->mouse = $this->newEditorMouse($this->document);
        $this->structural = $this->newEditorStructural($this->document);
        $this->actions = $this->newEditorActions($this->document, new ClipboardRing());
        $this->actionRouter = new EditorActionRouter(
            fn (): EditorCallbacks => $this->callbacks,
            fn (): array => [$this->document->getCursorLine(), $this->document->getCursorColUtf16()],
        );
    }

    private function newEditorStructural(Document $document): EditorStructural
    {
        return new EditorStructural(
            $document,
            $this->autoPair,
            $this->invalidate(...),
            $this->notifyChange(...),
            $this->openListOverlay(...),
        );
    }

    private function newEditorMouse(Document $document): EditorMouse
    {
        return new EditorMouse(
            $document,
            $this->foldModel,
            $this->decorations,
            $this->multiCaret,
            $this->invalidate(...),
            $this->closeCompletion(...),
            $this->centerOnLine(...),
            fn (int $line, int $index) => $this->callbacks->codeLens?->runCodeLens($line, $index),
        );
    }

    private function newMultiCaret(Document $document): MultiCaret
    {
        return new MultiCaret(
            $document,
            $this->invalidate(...),
            $this->notifyChange(...),
        );
    }

    private function newEditorCompletion(Document $document): EditorCompletion
    {
        return new EditorCompletion(
            $document,
            $this->invalidate(...),
            $this->notifyChange(...),
            $this->isInsertable(...),
            fn (int $l, int $c) => $this->callbacks->completion?->requestCompletion($l, $c),
            fn (array $raw) => $this->callbacks->completion?->resolveCompletion($raw),
            fn (array $raw, \Closure $onDetail) => $this->callbacks->completion?->resolveCompletionDetail($raw, $onDetail),
        );
    }

    private function newEditorActions(Document $document, ClipboardRing $clipboard): EditorActions
    {
        return new EditorActions($document, $this->multiCaret, $this->completion, $this->structural, $this->foldModel, $this->autoPair, $this, $clipboard, $this->translator, $this->flash);
    }

    // --- Public API --------------------------------------------------------

    public function openFile(string $path, string $content): void
    {
        $this->showDocument($path, new Document($content));
    }

    /**
     * Display an existing document (buffer switch: cursor, undo history and
     * diagnostics are preserved by the caller's Buffer).
     *
     * @param array<int, list<array{severity: int, message: string, col: int, endCol?: int, code?: int|string, source?: string, tags?: list<int>}>> $diagnostics
     * @param CodeHighlighter|null                                                            $highlighter buffer-owned highlighter
     *                                                                                                     whose cache survives switches
     * @param ResolvedEditorConfig|null                                                       $editorConfig the file's resolved
     *                                                                                                      `.editorconfig`, for the 'auto' indent branch
     */
    public function showDocument(string $path, Document $document, array $diagnostics = [], ?CodeHighlighter $highlighter = null, ?ResolvedEditorConfig $editorConfig = null): void
    {
        $this->filePath = '' !== $path ? $path : null;
        $this->document = $document;
        $this->currentEditorConfig = $editorConfig;
        $this->applyEffectiveIndent();
        $this->highlighter = $highlighter ?? $this->placeholderHighlighter();
        if (null === $highlighter) {
            $this->highlighter->setFile($path);
        }
        $this->decorations->resetForBuffer($diagnostics, $document->getLineCount());
        $this->multiCaret = $this->newMultiCaret($document);
        // Let undo/redo snapshots capture and restore this document's secondary
        // carets (bound to this specific MultiCaret, not whichever is active later).
        $mc = $this->multiCaret;
        $document->setCaretHooks($mc->carets(...), static function (array $extras) use ($mc): void {
            $mc->setExtras($extras);
        });
        $this->completion = $this->newEditorCompletion($document);
        $this->foldModel = new FoldModel($document);
        $this->scroll = new EditorScroll($document, $this->foldModel, $this->invalidate(...));
        $this->mouse = $this->newEditorMouse($document);
        $this->structural = $this->newEditorStructural($document);
        $this->actions = $this->newEditorActions($document, $this->actions->clipboardRing());
        $this->overlayHost->closeList();
        $this->invalidate();
    }

    /**
     * Open a modal list overlay (recent files, later: hover docs etc.).
     *
     * @param list<array{label: string, hint: string, value: string}> $items
     * @param callable(string): void                                  $onPick receives the picked item's value
     */
    #[\Override]
    public function openListOverlay(string $title, array $items, callable $onPick): void
    {
        $this->closeSiblingFloaters();
        $this->overlayHost->openList($title, $items, $onPick);
    }

    /**
     * Opening any floating surface closes the others — the same rule the full
     * views follow ({@see \Porta\Workbench\View\ViewRegistry}): the pickers share the pane span
     * slot with the Welcome and Find-in-Files modals, and two writers painting
     * the same frame would fight over it.
     */
    private function closeSiblingFloaters(): void
    {
        $this->views?->close('welcome');
        $this->findModal->close();
    }

    #[\Override]
    public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose, string $emptyMessage = ''): void
    {
        $this->closeSiblingFloaters();
        $this->overlayHost->openSearch($title, $onQuery, $onPick, $onClose, $emptyMessage);
    }

    /** @param list<SearchRow> $rows */
    #[\Override]
    public function setSearchRows(array $rows): void
    {
        $this->overlayHost->setSearchRows($rows);
    }

    #[\Override]
    public function closeSearch(): void
    {
        $this->overlayHost->closeSearch();
    }

    public function isSearchOverlayOpen(): bool
    {
        return $this->overlayHost->isSearchOpen();
    }

    /** Feed one key event to the open search modal (from the global input path). */
    public function handleSearchOverlayInput(string $data): void
    {
        $this->overlayHost->handleSearchInput($data, $this->getKeybindings());
    }

    /** The Find-in-Files search dialog, so the project-search controller can drive it. */
    public function findModal(): FindInFilesModal
    {
        return $this->findModal;
    }

    public function isFindModalOpen(): bool
    {
        return $this->findModal->isOpen();
    }

    /** Feed one key event to the open Find-in-Files modal (from the global input path). */
    public function handleFindModalInput(string $data): void
    {
        $this->findModal->handleInput($data, $this->getKeybindings());
    }

    /** Scroll the open Find-in-Files modal's result preview (mouse wheel). */
    public function scrollFindModal(int $delta): void
    {
        $this->findModal->scrollPreview($delta);
    }

    /**
     * The one view option that is not a plain `display()` call, and the reason
     * this pair stayed on the widget when the other nine delegations left:
     * turning the strip off also drops the marks behind it, which needs the
     * decoration store the options object has no business knowing about.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- $on is the desired STATE, which is this setter's whole subject
    #[\Override]
    public function setVcsGutter(bool $on): void
    {
        if ($this->display->setVcsGutter($on) && !$on) {
            $this->decorations->setVcsMarks([]); // nothing lingers when the strip hides
        }
    }

    /**
     * Set the per-language indent config (languageId => {style, size}); re-applies
     * to the current document immediately and to every document shown afterwards
     * (buffer switches re-apply via {@see showDocument}).
     *
     * @param array<string, LanguageIndent> $indents
     */
    #[\Override]
    public function setLanguageIndents(array $indents): void
    {
        $this->languageIndents = $indents;
        $this->applyEffectiveIndent();
        $this->invalidate();
    }

    /**
     * Apply the effective indent for the current file's language: an explicit
     * per-language style/size wins; 'auto' takes the file's `.editorconfig`
     * value, and failing that what the file itself does. A file whose language
     * has no entry in the map (e.g. plaintext, or a bare editor with no config)
     * keeps the document's own auto-detected/`.editorconfig` indent.
     */
    private function applyEffectiveIndent(): void
    {
        $config = $this->languageIndents[ProjectPaths::languageId($this->filePath ?? '')] ?? null;
        if (null === $config) {
            return;
        }

        // 'auto' asks the file, then the config, then the file again — which is
        // what a person means by auto, and what PhpStorm's "detect existing
        // file indents" does. It used to mean "unix defaults, size 2", so a
        // four-space PHP file with no .editorconfig drew an indent guide every
        // two columns and inserted half an indent on Tab.
        $usesTabs = match ($config->indentStyle) {
            'spaces' => false,
            'tabs' => true,
            default => $this->currentEditorConfig->usesTabs ?? $this->document->usesTabs(),
        };
        $size = 'auto' === $config->indentSize
            ? $this->currentEditorConfig->indentSize ?? $this->document->indentSize()
            : (int) $config->indentSize;

        $this->document->setIndent($usesTabs ? IndentKind::Tabs : IndentKind::Spaces, $size);
    }

    /**
     * Open a single-line input prompt (go to line, rename symbol).
     */
    #[\Override]
    public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false): void
    {
        $this->closeSiblingFloaters();
        $this->overlayHost->openPrompt($label, $initial, $onSubmit, $danger);
    }

    #[\Override]
    public function setSignatureHint(?string $hint, ?array $activeRange = null): void
    {
        $next = null === $hint ? null : new SignatureHint($hint, $activeRange);
        if ($this->signatureHint?->label !== $next?->label || $this->signatureHint?->activeRange !== $next?->activeRange) {
            $this->signatureHint = $next;
            $this->invalidate();
        }
    }

    #[\Override]
    public function fireCopy(string $text): void
    {
        $this->callbacks->file?->copy($text);
    }

    #[\Override]
    public function fireSignatureHelp(int $line, int $utf16Col): void
    {
        $this->callbacks->completion?->requestSignatureHelp($line, $utf16Col);
    }

    /** The handler validates via prepareRename, then owns the prompt + rename. */
    #[\Override]
    public function requestRename(int $line, int $utf16Col, string $word): void
    {
        $this->callbacks->refactor?->requestPrepareRename($line, $utf16Col, $word);
    }

    #[\Override]
    public function contentRows(): int
    {
        return $this->scroll->contentRows();
    }

    #[\Override]
    public function autoPairsEnabled(): bool
    {
        return $this->display->autoPairs();
    }

    /** Install the coordinator's outbound hooks (see {@see EditorCallbacks}). */
    public function setCallbacks(EditorCallbacks $callbacks): void
    {
        $this->callbacks = $callbacks;
    }

    #[\Override]
    public function getDocument(): Document
    {
        return $this->document;
    }

    #[\Override]
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /** The active buffer's highlight language (see CodeHighlighter), or null. */
    public function getLanguage(): ?string
    {
        return $this->highlighter->getLanguage();
    }

    /**
     * The document's structural scopes (namespace / class / function), memoized
     * by document identity + version. Empty for non-PHP files. Shared by the
     * breadcrumbs bar and sticky scroll.
     *
     * @return list<array{label: string, startLine: int, endLine: int, kind: string}>
     */
    public function scopes(): array
    {
        if (null === $this->filePath) {
            return [];
        }
        // PHP is scanned offline — instant, and right with no server running.
        // Everything else takes the language server's own symbols, which reach
        // the same shape, so the breadcrumb trail AND the sticky band work in
        // every language rather than only in PHP.
        if (!str_ends_with($this->filePath, '.php')) {
            return $this->documentScopes->forPath($this->filePath);
        }
        $docId = spl_object_id($this->document);
        $key = $docId.':'.$this->document->getVersion();
        if ($key === $this->scopeKey) {
            return $this->scopeCache;
        }

        // A different document must never show the previous file's trail, and
        // a first look must not open blank — those scan in place. Only a
        // same-document edit debounces: the trail and the sticky band stay
        // stale until the typing pauses, so a keystroke costs no whole-file
        // tokenizer pass. (No scheduler = bare test construction: stay
        // synchronous, the rigs need determinism, the container always has one.)
        if (null === $this->scheduler || !str_starts_with($this->scopeKey, $docId.':')) {
            $this->scopeCache = BreadcrumbScanner::scan($this->document->getText());
            $this->scopeKey = $key;

            return $this->scopeCache;
        }

        // Re-arm only when the version moved on, not per call: scopes() runs
        // on every frame (sticky band + trail), and an ambient repaint stream
        // pushing the timer out forever would keep the trail stale for as long
        // as anything animates.
        if ($this->scopeRescan?->key !== $key) {
            if (null !== $this->scopeRescan) {
                $this->scheduler->cancel($this->scopeRescan->timerId);
            }
            $this->scopeRescan = new ScopeRescan(
                $this->scheduler->delay(self::SCOPE_DEBOUNCE_SECONDS, function (): void {
                    $this->scopeRescan = null;
                    // Re-key from the live document — it may have moved on (or
                    // been swapped) since the edit that armed this timer.
                    $this->scopeCache = BreadcrumbScanner::scan($this->document->getText());
                    $this->scopeKey = spl_object_id($this->document).':'.$this->document->getVersion();
                    $this->invalidate(); // the settled trail needs a frame to show
                }),
                $key,
            );
        }

        return $this->scopeCache;
    }

    /**
     * Place the cursor from a click relative to the editor's top-left.
     * $relRow/$relCol are 0-based cells within the editor's rect.
     */
    public function clickAt(int $relRow, int $relCol): void
    {
        $this->mouse->clickAt($relRow, $relCol, $this->viewport());
    }

    /** The gutter's rendered width in columns (git strip + numbers + marker). */
    public function gutterWidth(): int
    {
        return $this->lastGutterWidth;
    }

    /**
     * The caret's widget-relative screen cell (row includes the sticky band,
     * column includes the gutter), for anchoring keyboard-opened popups at the
     * caret the way mouse-opened ones anchor at the pointer.
     *
     * @return array{row: int, col: int}
     */
    public function caretScreenCell(): array
    {
        $row = max(0, $this->foldModel->screenRowFor($this->scroll->vScroll(), $this->document->getCursorLine())) + \count($this->stickyLines);
        $col = max($this->lastGutterWidth, $this->lastGutterWidth + $this->document->getCursorDisplayCol() - $this->scroll->hScroll());

        return ['row' => $row, 'col' => $col];
    }

    /** The document line rendered at a widget-relative row (sticky/fold-aware), or null past the end. */
    public function documentLineAt(int $relRow): ?int
    {
        $stickyCount = \count($this->stickyLines);
        if ($relRow >= 0 && $relRow < $stickyCount) {
            return $this->stickyLines[$relRow];
        }

        return $this->foldModel->docLineAtRow($this->scroll->vScroll(), max(0, $relRow - $stickyCount));
    }

    /** Begin a mouse selection: place the caret at the press and drop the anchor there. */
    public function beginSelectionAt(int $relRow, int $relCol): void
    {
        $this->mouse->beginSelectionAt($relRow, $relCol, $this->viewport());
    }

    /** Extend the selection to the pointer (drag / release / Shift+click), anchoring at the caret if needed. */
    public function extendSelectionTo(int $relRow, int $relCol): void
    {
        $this->mouse->extendSelectionTo($relRow, $relCol, $this->viewport());
    }

    /** Select the word under the pointer (double-click); places a bare caret when off a word. */
    public function selectWordAt(int $relRow, int $relCol): void
    {
        $this->mouse->selectWordAt($relRow, $relCol, $this->viewport());
    }

    /** Select the whole line under the pointer (triple-click). */
    public function selectLineAt(int $relRow, int $relCol): void
    {
        $this->mouse->selectLineAt($relRow, $relCol, $this->viewport());
    }

    /** Add a caret at the pointer, or remove the one already there (Alt+Click). */
    public function addOrRemoveCaretAt(int $relRow, int $relCol): void
    {
        $this->mouse->addOrRemoveCaretAt($relRow, $relCol, $this->viewport());
    }

    /** Begin a mouse column (rectangular) selection at the press (Alt+Shift+drag). */
    public function beginColumnSelectionAt(int $relRow, int $relCol): void
    {
        $this->mouse->beginColumnSelectionAt($relRow, $relCol, $this->viewport());
    }

    /** Extend the column selection to the pointer, rebuilding the rectangle. */
    public function extendColumnSelectionTo(int $relRow, int $relCol): void
    {
        $this->mouse->extendColumnSelectionTo($relRow, $relCol, $this->viewport());
    }

    /** The last-render viewport geometry: {@see EditorMouse} maps click cells through it, the LSP inlay-hint range is derived from it. */
    public function viewport(): EditorViewport
    {
        // The fold marker is the gutter's second-to-last column (… marker, FOLD,
        // trailing space); with no gutter at all this goes negative, which no
        // click column can equal.
        return new EditorViewport(
            $this->scroll->vScroll(),
            $this->scroll->hScroll(),
            $this->lastGutterWidth,
            $this->lastGutterWidth - 2,
            $this->lastStripeCol,
            $this->lastRenderRows,
            $this->stickyLines,
            $this->wrapWidth(),
            $this->scroll->vScrollSegment(),
        );
    }

    /** Centre the viewport on a line (the stripe-jump target). */
    private function centerOnLine(int $line): void
    {
        $this->scroll->centerOnLine($line);
    }

    /**
     * Scroll the viewport by $delta rows without moving the caret — the view
     * detaches from the caret and snaps back on the next caret motion.
     */
    public function scrollBy(int $delta): void
    {
        $this->scroll->scrollBy($delta);
        // Viewport-scoped LSP features (inlay hints) fetch only a ranged
        // window; a detach-scroll can leave it, and no didChange would ever
        // tell them. The handler debounces, so a wheel flood costs one fetch.
        $this->callbacks->completion?->viewportScrolled();
    }

    /**
     * Scroll the view horizontally without moving the caret (horizontal wheel
     * or shift+wheel), clamped so the widest visible line stays reachable.
     * The next caret move snaps the view back via followCursor.
     */
    public function scrollHBy(int $cols): void
    {
        $doc = $this->document;
        $vScroll = $this->scroll->vScroll();
        $widest = 0;
        $last = min($doc->getLineCount() - 1, $vScroll + $this->scroll->contentRows() - 1);
        for ($line = $vScroll; $line <= $last; ++$line) {
            $widest = max($widest, $doc->lineDisplayWidth($line));
        }
        $this->scroll->scrollHBy($cols, $widest - 10);
    }

    /**
     * Insert text at the caret through the full change pipeline (undo history,
     * LSP didChange, invalidation) — the programmatic seam for plugins.
     */
    #[\Override]
    public function insertText(string $text): void
    {
        $this->document->insert($text);
        $this->notifyChange();
        $this->invalidate();
    }

    /**
     * Move the cursor to an LSP position and scroll it into view.
     */
    #[\Override]
    public function jumpTo(int $line, int $utf16Col): void
    {
        $doc = $this->document;
        $byteCol = $doc->utf16ColToByteCol($line, $utf16Col);
        $doc->setCursor($line, $byteCol);
        // Center the target line in the viewport
        $this->scroll->centerCursor();
        $this->closeCompletion();
        $this->invalidate();
    }

    /** @return array{errors: int, warnings: int} */
    public function getDiagnosticCounts(): array
    {
        return $this->decorations->diagnosticCounts();
    }

    /**
     * Diagnostic message on the cursor line, for the status bar.
     */
    public function getCursorDiagnostic(): ?string
    {
        return $this->decorations->diagnosticMessageAt($this->document->getCursorLine());
    }

    /**
     * @param list<CompletionItem> $items
     */
    public function showCompletions(array $items): void
    {
        $this->completion->show($items);
    }

    public function isCompletionOpen(): bool
    {
        return $this->completion->isOpen();
    }

    // --- Input -------------------------------------------------------------

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }

        // Bracketed paste: buffer chunks between ESC[200~ / ESC[201~
        $pastedText = $this->processBracketedPaste($data);
        if (null !== $pastedText) {
            $this->document->insert($pastedText);
            $this->notifyChange();
            if ('' === $data) {
                return;
            }
        }
        if (null === $pastedText && '' === $data && $this->isBufferingPaste()) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($this->overlayHost->handleInput($data, $kb)) {
            return;
        }

        if (null !== $this->signatureHint && $kb->matches($data, 'select_cancel')) {
            $this->setSignatureHint(null);

            return;
        }

        if ($this->completion->handleSnippetTabStop($data, $kb)) {
            return;
        }

        // Live templates: Tab expands the abbreviation at the caret. After the
        // snippet-tab-stop check, so Tab walks the stops of what it just
        // expanded, and before the editing verbs, so Tab still indents when
        // there is no template there.
        $language = ProjectPaths::languageId($this->filePath ?? '');
        if (null !== $this->filePath && $this->completion->expandTemplate($data, $kb, $this->callbacks->completion?->templatesFor($language) ?? TemplateCatalog::forLanguage($language))) {
            return;
        }

        if ($this->completion->isOpen() && $this->completion->handleInput($data, $kb)) {
            return;
        }

        // Editor-level actions (keymap + menu) fire through the action router;
        // anything it does not claim falls through to the editing verbs.
        if ($this->actionRouter->dispatchKey($data, $kb)) {
            return;
        }

        $this->actions->handleKey($data, $kb);
    }

    /**
     * The decoration layers for the buffer on display — git marks, breakpoints,
     * bookmarks, diagnostics, search matches, inlay hints, blame.
     *
     * One accessor in place of eight forwarding setters: this widget used to
     * IMPLEMENT {@see EditorDecorationSink}, so every new decoration was a
     * method on the largest class in the codebase and moved its
     * `too-many-methods` ceiling. The store implements the interface now, and
     * the container binds it through here so that consumers get the instance
     * this widget actually renders — not a second one built beside it.
     */
    public function decorations(): DecorationStore
    {
        return $this->decorations;
    }

    /**
     * The view options for this editor — line numbers, sticky scroll, indent
     * guides, the vcs gutter, inline diagnostics, auto-pairs, soft wrap.
     *
     * One accessor in place of eleven forwarding methods, for the reason
     * {@see decorations()} gives: this widget used to implement the whole
     * interface, so every new view option was a method on the largest class in
     * the codebase — and soft wrap was the one that finally could not fit.
     */
    /**
     * The width of one visual row while soft wrap is on, or null when it is
     * off — the one thing caret motion needs that only the render knows.
     * Answered from the last rendered geometry, which is where a content width
     * comes from at all.
     */
    #[\Override]
    public function wrapWidth(): ?int
    {
        $width = $this->scroll->contentWidth();

        return $this->display->softWrap() && $width > 0 ? $width : null;
    }

    public function display(): EditorDisplayOptions
    {
        return $this->display;
    }

    /**
     * Supply LSP fold regions for the current document version — {@see FoldModel}
     * falls back to its brace scan once the document changes.
     *
     * @param list<array{0: int, 1: int}> $regions [startLine, endLine]
     */
    public function setFoldRegions(array $regions): void
    {
        $this->foldModel->setRegions($regions, $this->document->getVersion());
    }

    /** Whether keyboard column selection is on; the status bar shows it. */
    public function columnMode(): bool
    {
        return $this->actions->columnMode();
    }

    /**
     * Invoke an editor action by its id — the entry point the application menu
     * uses so a menu item fires exactly the same logic as pressing its key.
     * Unknown ids are a safe no-op.
     */
    #[\Override]
    public function runAction(string $action): void
    {
        if (!$this->actionRouter->run($action)) {
            $this->actions->run($action);
        }
    }

    /**
     * Route a left-click (in editor-pane-relative coords) at the completion
     * popup: it only consumes a click that lands on one of its rows (so a
     * click elsewhere falls through to caret placement, which closes it).
     * The pickers take SCREEN coordinates through {@see handlePickerClick} —
     * they float over the workspace, not inside this pane.
     */
    public function handlePopupClick(int $relRow, int $relCol): bool
    {
        return $this->completion->handleClick($relRow, $relCol);
    }

    /**
     * Route a left-click (screen coords) at the open floating picker; the
     * topmost surface wins ({@see OverlayHost::topmostSurface}).
     */
    public function handlePickerClick(int $row, int $col): bool
    {
        return $this->overlayHost->pickerClickAt($row, $col);
    }

    /**
     * Wheel over an open editor popup: move ITS selection instead of
     * scrolling the buffer underneath. Returns whether a popup consumed it
     * (the find modal keeps its own dedicated scroll path).
     */
    public function movePopupSelection(int $delta): bool
    {
        if ($this->overlayHost->moveSelection($delta)) {
            return true;
        }
        if ($this->completion->isOpen()) {
            $this->completion->moveSelection($delta);

            return true;
        }

        return false;
    }

    /**
     * Highlight the popup row under the pointer (editor-pane-relative coords).
     * Returns whether the highlighted row actually changed (so the caller can
     * skip a redundant re-render on a same-row hover).
     */
    public function handlePopupHover(int $relRow, int $relCol): bool
    {
        return $this->completion->handleHover($relRow, $relCol);
    }

    /** Tint the picker row (screen coords) under the pointer; returns whether it changed. */
    public function handlePickerHover(int $row, int $col): bool
    {
        return $this->overlayHost->pickerHoverAt($row, $col);
    }

    /** Whether any floating picker (list overlay, Search Everywhere, prompt) is open. */
    public function isAnyPickerOpen(): bool
    {
        return $this->overlayHost->anyOpen();
    }

    /**
     * Lay the open pickers out against the pane-union bounds (screen coords) —
     * {@see \Porta\Overlay\Presenter\PickerModalPresenter}'s per-frame call.
     *
     * @param array{int, int, int, int} $bounds
     *
     * @return list<array{rows: list<string>, top: int, col: int, width: int}>
     */
    public function layoutPickers(array $bounds): array
    {
        return $this->overlayHost->layout($bounds);
    }

    /** Continue an armed Find-in-Files drag — corner resize or title-bar move (editor-relative coords). */
    public function continueFindModalDrag(int $relRow, int $relCol, bool $release): bool
    {
        return $this->findModal->continueDragTo($relRow, $relCol, $release);
    }

    /** Disarm a stale Find-in-Files drag (the router's fresh-press sweep). */
    public function cancelFindModalDrag(): void
    {
        $this->findModal->cancelDrag();
    }

    /** Cancel any active snippet session (e.g. before an external edit). */
    public function clearSnippetSession(): void
    {
        $this->completion->clearSnippet();
    }

    /**
     * The empty-state placeholder highlighter. Always the built-in engine: it
     * colours no real buffer (a buffer brings its own), so routing it through
     * the registry would only add a dependency cycle.
     */
    private function placeholderHighlighter(): CodeHighlighter
    {
        return new CodeHighlighter(new TempestHighlightProvider($this->syntaxTheme), $this->syntaxTheme);
    }

    /**
     * How the current language spells a comment, or null when it has none.
     *
     * This used to be a fourteen-arm match returning a prefix string, which
     * could not express "block comments only" and so answered null for Twig —
     * leaving `Ctrl+/` dead there and in thirty other grammars. The table is
     * {@see CommentSyntax}'s now; the widget's job is only to say which
     * language the buffer is in.
     */
    #[\Override]
    public function commentSyntax(): ?CommentSyntax
    {
        return CommentSyntax::forGrammar($this->highlighter->getLanguage());
    }

    /** How the current language ends a statement, or null when it has none. */
    #[\Override]
    public function statementSyntax(): ?StatementSyntax
    {
        return StatementSyntax::forGrammar($this->highlighter->getLanguage());
    }

    private function closeCompletion(): void
    {
        $this->completion->close();
    }

    // --- Multi-cursor -------------------------------------------------------

    public function clearExtraCarets(): void
    {
        $this->multiCaret->clear();
    }

    #[\Override]
    public function notifyChange(): void
    {
        // The one place core hears about every edit, which is what the git
        // gutter marks needed: they describe the file on DISK and are
        // recomputed only on a save, a reload or a buffer switch, so between
        // those they used to sit still while the lines under them moved. The
        // store works out where and by how much from these two numbers — see
        // {@see DecorationStore::remapVcsMarksForEdit()}.
        $this->decorations->remapVcsMarksForEdit($this->document->getCursorLine(), $this->document->getLineCount());
        $this->invalidate();
        $this->callbacks->completion?->scheduleDidChange($this->document);
    }

    private function hasControlBytes(string $data): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $data) || str_contains($data, "\x1b");
    }

    /** True when $data is a printable character safe to insert (not a control key). */
    #[\Override]
    public function isInsertable(string $data): bool
    {
        return '' !== $data && !$this->hasControlBytes($data);
    }

    // --- Rendering -----------------------------------------------------------

    /**
     * The sticky-scroll headers for a viewport whose top row shows the doc line at
     * $vScroll: enclosing scopes scrolled off the top, capped to leave content rows.
     *
     * @return list<array{label: string, line: int}>
     */
    private function stickyHeadersAt(int $vScroll, int $rows): array
    {
        if (!$this->display->stickyScroll()) {
            return [];
        }
        $top = $this->foldModel->docLineAtRow($vScroll, 0) ?? 0;

        return BreadcrumbScanner::stickyHeaders($this->scopes(), $top, IntMath::clamp($rows - 4, 0, EditorScroll::MAX_STICKY_ROWS));
    }

    /**
     * {@see EditorRenderer::matchingBrackets()} memoized per (version, caret):
     * the scan is up to ~20k chars, and re-renders triggered by anything other
     * than an edit or caret move (diagnostics arriving, git marks, overlays)
     * would otherwise repeat it for an identical result.
     *
     * @return array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null
     */
    private function matchingBrackets(Document $doc): ?array
    {
        $key = $doc->getVersion().':'.$doc->getCursorLine().':'.$doc->getCursorCol();
        if ($key !== $this->bracketsKey) {
            $this->bracketsKey = $key;
            $this->bracketsMemo = EditorRenderer::matchingBrackets($doc);
        }

        return $this->bracketsMemo;
    }

    /**
     * Overlay the signature hint on the rendered rows and hand the completion
     * popup box to EditorCompletion (cleared first so a closed popup leaves
     * no stale box for mouse hit-testing).
     *
     * @param list<string> $result rendered rows
     *
     * @return list<string>
     */
    private function composePopups(array $result, EditorPopupGeometry $geo): array
    {
        // Both overlays anchor to the caret's on-screen row (past the sticky headers).
        $cursorScreenRow = max(0, $this->foldModel->screenRowFor($geo->vScroll, $geo->cursorLine)) + $geo->stickyCount;
        if (null !== $this->signatureHint) {
            EditorRenderer::signatureHint($result, $geo->columns, $geo->gutterWidth, $cursorScreenRow, $this->signatureHint->label, $this->styles, $this->signatureHint->activeRange);
        }

        $this->completion->setBox(null);
        if (null !== ($popup = $this->completion->popupState())) {
            $anchorCol = $geo->gutterWidth + ($geo->cursorDisplayCol - $geo->hScroll) - mb_strwidth($this->document->getWordBeforeCursor());
            $this->completion->setBox(EditorRenderer::completionPopup(
                $result,
                $geo->columns,
                $geo->rows,
                $cursorScreenRow,
                $anchorCol,
                $popup['items'],
                $popup['selected'],
                $popup['hovered'],
                $this->styles,
            ));
        }

        // signatureHint() and completionPopup() rewrite rows in place, keeping the shape.
        return $result;
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        // Timed as a whole (early returns included): the perf beat's edMs is
        // this widget's share of renderMs, the split that says whether a slow
        // frame is the editor row pipeline or the rest of the tree.
        $startNs = hrtime(true);
        try {
            $columns = $context->getColumns();
            $rows = max(1, $context->getRows() > 0 ? $context->getRows() : min(30, $this->document->getLineCount()));

            // No file open: show a centered hint instead of a blank editable
            // buffer, still compositing the floating overlays' spans so the
            // app-menu dropdown (and a modal's slice) stay visible over it.
            if (null === $this->filePath) {
                $this->lastGutterWidth = 0;
                $this->lastStripeCol = \PHP_INT_MAX; // no stripe over the empty-state pane

                return $this->withMenuOverlay($this->renderEmptyState($columns, $rows), $columns);
            }

            $doc = $this->document;
            $lineCount = $doc->getLineCount();
            $geo = $this->computeGeometry($columns, $lineCount);
            $this->lastGutterWidth = $geo->gutterWidth;
            $this->lastRenderRows = $rows;
            $this->lastStripeCol = $geo->stripeCol;

            // Both BEFORE the clamp, which reads them: the caret's screen row
            // depends on how many rows the lines above it occupy, and setting
            // them afterwards left the clamp a frame behind — the caret could
            // sit off screen for one paint after every Down.
            $this->scroll->setContentWidth($geo->contentWidth);
            $wrapLayout = $this->display->softWrap() ? new WrapLayout($doc) : null;
            $this->scroll->setRowsForLine(
                null === $wrapLayout && !$this->decorations->hasCodeLenses()
                    ? null // one row per line, and the old arithmetic exactly
                    : fn (int $line): int => \count($this->decorations->codeLensesAt($line)) + ($wrapLayout?->rowsFor($line, $geo->contentWidth) ?? 1),
            );

            // Clamp scroll to cursor: the caret must stay visible below whatever
            // sticky-scroll band the settled scroll position pins at the top.
            $cursorLine = $doc->getCursorLine();
            $this->scroll->clampToCaret($rows, fn (int $vScroll): int => \count($this->stickyHeadersAt($vScroll, $rows)));
            $vScroll = $this->scroll->vScroll();

            // The pinned headers for the settled scroll position.
            $sticky = $this->stickyHeadersAt($vScroll, $rows);
            $this->stickyLines = array_map(static fn (array $h): int => $h['line'], $sticky);
            $contentRows = $rows - \count($sticky);
            $this->scroll->setContentRows($contentRows);

            $cursorDisplayCol = $this->followCaret($doc, $cursorLine, $geo->contentWidth);
            $hScroll = $this->scroll->hScroll();

            // Highlight/render tab-expanded lines so widths line up with the
            // display-column math; the buffer keeps its raw tabs.
            $highlighted = $this->highlighter->highlight($doc->getVisualLines(), $doc->getVersion());
            $lastVisibleDocLine = $this->foldModel->docLineAtRow($vScroll, $contentRows - 1) ?? ($lineCount - 1);
            $decorations = $this->collectDecorations($doc, $vScroll, $lastVisibleDocLine);

            // Right-margin marker stripe: one styled cell per screen row, a whole-file
            // overview of diagnostics, breakpoints, change marks and search hits plus
            // a faint viewport thumb. Appended to every produced row so each reaches
            // the full terminal width.
            $marks = StripeMarks::from($this->decorations, $this->display->vcsGutter());
            $stripe = ErrorStripe::cells($marks, $rows, $lineCount, $vScroll, $lastVisibleDocLine, $this->styles);

            $assembler = new EditorRowAssembler(
                $doc,
                $this->foldModel,
                $this->decorations,
                $this->display->lineNumbers(),
                $this->display->indentGuides(),
                $this->display->vcsGutter(),
                $this->display->inlineDiagnostics(),
                $this->focused,
                $this->focused && !$this->findModal->isOpen() && !$this->overlayHost->anyOpen(), // an open modal owns the caret
                $this->styles,
                $this->cursorStyle,
                // Wrap and horizontal scroll are one knob read two ways — the
                // same slice offset — so only one of them can be driving.
                // Built once per frame: the memo is keyed on width and
                // document version, so the only lifetime worth anything is the
                // walk over this frame's rows — and the caret clamp above
                // shares it.
                $wrapLayout,
                // The live theme, so a semantic token resolves to the colour
                // the lexer would have used for that kind of symbol — and
                // follows a theme switch without waiting for the next answer.
                $this->syntaxTheme,
                // Computed per frame from the same scopes the trail and the
                // sticky band read, so the rule appears in every language they
                // work in. Passed in rather than pushed into the decoration
                // store: a store write invalidates, and this runs in render.
                $this->display->methodSeparators() ? MemberSeparators::linesFor($this->scopes()) : [],
                $this->display->showWhitespace(),
            );
            $result = $assembler->assemble(
                $geo,
                $decorations,
                $sticky,
                $highlighted,
                $stripe,
                $contentRows,
                $vScroll,
                $cursorLine,
                $cursorDisplayCol,
                $this->display->softWrap() ? 0 : $hScroll,
                $this->scroll->vScrollSegment(),
            );

            $result = $this->composePopups($result, new EditorPopupGeometry(
                columns: $columns,
                rows: $rows,
                vScroll: $vScroll,
                hScroll: $hScroll,
                cursorLine: $cursorLine,
                cursorDisplayCol: $cursorDisplayCol,
                gutterWidth: $geo->gutterWidth,
                stickyCount: \count($sticky),
            ));

            // The pickers (list overlay, Search Everywhere, prompt) float over the
            // whole workspace via PickerModalPresenter — nothing composites into
            // the pane's own lines here; the application-menu dropdown floats
            // above everything else.
            return $this->withMenuOverlay($result, $columns);
        } finally {
            $this->perf?->editorMark((hrtime(true) - $startNs) / 1_000_000_000.0);
        }
    }

    /**
     * The horizontal layout for a $lineCount-line document in $columns cells:
     * gutter = git strip (1) + line number + change marker + fold column +
     * trailing space, and the rightmost column always reserved for the error
     * stripe so diagnostics arriving or leaving never reflow the content.
     *
     * The fold column is unconditional, like the marker beside it: a column that
     * appeared only on files with foldable regions would shift the text sideways
     * as you moved between buffers.
     */
    /** The right-margin error stripe, which every row ends with. */
    private const int STRIPE_WIDTH = 1;

    private function computeGeometry(int $columns, int $lineCount): EditorGeometry
    {
        $numberWidth = LineNumberMode::Off === $this->display->lineNumbers() ? 0 : max(3, \strlen((string) $lineCount));
        // git strip + number + marker + fold + a trailing space, which is what
        // `EditorRowAssembler::gutterCell()` actually emits.
        $natural = ($this->display->vcsGutter() ? 1 : 0) + $numberWidth + 3;

        // ALL or NOTHING. The gutter is a fixed structure of single-column
        // cells, so a partial one would have to be cut through a marker; and a
        // row is `gutter + content + stripe`, so keeping a full gutter in a pane
        // that cannot seat one is how the editor came to render nine columns
        // into one and take the IDE down with a RenderException. Below the width
        // that seats the gutter, the stripe and a column of text, the gutter
        // goes and the text keeps the pane — which is what the pane is for.
        $fits = $columns >= ($natural + self::STRIPE_WIDTH + 1);
        $gutterWidth = $fits ? $natural : 0;
        $contentWidth = max(0, $columns - $gutterWidth - self::STRIPE_WIDTH);

        return new EditorGeometry($fits ? $numberWidth : 0, $gutterWidth, $contentWidth, $gutterWidth + $contentWidth);
    }

    /**
     * Advance the horizontal scroll to keep the caret in view and return its
     * display column (shifted right by any inlay hints before it). Follows only
     * when the caret actually moved since the last frame, so a manual scroll
     * (scrollHBy) survives parked-caret renders.
     */
    private function followCaret(Document $doc, int $cursorLine, int $contentWidth): int
    {
        $cursorDisplayCol = $doc->getCursorDisplayCol();
        $cursorDisplayCol += EditorRenderer::inlayShiftAt($doc, $cursorLine, $this->decorations->inlayHintsAt($cursorLine), $cursorDisplayCol);
        $cursorMoved = [$cursorLine, $doc->getCursorCol()] !== $this->lastFollowedCursor;
        $this->lastFollowedCursor = [$cursorLine, $doc->getCursorCol()];
        $this->scroll->followCursor($cursorDisplayCol, $contentWidth, $cursorMoved ? CaretMotion::Moved : CaretMotion::Parked);

        return $cursorDisplayCol;
    }

    /**
     * Gather the per-line decoration inputs for the frame. With multiple carets
     * every caret (primary + extras) is drawn the same way — a reverse-video
     * cell — so the range selection and the hardware-cursor bar are suppressed.
     */
    private function collectDecorations(Document $doc, int $vScroll, int $lastVisibleDocLine): EditorDecorations
    {
        $multiActive = $this->focused && $this->multiCaret->hasMultiple();

        // The bracketsMemo idiom, inline: the whole-viewport preg_match_all
        // repeats an identical result on every frame a toast, diagnostic or
        // git mark triggers — only these inputs can change its outcome.
        $key = \sprintf(
            '%d:%d:%d:%d:%d:%d:%d',
            $doc->getVersion(),
            $doc->getCursorLine(),
            $doc->getCursorCol(),
            (int) $this->focused,
            (int) $doc->hasSelection(),
            $vScroll,
            $lastVisibleDocLine,
        );
        if ($key !== $this->occurrencesMemo['key']) {
            $this->occurrencesMemo = [
                'key' => $key,
                'spans' => EditorRenderer::identifierOccurrences($doc, $this->focused ? PaneFocus::Focused : PaneFocus::Blurred, $vScroll, $lastVisibleDocLine),
            ];
        }

        return new EditorDecorations(
            multiActive: $multiActive,
            selection: $doc->getSelectionRange(),
            carets: $multiActive ? $this->multiCaret->allCarets() : [],
            occurrences: $this->occurrences->spansFor($this->filePath, $doc->getVersion(), $doc->getCursorLine(), $doc->getCursorColUtf16(), $this->occurrencesMemo['spans']),
            brackets: $this->focused ? $this->matchingBrackets($doc) : null,
        );
    }

    /**
     * The "no file open" placeholder: a single dimmed, centered hint on an
     * otherwise blank pane (no gutter, no cursor). Returns exactly $rows lines,
     * each exactly $columns wide.
     *
     * @return list<string>
     */
    private function renderEmptyState(int $columns, int $rows): array
    {
        // The LIVE binding, not a spelled-out key: this widget owns the map
        // that answers `goto_file`, and a rebind used to leave the one hint a
        // new user reads pointing at the old chord. Falls back to naming the
        // action when nothing is bound to it at all.
        $binding = ShortcutFormatter::format($this->getKeybindings()->getBindings('goto_file')[0] ?? '');
        $hint = '' === $binding
            ? $this->translator->trans('No file open · use Go to File to open one')
            : $this->translator->trans('No file open · press %key% to open a file', ['%key%' => $binding]);
        $text = AnsiText::truncate($hint, $columns);
        $textWidth = AnsiUtils::visibleWidth($text);
        $left = max(0, intdiv($columns - $textWidth, num2: 2));
        $hintRow = AnsiText::padRight(str_repeat(' ', $left).$this->styles->of(Role::CompletionHint)->apply($text), $columns);

        $middle = intdiv($rows, num2: 2);
        $lines = [];
        for ($i = 0; $i < $rows; ++$i) {
            $lines[] = $i === $middle ? $hintRow : AnsiText::blank($columns);
        }

        return $lines;
    }

    // --- Inlay hints -------------------------------------------------------

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return EditorKeymap::defaults();
    }
}
