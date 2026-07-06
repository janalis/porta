<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Editor\AutoPairService;
use Porta\Editor\BreadcrumbScanner;
use Porta\Editor\DiagnosticStyle;
use Porta\Editor\Document;
use Porta\Editor\EditorActions;
use Porta\Editor\EditorCompletion;
use Porta\Editor\EditorMouse;
use Porta\Editor\EditorScroll;
use Porta\Editor\EditorStructural;
use Porta\Editor\EditorViewport;
use Porta\Editor\FoldModel;
use Porta\Editor\MultiCaret;
use Porta\Highlight\CodeHighlighter;
use Porta\Input\TextInput;
use Porta\Widget\Overlay\OverlayHost;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\LineSpans;
use Porta\Widget\Render\MenuOverlay;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\CursorShape;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\BracketedPasteTrait;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * IDE-style code editor: line-number gutter, syntax highlighting,
 * vertical + horizontal scrolling, LSP diagnostics and a completion popup.
 */
final class CodeEditorWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface
{
    use BracketedPasteTrait;
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private Document $document;
    private CodeHighlighter $highlighter;
    private ?string $filePath = null;

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

    /** Mouse hit-testing + selection, rebuilt per document. */
    private EditorMouse $mouse;

    private MultiCaret $multiCaret;

    /** Structural selection (expand/shrink/surround) + merge-conflict nav, rebuilt per document. */
    private EditorStructural $structural;

    /** @var array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> keyed by 0-based line */
    private array $diagnostics = [];

    private bool $inlineDiagnostics = true;
    private bool $relativeLineNumbers = false;
    private bool $autoPairs = true;

    private readonly AutoPairService $autoPair;

    /** LSP completion + snippet machinery, rebuilt per document. */
    private EditorCompletion $completion;
    private FoldModel $foldModel;
    /** @var array<int, list<array{col: int, text: string}>> inlay hints per line: byte col => dim label */
    private array $inlayHints = [];

    /** @var (callable(): void)|null */
    private $onSaveRequested = null;
    /** @var (callable(): void)|null */
    private $onSaveAllRequested = null;
    /** @var (callable(Document): void)|null */
    private $onChanged = null;
    /** @var (callable(int, int): void)|null request completion at (line, utf16 col) */
    private $onCompletionRequested = null;
    /** @var (callable(array<string, mixed>): void)|null resolve an accepted completion item (for deferred auto-imports) */
    private $onCompletionResolve = null;
    /** @var (callable(int, int): void)|null request definition at (line, utf16 col) */
    private $onDefinitionRequested = null;
    /** @var (callable(int, int): void)|null request implementation at (line, utf16 col) */
    private $onImplementationRequested = null;
    /** @var (callable(int, int): void)|null request type definition at (line, utf16 col) */
    private $onTypeDefinitionRequested = null;
    /** @var (callable(): void)|null navigate back after a definition jump */
    private $onNavigateBack = null;
    /** @var (callable(string): void)|null text copied to the clipboard (for OSC 52) */
    private $onCopy = null;
    /** @var (callable(int): void)|null cycle to next (+1) / previous (-1) buffer */
    private $onBufferCycle = null;
    /** @var (callable(): void)|null open the recent-files switcher */
    private $onRecentFiles = null;
    /** @var (callable(): void)|null close the active buffer */
    private $onCloseBuffer = null;
    /** @var (callable(bool): void)|null open the find bar (true = with replace) */
    private $onFindRequested = null;
    /** @var (callable(): void)|null open the go-to-file palette */
    private $onGotoFile = null;

    /** @var array<int, list<array{start: int, end: int, current: bool}>> search matches by line (byte cols) */
    private array $searchMatches = [];

    /** @var array<int, string> 0-based line => 'added'|'modified'|'deleted' */
    private array $gitMarks = [];

    /** @var list<array{row: int, col: int, width: int, segment: string}> floating menu dropdown spans (local coords) */
    private array $menuOverlay = [];

    /** One-line signature hint shown above the cursor, cleared on movement. */
    private ?string $signatureHint = null;

    /** @var (callable(int, int): void)|null quick documentation at (line, utf16 col) */
    private $onQuickDoc = null;
    /** @var (callable(string, int, int): void)|null rename symbol to $newName at (line, utf16 col) */
    private $onRename = null;
    /** @var (callable(int, int, string): void)|null validate+begin a rename at (line, utf16 col, word) */
    private $onRenameRequested = null;
    /** @var (callable(int, int): void)|null find usages at (line, utf16 col) */
    private $onFindUsages = null;
    /** @var (callable(int, int): void)|null signature help at (line, utf16 col) */
    private $onSignatureHelp = null;
    /** @var (callable(int, int): void)|null quick fixes at (line, utf16 col) */
    private $onCodeAction = null;
    /** @var (callable(): void)|null file structure popup */
    private $onFileStructure = null;
    /** @var (callable(int, int): void)|null peek definition at (line, utf16 col) */
    private $onPeek = null;
    /** @var (callable(): void)|null reformat the whole file */
    private $onReformat = null;

    /** Editing-verb dispatcher (movement/clipboard/line ops), rebuilt per document. */
    private EditorActions $actions;

    /** Modal list-overlay + prompt chrome composited over the editor pane. */
    private readonly OverlayHost $overlayHost;

    public function __construct()
    {
        $this->overlayHost = new OverlayHost(fn () => $this->invalidate());
        $this->document = new Document('');
        $this->highlighter = new CodeHighlighter();
        $this->foldModel = new FoldModel($this->document);
        $this->scroll = new EditorScroll($this->document, $this->foldModel, fn () => $this->invalidate());
        $this->multiCaret = $this->newMultiCaret($this->document);
        $this->completion = $this->newEditorCompletion($this->document);
        $this->autoPair = new AutoPairService();
        $this->mouse = $this->newEditorMouse($this->document);
        $this->structural = $this->newEditorStructural($this->document);
        $this->actions = $this->newEditorActions($this->document, '');
    }

    private function newEditorStructural(Document $document): EditorStructural
    {
        return new EditorStructural(
            $document,
            $this->autoPair,
            fn () => $this->invalidate(),
            fn () => $this->notifyChange(),
            fn (string $title, array $items, callable $onPick) => $this->openListOverlay($title, $items, $onPick),
        );
    }

    private function newEditorMouse(Document $document): EditorMouse
    {
        return new EditorMouse(
            $document,
            $this->foldModel,
            $this->multiCaret,
            fn () => $this->invalidate(),
            fn () => $this->closeCompletion(),
            fn (int $line) => $this->centerOnLine($line),
        );
    }

    private function newMultiCaret(Document $document): MultiCaret
    {
        return new MultiCaret(
            $document,
            fn () => $this->invalidate(),
            fn () => $this->notifyChange(),
        );
    }

    private function newEditorCompletion(Document $document): EditorCompletion
    {
        return new EditorCompletion(
            $document,
            fn () => $this->invalidate(),
            fn () => $this->notifyChange(),
            fn (string $d) => $this->isInsertable($d),
            fn (int $l, int $c) => null !== $this->onCompletionRequested ? ($this->onCompletionRequested)($l, $c) : null,
            fn (array $raw) => null !== $this->onCompletionResolve ? ($this->onCompletionResolve)($raw) : null,
        );
    }

    private function newEditorActions(Document $document, string $clipboard): EditorActions
    {
        return new EditorActions(
            $document,
            $this->multiCaret,
            $this->completion,
            $this->structural,
            $this->foldModel,
            $this->autoPair,
            fn () => $this->invalidate(),
            fn () => $this->notifyChange(),
            fn (string $label, string $initial, callable $onSubmit) => $this->openPrompt($label, $initial, $onSubmit),
            fn (int $line, int $col) => $this->jumpTo($line, $col),
            fn (?string $hint) => $this->setSignatureHint($hint),
            fn (string $text) => null !== $this->onCopy ? ($this->onCopy)($text) : null,
            fn (int $line, int $col) => null !== $this->onSignatureHelp ? ($this->onSignatureHelp)($line, $col) : null,
            function (int $line, int $col, string $word): void {
                if (null !== $this->onRenameRequested) {
                    // The IDE validates via prepareRename, then opens the prompt.
                    ($this->onRenameRequested)($line, $col, $word);
                } elseif (null !== $this->onRename) {
                    $this->openPrompt('Rename to', $word, function (string $value) use ($line, $col): void {
                        if ('' !== trim($value)) {
                            /** @var callable(string, int, int): void $this->onRename */
                            ($this->onRename)(trim($value), $line, $col);
                        }
                    });
                }
            },
            fn (): int => $this->scroll->contentRows(),
            fn (): ?string => $this->commentPrefix(),
            fn (): bool => $this->autoPairs,
            fn (string $d): bool => $this->isInsertable($d),
            $clipboard,
        );
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
     * @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $diagnostics
     * @param CodeHighlighter|null                                                            $highlighter buffer-owned highlighter
     *                                                                                                     whose cache survives switches
     */
    public function showDocument(string $path, Document $document, array $diagnostics = [], ?CodeHighlighter $highlighter = null): void
    {
        $this->filePath = '' !== $path ? $path : null;
        $this->document = $document;
        if (null !== $highlighter) {
            $this->highlighter = $highlighter;
        } else {
            $this->highlighter = new CodeHighlighter();
            $this->highlighter->setFile($path);
        }
        $this->diagnostics = $diagnostics;
        $this->multiCaret = $this->newMultiCaret($document);
        // Let undo/redo snapshots capture and restore this document's secondary
        // carets (bound to this specific MultiCaret, not whichever is active later).
        $mc = $this->multiCaret;
        $document->setCaretHooks(static fn (): array => $mc->carets(), static function (array $extras) use ($mc): void {
            $mc->setExtras($extras);
        });
        $this->completion = $this->newEditorCompletion($document);
        $this->foldModel = new FoldModel($document);
        $this->scroll = new EditorScroll($document, $this->foldModel, fn () => $this->invalidate());
        $this->mouse = $this->newEditorMouse($document);
        $this->structural = $this->newEditorStructural($document);
        $this->actions = $this->newEditorActions($document, $this->actions->clipboardValue());
        $this->inlayHints = [];
        $this->closeCompletion();
        $this->overlayHost->closeList();
        $this->invalidate();
    }

    /**
     * Open a modal list overlay (recent files, later: hover docs etc.).
     *
     * @param list<array{label: string, hint: string, value: string}> $items
     * @param callable(string): void                                  $onPick receives the picked item's value
     */
    public function openListOverlay(string $title, array $items, callable $onPick): void
    {
        $this->overlayHost->openList($title, $items, $onPick);
    }

    public function isOverlayOpen(): bool
    {
        return $this->overlayHost->isListOpen();
    }

    public function onBufferCycle(callable $callback): self
    {
        $this->onBufferCycle = $callback;

        return $this;
    }

    public function onRecentFiles(callable $callback): self
    {
        $this->onRecentFiles = $callback;

        return $this;
    }

    public function onCloseBuffer(callable $callback): self
    {
        $this->onCloseBuffer = $callback;

        return $this;
    }

    public function onFindRequested(callable $callback): self
    {
        $this->onFindRequested = $callback;

        return $this;
    }

    public function onGotoFile(callable $callback): self
    {
        $this->onGotoFile = $callback;

        return $this;
    }

    public function onQuickDoc(callable $callback): self
    {
        $this->onQuickDoc = $callback;

        return $this;
    }

    public function onRename(callable $callback): self
    {
        $this->onRename = $callback;

        return $this;
    }

    public function onRenameRequested(callable $callback): self
    {
        $this->onRenameRequested = $callback;

        return $this;
    }

    public function onFindUsages(callable $callback): self
    {
        $this->onFindUsages = $callback;

        return $this;
    }

    public function onSignatureHelp(callable $callback): self
    {
        $this->onSignatureHelp = $callback;

        return $this;
    }

    public function onCodeAction(callable $callback): self
    {
        $this->onCodeAction = $callback;

        return $this;
    }

    public function onFileStructure(callable $callback): self
    {
        $this->onFileStructure = $callback;

        return $this;
    }

    public function onPeek(callable $callback): self
    {
        $this->onPeek = $callback;

        return $this;
    }

    public function setInlineDiagnostics(bool $enabled): void
    {
        if ($this->inlineDiagnostics !== $enabled) {
            $this->inlineDiagnostics = $enabled;
            $this->invalidate();
        }
    }

    public function setRelativeLineNumbers(bool $enabled): void
    {
        if ($this->relativeLineNumbers !== $enabled) {
            $this->relativeLineNumbers = $enabled;
            $this->invalidate();
        }
    }

    public function hasRelativeLineNumbers(): bool
    {
        return $this->relativeLineNumbers;
    }

    public function setAutoPairs(bool $enabled): void
    {
        $this->autoPairs = $enabled;
    }

    public function hasAutoPairs(): bool
    {
        return $this->autoPairs;
    }

    public function onReformat(callable $callback): self
    {
        $this->onReformat = $callback;

        return $this;
    }

    /**
     * Open a single-line input prompt (go to line, rename symbol).
     */
    public function openPrompt(string $label, string $initial, callable $onSubmit): void
    {
        $this->overlayHost->openPrompt($label, $initial, $onSubmit);
    }

    public function setSignatureHint(?string $hint): void
    {
        if ($this->signatureHint !== $hint) {
            $this->signatureHint = $hint;
            $this->invalidate();
        }
    }

    /**
     * @param array<int, list<array{start: int, end: int, current: bool}>> $byLine byte columns
     */
    public function setSearchMatches(array $byLine): void
    {
        $this->searchMatches = $byLine;
        $this->invalidate();
    }

    /**
     * @param array<int, string> $byLine 0-based line => 'added'|'modified'|'deleted'
     */
    public function setGitMarks(array $byLine): void
    {
        $this->gitMarks = $byLine;
        $this->invalidate();
    }

    /**
     * Floating application-menu dropdown spans (in this widget's local coords) to
     * composite over the editor, or [] for none. Set by the coordinator so the
     * menu dropdown floats without reflowing the layout.
     *
     * @param list<array{row: int, col: int, width: int, segment: string}> $spans
     */
    public function setMenuOverlay(array $spans): void
    {
        $this->menuOverlay = $spans;
        $this->invalidate();
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getFilePath(): ?string
    {
        return $this->filePath;
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
        if (null === $this->filePath || !str_ends_with($this->filePath, '.php')) {
            return [];
        }
        $key = spl_object_id($this->document).':'.$this->document->getVersion();
        if ($key !== $this->scopeKey) {
            $this->scopeCache = BreadcrumbScanner::scan($this->document->getText());
            $this->scopeKey = $key;
        }

        return $this->scopeCache;
    }

    public function isVerticallyExpanded(): bool
    {
        return true;
    }

    public function expandVertically(bool $expand): static
    {
        return $this; // the editor always fills its pane
    }

    public function onSaveRequested(callable $callback): self
    {
        $this->onSaveRequested = $callback;

        return $this;
    }

    public function onSaveAllRequested(callable $callback): self
    {
        $this->onSaveAllRequested = $callback;

        return $this;
    }

    public function onChanged(callable $callback): self
    {
        $this->onChanged = $callback;

        return $this;
    }

    public function onCompletionRequested(callable $callback): self
    {
        $this->onCompletionRequested = $callback;

        return $this;
    }

    public function onCompletionResolve(callable $callback): self
    {
        $this->onCompletionResolve = $callback;

        return $this;
    }

    public function onDefinitionRequested(callable $callback): self
    {
        $this->onDefinitionRequested = $callback;

        return $this;
    }

    public function onImplementationRequested(callable $callback): self
    {
        $this->onImplementationRequested = $callback;

        return $this;
    }

    public function onTypeDefinitionRequested(callable $callback): self
    {
        $this->onTypeDefinitionRequested = $callback;

        return $this;
    }

    public function onNavigateBack(callable $callback): self
    {
        $this->onNavigateBack = $callback;

        return $this;
    }

    public function onCopy(callable $callback): self
    {
        $this->onCopy = $callback;

        return $this;
    }

    /**
     * Place the cursor from a click relative to the editor's top-left.
     * $relRow/$relCol are 0-based cells within the editor's rect.
     */
    public function clickAt(int $relRow, int $relCol): void
    {
        $this->mouse->clickAt($relRow, $relCol, $this->viewport());
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

    /** The last-render viewport geometry {@see EditorMouse} needs to map a click cell to a document position. */
    private function viewport(): EditorViewport
    {
        return new EditorViewport($this->scroll->vScroll(), $this->scroll->hScroll(), $this->lastGutterWidth, $this->lastStripeCol, $this->lastRenderRows, $this->stickyLines);
    }

    /** Centre the viewport on a line (the stripe-jump target). */
    private function centerOnLine(int $line): void
    {
        $this->scroll->centerOnLine($line);
    }

    /**
     * Scroll the viewport by $delta rows without moving the cursor line
     * relationship (cursor is re-clamped into view on the next render).
     */
    public function scrollBy(int $delta): void
    {
        $this->scroll->scrollBy($delta);
    }

    /**
     * Move the cursor to an LSP position and scroll it into view.
     */
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

    /** @param array<int, list<array{severity: int, message: string, col: int, endCol?: int}>> $byLine */
    public function setDiagnostics(array $byLine): void
    {
        $this->diagnostics = $byLine;
        $this->invalidate();
    }

    /** @return array{errors: int, warnings: int} */
    public function getDiagnosticCounts(): array
    {
        $errors = $warnings = 0;
        foreach ($this->diagnostics as $list) {
            foreach ($list as $d) {
                1 === $d['severity'] ? ++$errors : ++$warnings;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Diagnostic message on the cursor line, for the status bar.
     */
    public function getCursorDiagnostic(): ?string
    {
        $list = $this->diagnostics[$this->document->getCursorLine()] ?? null;
        if (!$list) {
            return null;
        }

        return $list[0]['message'];
    }

    /**
     * @param list<array{label: string, insertText: string, kind: string, detail: string, additionalTextEdits?: list<array>, snippet?: string|null, raw?: array<string, mixed>}> $items
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

    /**
     * Editor actions that fire a no-argument callback. Shared by handleInput
     * (key dispatch) and runAction (menu dispatch) so both fire identical logic.
     */
    private const array SIMPLE_ACTIONS = [
        'recent_files' => 'onRecentFiles',
        'close_buffer' => 'onCloseBuffer',
        'goto_file' => 'onGotoFile',
        'reformat' => 'onReformat',
        'file_structure' => 'onFileStructure',
        'save_all' => 'onSaveAllRequested',
        'save' => 'onSaveRequested',
        'navigate_back' => 'onNavigateBack',
    ];

    /** Editor actions that fire a callback with the caret (line, utf16 col). */
    private const array POSITIONAL_ACTIONS = [
        'peek_definition' => 'onPeek',
        'code_action' => 'onCodeAction',
        'quick_doc' => 'onQuickDoc',
        'find_usages' => 'onFindUsages',
        'goto_definition' => 'onDefinitionRequested',
        'goto_implementation' => 'onImplementationRequested',
        'goto_type_definition' => 'onTypeDefinitionRequested',
    ];

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }

        // Bracketed paste: buffer chunks between ESC[200~ / ESC[201~
        if (null !== ($pastedText = $this->processBracketedPaste($data))) {
            $this->document->insert($pastedText);
            $this->notifyChange();
            if ('' === $data) {
                return;
            }
        } elseif ('' === $data && $this->isBufferingPaste()) {
            return;
        }

        $kb = $this->getKeybindings();
        $doc = $this->document;

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

        if ($this->completion->isOpen() && $this->completion->handleInput($data, $kb)) {
            return;
        }

        // Editor-level actions that fire an optional callback, dispatched by a
        // lookup table instead of a flat if/return chain. Split by the
        // callback's arity: no arguments, or the caret position (line, col).
        // The same tables drive runAction() so the menu bar fires identical logic.
        foreach (self::SIMPLE_ACTIONS as $action => $callback) {
            if ($kb->matches($data, $action)) {
                $this->fireSimpleAction($action);

                return;
            }
        }

        foreach (self::POSITIONAL_ACTIONS as $action => $callback) {
            if ($kb->matches($data, $action)) {
                $this->firePositionalAction($action);

                return;
            }
        }

        // The remaining editor-level actions carry extra logic beyond firing a
        // single callback.
        if ($kb->matches($data, 'next_buffer') || $kb->matches($data, 'prev_buffer')) {
            if (null !== $this->onBufferCycle) {
                ($this->onBufferCycle)($kb->matches($data, 'next_buffer') ? 1 : -1);
            }

            return;
        }

        if ($kb->matches($data, 'find') || $kb->matches($data, 'replace')) {
            if (null !== $this->onFindRequested) {
                ($this->onFindRequested)($kb->matches($data, 'replace'));
            }

            return;
        }

        $this->actions->handleKey($data, $kb);
    }

    /**
     * Invoke an editor action by its id — the entry point the application menu
     * uses so a menu item fires exactly the same logic as pressing its key.
     * Unknown ids are a safe no-op.
     */
    public function runAction(string $action): void
    {
        if (isset(self::SIMPLE_ACTIONS[$action])) {
            $this->fireSimpleAction($action);

            return;
        }
        if (isset(self::POSITIONAL_ACTIONS[$action])) {
            $this->firePositionalAction($action);

            return;
        }
        $this->actions->run($action);
    }

    private function fireSimpleAction(string $action): void
    {
        $callback = self::SIMPLE_ACTIONS[$action];
        if (null !== $this->{$callback}) {
            ($this->{$callback})();
        }
    }

    private function firePositionalAction(string $action): void
    {
        $callback = self::POSITIONAL_ACTIONS[$action];
        if (null !== $this->{$callback}) {
            ($this->{$callback})($this->document->getCursorLine(), $this->document->getCursorColUtf16());
        }
    }

    /**
     * Route a left-click (in editor-pane-relative coords) at an open popup.
     *
     * The modal list overlay always consumes the click: on an item it picks it,
     * anywhere else it dismisses. The completion popup only consumes a click that
     * lands on one of its rows (so a click elsewhere falls through to caret
     * placement, which closes it). Returns whether the click was consumed.
     */
    public function handlePopupClick(int $relRow, int $relCol): bool
    {
        if ($this->overlayHost->isListOpen()) {
            return $this->overlayHost->clickAt($relRow, $relCol);
        }

        return $this->completion->handleClick($relRow, $relCol);
    }

    /**
     * Highlight the popup row under the pointer (editor-pane-relative coords).
     * Returns whether the highlighted row actually changed (so the caller can
     * skip a redundant re-render on a same-row hover).
     */
    public function handlePopupHover(int $relRow, int $relCol): bool
    {
        if ($this->overlayHost->isListOpen()) {
            return $this->overlayHost->hoverAt($relRow, $relCol);
        }

        return $this->completion->handleHover($relRow, $relCol);
    }

    /** Cancel any active snippet session (e.g. before an external edit). */
    public function clearSnippetSession(): void
    {
        $this->completion->clearSnippet();
    }

    /**
     * Line-comment prefix for the current language, or null when unsupported.
     */
    private function commentPrefix(): ?string
    {
        return match ($this->highlighter->getLanguage()) {
            'php', 'javascript', 'typescript', 'css', 'scss' => '//',
            'bash', 'yaml', 'dotenv', 'ini', 'python', 'dockerfile', 'terraform' => '#',
            'sql' => '--',
            'twig' => null, // block comments only
            default => null,
        };
    }

    private function closeCompletion(): void
    {
        $this->completion->close();
    }

    // --- Multi-cursor -------------------------------------------------------

    public function hasMultipleCarets(): bool
    {
        return $this->multiCaret->hasMultiple();
    }

    /** @return list<array{line: int, col: int, anchorLine: int, anchorCol: int}> secondary carets (for tests/render) */
    public function getExtraCarets(): array
    {
        return $this->multiCaret->carets();
    }

    public function clearExtraCarets(): void
    {
        $this->multiCaret->clear();
    }

    private function notifyChange(): void
    {
        $this->invalidate();
        if (null !== $this->onChanged) {
            ($this->onChanged)($this->document);
        }
    }

    private function hasControlBytes(string $data): bool
    {
        return 1 === preg_match('/[\x00-\x08\x0B-\x1F\x7F]/', $data) || str_contains($data, "\x1b");
    }

    /** True when $data is a printable character safe to insert (not a control key). */
    private function isInsertable(string $data): bool
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
        $top = $this->foldModel->docLineAtRow($vScroll, 0) ?? 0;

        return BreadcrumbScanner::stickyHeaders($this->scopes(), $top, max(0, min(EditorScroll::MAX_STICKY_ROWS, $rows - 4)));
    }

    /**
     * Render one pinned header row: its highlighted source, a dimmed gutter line
     * number, and a distinct band background, over the full editor width.
     *
     * @param array<array-key, string> $highlighted
     */
    private function renderStickyRow(int $docLine, int $numberWidth, int $contentWidth, array $highlighted): string
    {
        $gutter = EditorRenderer::gitCell($this->gitMarks[$docLine] ?? null)."\x1b[38;5;240m".str_pad((string) ($docLine + 1), $numberWidth, ' ', \STR_PAD_LEFT).' '."\x1b[0m ";
        $hl = EditorRenderer::applyIndentGuides($this->document, $highlighted[$docLine] ?? '', $docLine);
        $content = EditorRenderer::renderContentLine($this->scroll->hScroll(), $hl, $contentWidth, false, 0);

        return LineDecorator::applyFullWidth($gutter.$content, $this->lastGutterWidth + $contentWidth, "\x1b[48;5;237m");
    }

    public function render(RenderContext $context): array
    {
        $columns = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : min(30, $this->document->getLineCount()));

        // No file open: show a centered hint instead of a blank editable buffer,
        // still compositing the app-menu dropdown so File/Edit stay reachable.
        if (null === $this->filePath) {
            $this->lastGutterWidth = 0;
            $this->lastStripeCol = \PHP_INT_MAX; // no stripe over the empty-state pane

            return MenuOverlay::apply($this->renderEmptyState($columns, $rows), $this->menuOverlay, $columns);
        }

        $doc = $this->document;
        $lineCount = $doc->getLineCount();
        // gutter = git strip (1) + line number + change marker + trailing space
        $numberWidth = max(3, \strlen((string) $lineCount));
        $gutterWidth = 1 + $numberWidth + 2;
        $this->lastGutterWidth = $gutterWidth;
        // Reserve the rightmost column for the error stripe (whole-file overview);
        // always reserved so diagnostics arriving/leaving never reflow the content.
        $contentWidth = max(1, $columns - $gutterWidth - 1);
        $this->lastRenderRows = $rows;
        $this->lastStripeCol = $gutterWidth + $contentWidth;

        // Clamp scroll to cursor: the caret must stay visible below whatever
        // sticky-scroll band the settled scroll position pins at the top.
        $cursorLine = $doc->getCursorLine();
        $this->scroll->clampToCaret($rows, fn (int $vScroll): int => \count($this->stickyHeadersAt($vScroll, $rows)));
        $vScroll = $this->scroll->vScroll();

        // The pinned headers for the settled scroll position.
        $sticky = $this->stickyHeadersAt($vScroll, $rows);
        $stickyCount = \count($sticky);
        $this->stickyLines = array_map(static fn (array $h): int => $h['line'], $sticky);
        $contentRows = $rows - $stickyCount;
        $this->scroll->setContentRows($contentRows);

        $cursorDisplayCol = $doc->getCursorDisplayCol();
        // Inlay hints inserted before the caret push its visual column right.
        $cursorDisplayCol += EditorRenderer::inlayShiftAt($doc, $cursorLine, $this->inlayHints[$cursorLine] ?? [], $cursorDisplayCol);
        $this->scroll->followCursor($cursorDisplayCol, $contentWidth);
        $hScroll = $this->scroll->hScroll();

        // Highlight/render tab-expanded lines so widths line up with the
        // display-column math; the buffer keeps its raw tabs.
        $highlighted = $this->highlighter->highlight($doc->getVisualLines(), $doc->getVersion());
        $selection = $doc->getSelectionRange();
        // With multiple carets, every caret (primary + extras) is drawn the same
        // way — a reverse-video cell — and the hardware-cursor bar is suppressed.
        $multiActive = $this->focused && $this->multiCaret->hasMultiple();
        $carets = $multiActive ? $this->multiCaret->allCarets() : [];
        $brackets = $this->focused ? EditorRenderer::matchingBrackets($doc) : null;
        $lastVisibleDocLine = $this->foldModel->docLineAtRow($vScroll, $contentRows - 1) ?? ($lineCount - 1);
        $occurrences = EditorRenderer::identifierOccurrences($doc, $this->focused, $vScroll, $lastVisibleDocLine);

        // Right-margin error stripe: one styled cell per screen row, a whole-file
        // overview of diagnostics plus a faint viewport thumb. Appended to every
        // produced row so each reaches the full terminal width.
        $stripe = $this->buildStripe($rows, $lineCount, $vScroll, $lastVisibleDocLine);

        /** @var list<string> $result */
        $result = [];
        // Pinned sticky-scroll headers occupy the top rows; content follows below.
        foreach ($sticky as $i => $header) {
            $result[] = $this->renderStickyRow($header['line'], $numberWidth, $contentWidth, $highlighted).$stripe[$i];
        }
        for ($row = 0; $row < $contentRows; ++$row) {
            $lineIndex = $this->foldModel->docLineAtRow($vScroll, $row);

            if (null === $lineIndex) {
                $result[] = "\x1b[38;5;238m".str_pad('~', $gutterWidth)."\x1b[0m".str_repeat(' ', $contentWidth).$stripe[$stickyCount + $row];
                continue;
            }

            $isCursorLine = $lineIndex === $cursorLine;
            $lineDiags = $this->diagnostics[$lineIndex] ?? [];
            $hasError = [] !== $lineDiags;
            $worstSeverity = $hasError ? DiagnosticStyle::worst($lineDiags) : 4;

            $numColor = match (true) {
                $hasError => "\x1b[38;5;".DiagnosticStyle::fg($worstSeverity).'m',
                $isCursorLine => "\x1b[38;5;252m",
                default => "\x1b[38;5;240m",
            };
            // No icon in the left gutter — the severity reads from the line-number
            // color here and from the colored block in the right stripe.
            $marker = ' ';
            // Relative line numbers: distance from the cursor line, absolute on
            // the cursor line itself (PhpStorm/Vim "relativenumber" style).
            if ($this->relativeLineNumbers && !$isCursorLine) {
                $numberLabel = (string) abs($lineIndex - $cursorLine);
            } else {
                $numberLabel = (string) ($lineIndex + 1);
            }
            $gutter = EditorRenderer::gitCell($this->gitMarks[$lineIndex] ?? null).$numColor.str_pad($numberLabel, $numberWidth, ' ', \STR_PAD_LEFT).$marker."\x1b[0m ";

            $hlLine = $highlighted[$lineIndex] ?? '';
            $hlLine = EditorRenderer::applyIndentGuides($doc, $hlLine, $lineIndex);

            // Gather decoration spans in layering order, then apply them all at
            // once. Each span is [startCol, endCol, openSgr, closeSgr, bgReapply].
            // With multiple carets the primary selection is null: every caret
            // (incl. the primary) draws uniformly through the caret records.
            ['spans' => $spans, 'padTo' => $padTo] = LineSpans::forLine(
                $doc,
                $lineIndex,
                $multiActive ? null : $selection,
                $carets,
                $this->searchMatches[$lineIndex] ?? [],
                $occurrences,
                $brackets,
                $lineDiags,
            );
            if ($padTo > 0) {
                // Pad so an end-of-line caret has a cell to invert (the window
                // bound keeps padding within what's visible).
                $need = min($padTo, $hScroll + $contentWidth);
                $width = AnsiUtils::visibleWidth($hlLine);
                if ($need > $width) {
                    $hlLine .= str_repeat(' ', $need - $width);
                }
            }
            foreach ($spans as [$spanStart, $spanEnd, $openSgr, $closeSgr, $reapply]) {
                $hlLine = LineDecorator::apply($hlLine, $spanStart, $spanEnd, $openSgr, $closeSgr, $reapply);
            }

            // Inlay hints: dim virtual text inserted after all decorations so
            // their SGR spans stay attached to the real characters.
            $hlLine = EditorRenderer::applyInlayHints($doc, $lineIndex, $this->inlayHints[$lineIndex] ?? [], $hlLine);

            // Collapsed-region marker on the fold-start line.
            if (null !== ($foldEnd = $this->foldModel->foldEndAt($lineIndex))) {
                $hidden = $foldEnd - $lineIndex;
                $hlLine .= "\x1b[38;5;238m ⋯ ".$hidden." lines\x1b[0m";
            }

            $content = EditorRenderer::renderContentLine(
                $hScroll,
                $hlLine,
                $contentWidth,
                $isCursorLine && $this->focused && !$multiActive,
                $cursorDisplayCol,
            );

            // Current-line background (skip when a selection is being shown)
            if ($isCursorLine && $this->focused && null === $selection) {
                $content = LineDecorator::applyFullWidth($content, $contentWidth, LineDecorator::CURRENT_LINE_BG);
            }

            // Inline diagnostic message in the trailing space (PhpStorm-style):
            // the worst-severity message on the line, colored by severity, with a
            // "+N" count when the line carries more than one diagnostic.
            if ($this->inlineDiagnostics && $hasError) {
                $content = EditorRenderer::appendInlineDiagnostic($hScroll, $doc->lineDisplayWidth($lineIndex), $lineDiags, $content, $contentWidth);
            }

            $result[] = $gutter.$content.$stripe[$stickyCount + $row];
        }

        if (null !== $this->signatureHint) {
            $cursorScreenRow = max(0, $this->foldModel->screenRowFor($vScroll, $cursorLine)) + $stickyCount;
            EditorRenderer::signatureHint($result, $columns, $gutterWidth, $cursorScreenRow, $this->signatureHint);
        }

        $this->completion->setBox(null);
        if (null !== ($popup = $this->completion->popupState())) {
            $cursorScreenRow = max(0, $this->foldModel->screenRowFor($vScroll, $cursorLine)) + $stickyCount;
            $anchorCol = $gutterWidth + ($cursorDisplayCol - $hScroll) - mb_strwidth($this->document->getWordBeforeCursor());
            $this->completion->setBox(EditorRenderer::completionPopup($result, $columns, $rows, $cursorScreenRow, $anchorCol, $popup['items'], $popup['selected']));
        }

        // The overlay/prompt chrome composites over the content; the
        // application-menu dropdown floats above everything else.
        /** @var list<string> $result */
        return MenuOverlay::apply(
            $this->overlayHost->composite($result, $columns, $rows, $this->focused),
            $this->menuOverlay,
            $columns,
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
        $hint = 'No file open · press Ctrl+P to open a file';
        $text = AnsiUtils::truncateToWidth($hint, $columns, '…');
        $textWidth = AnsiUtils::visibleWidth($text);
        $left = max(0, intdiv($columns - $textWidth, 2));
        $hintRow = str_repeat(' ', $left)."\x1b[38;5;244m".$text."\x1b[39m".str_repeat(' ', max(0, $columns - $left - $textWidth));

        $middle = intdiv($rows, 2);
        $lines = [];
        for ($i = 0; $i < $rows; ++$i) {
            $lines[] = $i === $middle ? $hintRow : str_repeat(' ', $columns);
        }

        return $lines;
    }

    /**
     * Build the right-margin error stripe: one self-contained 1-column cell per
     * screen row. Each cell shows the worst-severity diagnostic mark landing on
     * that proportional slice of the file, over a faint viewport thumb (only when
     * the file is taller than the viewport).
     *
     * @return list<string>
     */
    private function buildStripe(int $rows, int $lineCount, int $vScroll, int $lastVisible): array
    {
        // Worst severity landing on each stripe row (0 = none).
        $sev = array_fill(0, max(0, $rows), 0);
        foreach ($this->diagnostics as $line => $list) {
            if ([] === $list) {
                continue;
            }
            $r = $lineCount <= 1 ? 0 : max(0, min($rows - 1, intdiv($line * $rows, $lineCount)));
            $worst = DiagnosticStyle::worst($list);
            $sev[$r] = 0 === $sev[$r] ? $worst : min($sev[$r], $worst);
        }

        // Viewport thumb: the stripe rows covering the visible doc-line range,
        // shown only when the file actually scrolls.
        $showThumb = $lineCount > $rows;
        $thumbTop = $lineCount <= 1 ? 0 : max(0, min($rows - 1, intdiv($vScroll * $rows, $lineCount)));
        $thumbBottom = $lineCount <= 1 ? 0 : max(0, min($rows - 1, intdiv($lastVisible * $rows, $lineCount)));

        $cells = [];
        for ($r = 0; $r < $rows; ++$r) {
            $bg = $showThumb && $r >= $thumbTop && $r <= $thumbBottom ? "\x1b[48;5;236m" : '';
            $cells[] = 0 !== $sev[$r]
                ? "\x1b[0m".$bg."\x1b[38;5;".DiagnosticStyle::fg($sev[$r]).'m'.DiagnosticStyle::MARK."\x1b[0m"
                : "\x1b[0m".$bg.' '."\x1b[0m";
        }

        return $cells;
    }

    // --- Inlay hints -------------------------------------------------------

    /**
     * Replace the inlay hints (parameter names / inferred types) shown inline.
     *
     * @param array<int, list<array{col: int, text: string}>> $hints line => byte-col hints
     */
    public function setInlayHints(array $hints): void
    {
        $this->inlayHints = $hints;
        $this->invalidate();
    }

    /**
     * PhpStorm-first keymap. Deliberate deviations (terminal constraints):
     * quit stays Ctrl+Q on the app level (PhpStorm quick-doc moves to F1),
     * Ctrl+W stays delete-word, Ctrl+K stays delete-to-line-end.
     *
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'cursor_up' => [Key::UP],
            'cursor_down' => [Key::DOWN],
            'cursor_left' => [Key::LEFT],
            'cursor_right' => [Key::RIGHT],
            'cursor_word_left' => ['ctrl+left', 'alt+b'],
            'cursor_word_right' => ['ctrl+right', 'alt+f'],
            'cursor_line_start' => [Key::HOME],
            'cursor_line_end' => [Key::END],
            'document_start' => ['ctrl+home', 'alt+<'],
            'document_end' => ['ctrl+end', 'alt+>'],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],

            'select_up' => ['shift+up'],
            'select_down' => ['shift+down'],
            'select_left' => ['shift+left'],
            'select_right' => ['shift+right'],
            'select_word_left' => ['ctrl+shift+left'],
            'select_word_right' => ['ctrl+shift+right'],
            'select_line_start' => ['shift+home'],
            'select_line_end' => ['shift+end'],
            'select_all' => ['ctrl+a'],

            'copy' => ['ctrl+c'],
            'cut' => ['ctrl+x'],
            'paste' => ['ctrl+v'],

            'delete_char_backward' => [Key::BACKSPACE, 'shift+backspace'],
            'delete_char_forward' => [Key::DELETE],
            'delete_word_backward' => ['alt+backspace'],
            'delete_to_line_end' => ['ctrl+k'],
            'delete_line' => ['ctrl+y', 'ctrl+shift+k'],
            'duplicate_line' => ['ctrl+d'],
            'move_line_up' => ['alt+shift+up'],
            'move_line_down' => ['alt+shift+down'],
            'move_statement_up' => ['ctrl+shift+up'],
            'move_statement_down' => ['ctrl+shift+down'],
            'expand_selection' => ['ctrl+w'],
            'shrink_selection' => ['ctrl+shift+w'],
            'surround_with' => ['ctrl+alt+t'],
            'next_conflict' => [Key::F7],
            'prev_conflict' => ['shift+f7'],
            'resolve_conflict' => ['alt+m'],
            'toggle_comment' => ['ctrl+/', 'ctrl+-'],
            'toggle_fold' => ['ctrl+.'],

            'new_line' => [Key::ENTER, 'shift+enter'],
            'indent' => [Key::TAB],
            'prev_tab_stop' => ['shift+tab'],
            'accept_completion' => [Key::ENTER],
            'trigger_completion' => ['ctrl+space'],
            'select_cancel' => [Key::ESCAPE],

            'save' => ['ctrl+s'],
            'save_all' => ['ctrl+alt+s'],
            'undo' => ['ctrl+z'],
            'redo' => ['ctrl+shift+z'],
            'goto_definition' => ['ctrl+b', Key::F12],
            'goto_implementation' => ['ctrl+alt+b'],
            'goto_type_definition' => ['ctrl+shift+b'],
            'navigate_back' => ['ctrl+alt+left'],

            'add_caret_above' => ['ctrl+alt+up'],
            'add_caret_below' => ['ctrl+alt+down'],
            'add_next_occurrence' => ['alt+j'],
            'select_all_occurrences' => ['ctrl+alt+shift+j'],

            'next_buffer' => ['alt+right'],
            'prev_buffer' => ['alt+left'],
            'recent_files' => ['ctrl+e'],
            'close_buffer' => ['ctrl+f4', 'alt+w'],

            'find' => ['ctrl+f'],
            'replace' => ['ctrl+r'],
            'goto_file' => ['ctrl+shift+n', 'ctrl+p'],

            'quick_doc' => [Key::F1],
            'code_action' => ['alt+enter'],
            'file_structure' => ['ctrl+f12', 'alt+7'],
            'peek_definition' => ['ctrl+shift+i'],
            'reformat' => ['ctrl+alt+l'],
            'goto_line' => ['ctrl+g'],
            'rename_symbol' => ['shift+f6'],
            'find_usages' => ['alt+f7'],
        ];
    }
}
