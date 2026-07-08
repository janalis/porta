<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Config\EditorConfig\ResolvedEditorConfig;
use Porta\Config\LanguageIndent;
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
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Support\ProjectPaths;
use Porta\Ui\Style;
use Porta\Widget\Overlay\OverlayHost;
use Porta\Widget\Render\EditorDecorations;
use Porta\Widget\Render\EditorGeometry;
use Porta\Widget\Render\EditorPopupGeometry;
use Porta\Widget\Render\EditorRenderer;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\LineSpans;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
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
final class CodeEditorWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost, VerticallyExpandableInterface
{
    use BracketedPasteTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use MenuOverlayHostTrait;
    use InputHook;
    use KeybindingsTrait;

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

    /** @var array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null memoized bracket pair, keyed by version + caret */
    private ?array $bracketsMemo = null;
    private string $bracketsKey = '';

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

    /** Outbound hooks into the coordinator, replaced wholesale by wiring/tests. */
    private EditorCallbacks $callbacks;

    /** @var array<int, list<array{start: int, end: int, current: bool}>> search matches by line (byte cols) */
    private array $searchMatches = [];

    /** @var array<int, string> 0-based line => 'added'|'modified'|'deleted' */
    private array $gitMarks = [];

    /** @var array{int, int}|null caret (line, col) at the last render, to gate hScroll following */
    private ?array $lastFollowedCursor = null;

    /** One-line signature hint shown above the cursor, cleared on movement. */
    private ?string $signatureHint = null;

    /** Editing-verb dispatcher (movement/clipboard/line ops), rebuilt per document. */
    private EditorActions $actions;

    /** Modal list-overlay + prompt chrome composited over the editor pane. */
    private readonly OverlayHost $overlayHost;

    public function __construct()
    {
        $this->callbacks = new EditorCallbacks();
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
            fn (int $l, int $c) => null !== $this->callbacks->onCompletionRequested ? ($this->callbacks->onCompletionRequested)($l, $c) : null,
            fn (array $raw) => null !== $this->callbacks->onCompletionResolve ? ($this->callbacks->onCompletionResolve)($raw) : null,
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
            fn (string $text) => null !== $this->callbacks->onCopy ? ($this->callbacks->onCopy)($text) : null,
            fn (int $line, int $col) => null !== $this->callbacks->onSignatureHelp ? ($this->callbacks->onSignatureHelp)($line, $col) : null,
            function (int $line, int $col, string $word): void {
                if (null !== $this->callbacks->onRenameRequested) {
                    // The IDE validates via prepareRename, then opens the prompt.
                    ($this->callbacks->onRenameRequested)($line, $col, $word);
                } elseif (null !== $this->callbacks->onRename) {
                    $this->openPrompt('Rename to', $word, function (string $value) use ($line, $col): void {
                        if ('' !== trim($value)) {
                            /** @var callable(string, int, int): void $this->callbacks->onRename */
                            ($this->callbacks->onRename)(trim($value), $line, $col);
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
     * @param ResolvedEditorConfig|null                                                       $editorConfig the file's resolved
     *                                                                                                      `.editorconfig`, for the 'auto' indent branch
     */
    public function showDocument(string $path, Document $document, array $diagnostics = [], ?CodeHighlighter $highlighter = null, ?ResolvedEditorConfig $editorConfig = null): void
    {
        $this->filePath = '' !== $path ? $path : null;
        $this->document = $document;
        $this->currentEditorConfig = $editorConfig;
        $this->applyEffectiveIndent();
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

    /**
     * Set the per-language indent config (languageId => {style, size}); re-applies
     * to the current document immediately and to every document shown afterwards
     * (buffer switches re-apply via {@see showDocument}).
     *
     * @param array<string, LanguageIndent> $indents
     */
    public function setLanguageIndents(array $indents): void
    {
        $this->languageIndents = $indents;
        $this->applyEffectiveIndent();
        $this->invalidate();
    }

    /**
     * Apply the effective indent for the current file's language: an explicit
     * per-language style/size wins; 'auto' takes the file's `.editorconfig`
     * value, falling back to unix-style 2 spaces. A file whose language has no
     * entry in the map (e.g. plaintext, or a bare editor with no config) keeps
     * the document's own auto-detected/`.editorconfig` indent.
     */
    private function applyEffectiveIndent(): void
    {
        $config = $this->languageIndents[ProjectPaths::languageId($this->filePath ?? '')] ?? null;
        if (null === $config) {
            return;
        }

        $usesTabs = match ($config->indentStyle) {
            'spaces' => false,
            'tabs' => true,
            default => $this->currentEditorConfig?->usesTabs ?? false,
        };
        $size = 'auto' === $config->indentSize
            ? $this->currentEditorConfig?->indentSize ?? 2
            : (int) $config->indentSize;

        $this->document->setIndent($usesTabs, $size);
    }

    public function setAutoPairs(bool $enabled): void
    {
        $this->autoPairs = $enabled;
    }

    public function hasAutoPairs(): bool
    {
        return $this->autoPairs;
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

    /** Install the coordinator's outbound hooks (see {@see EditorCallbacks}). */
    public function setCallbacks(EditorCallbacks $callbacks): void
    {
        $this->callbacks = $callbacks;
    }

    public function getDocument(): Document
    {
        return $this->document;
    }

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
            if (null !== $this->callbacks->onBufferCycle) {
                ($this->callbacks->onBufferCycle)($kb->matches($data, 'next_buffer') ? 1 : -1);
            }

            return;
        }

        if ($kb->matches($data, 'find') || $kb->matches($data, 'replace')) {
            if (null !== $this->callbacks->onFindRequested) {
                ($this->callbacks->onFindRequested)($kb->matches($data, 'replace'));
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
        $callback = $this->callbacks->{self::SIMPLE_ACTIONS[$action]};
        if (null !== $callback) {
            $callback();
        }
    }

    private function firePositionalAction(string $action): void
    {
        $callback = $this->callbacks->{self::POSITIONAL_ACTIONS[$action]};
        if (null !== $callback) {
            $callback($this->document->getCursorLine(), $this->document->getCursorColUtf16());
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
        if (null !== $this->callbacks->onChanged) {
            ($this->callbacks->onChanged)($this->document);
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

        return BreadcrumbScanner::stickyHeaders($this->scopes(), $top, IntMath::clamp($rows - 4, 0, EditorScroll::MAX_STICKY_ROWS));
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
     * The gutter cell for one document line: git strip, line number colored by
     * the line's worst diagnostic (or highlighted on the caret line), marker.
     *
     * @param list<array{col: int, endCol?: int, message: string, severity: int}> $lineDiags
     */
    private function gutterCell(int $lineIndex, bool $isCursorLine, array $lineDiags, int $numberWidth): string
    {
        $numColor = match (true) {
            [] !== $lineDiags => "\x1b[38;5;".DiagnosticStyle::fg(DiagnosticStyle::worst($lineDiags)).'m',
            $isCursorLine => "\x1b[38;5;252m",
            default => "\x1b[38;5;240m",
        };
        // No icon in the left gutter — the severity reads from the line-number
        // color here and from the colored block in the right stripe.
        $marker = ' ';
        // Relative line numbers: distance from the cursor line, absolute on
        // the cursor line itself (PhpStorm/Vim "relativenumber" style).
        if ($this->relativeLineNumbers && !$isCursorLine) {
            $numberLabel = (string) abs($lineIndex - $this->document->getCursorLine());
        } else {
            $numberLabel = (string) ($lineIndex + 1);
        }

        return EditorRenderer::gitCell($this->gitMarks[$lineIndex] ?? null).$numColor.str_pad($numberLabel, $numberWidth, ' ', \STR_PAD_LEFT).$marker."\x1b[0m ";
    }

    /**
     * One document line highlighted and fully decorated: indent guides, the
     * layered selection/caret/search/occurrence/bracket/diagnostic spans,
     * inlay hints, and the collapsed-fold marker.
     *
     * @param array{startLine: int, startCol: int, endLine: int, endCol: int}|null $selection null with multiple carets: every caret draws uniformly through the records
     * @param list<array{line: int, col: int, anchorLine: int, anchorCol: int}>    $carets
     * @param list<array{0: int, 1: int, 2: int}>                                  $occurrences
     * @param array{0: array{0: int, 1: int}, 1: array{0: int, 1: int}}|null       $brackets
     * @param list<array{col: int, endCol?: int, message: string, severity: int}>  $lineDiags
     */
    private function decoratedLine(
        int $lineIndex,
        string $highlighted,
        ?array $selection,
        array $carets,
        array $occurrences,
        ?array $brackets,
        array $lineDiags,
        int $hScroll,
        int $contentWidth,
    ): string {
        $doc = $this->document;
        $hlLine = EditorRenderer::applyIndentGuides($doc, $highlighted, $lineIndex);

        // Gather decoration spans in layering order, then apply them all at
        // once. Each span is [startCol, endCol, openSgr, closeSgr, bgReapply].
        ['spans' => $spans, 'padTo' => $padTo] = LineSpans::forLine(
            $doc,
            $lineIndex,
            $selection,
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

        return $hlLine;
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
        if (null !== $this->signatureHint) {
            $cursorScreenRow = max(0, $this->foldModel->screenRowFor($geo->vScroll, $geo->cursorLine)) + $geo->stickyCount;
            EditorRenderer::signatureHint($result, $geo->columns, $geo->gutterWidth, $cursorScreenRow, $this->signatureHint);
        }

        $this->completion->setBox(null);
        if (null !== ($popup = $this->completion->popupState())) {
            $cursorScreenRow = max(0, $this->foldModel->screenRowFor($geo->vScroll, $geo->cursorLine)) + $geo->stickyCount;
            $anchorCol = $geo->gutterWidth + ($geo->cursorDisplayCol - $geo->hScroll) - mb_strwidth($this->document->getWordBeforeCursor());
            $this->completion->setBox(EditorRenderer::completionPopup($result, $geo->columns, $geo->rows, $cursorScreenRow, $anchorCol, $popup['items'], $popup['selected']));
        }

        /** @var list<string> $result signatureHint() rewrites rows in place, keeping the shape */
        return $result;
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

            return $this->withMenuOverlay($this->renderEmptyState($columns, $rows), $columns);
        }

        $doc = $this->document;
        $lineCount = $doc->getLineCount();
        $geo = $this->computeGeometry($columns, $lineCount);
        $this->lastGutterWidth = $geo->gutterWidth;
        $this->lastRenderRows = $rows;
        $this->lastStripeCol = $geo->stripeCol;

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

        // Right-margin error stripe: one styled cell per screen row, a whole-file
        // overview of diagnostics plus a faint viewport thumb. Appended to every
        // produced row so each reaches the full terminal width.
        $stripe = $this->buildStripe($rows, $lineCount, $vScroll, $lastVisibleDocLine);

        $result = $this->assembleRows($geo, $decorations, $sticky, $highlighted, $stripe, $contentRows, $vScroll, $cursorLine, $cursorDisplayCol, $hScroll);

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

        // The overlay/prompt chrome composites over the content; the
        // application-menu dropdown floats above everything else.
        return $this->withMenuOverlay($this->overlayHost->composite($result, $columns, $rows, $this->focused), $columns);
    }

    /**
     * The horizontal layout for a $lineCount-line document in $columns cells:
     * gutter = git strip (1) + line number + change marker + trailing space, and
     * the rightmost column always reserved for the error stripe so diagnostics
     * arriving or leaving never reflow the content.
     */
    private function computeGeometry(int $columns, int $lineCount): EditorGeometry
    {
        $numberWidth = max(3, \strlen((string) $lineCount));
        $gutterWidth = 1 + $numberWidth + 2;
        $contentWidth = max(1, $columns - $gutterWidth - 1);

        return new EditorGeometry($numberWidth, $gutterWidth, $contentWidth, $gutterWidth + $contentWidth);
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
        $cursorDisplayCol += EditorRenderer::inlayShiftAt($doc, $cursorLine, $this->inlayHints[$cursorLine] ?? [], $cursorDisplayCol);
        $cursorMoved = [$cursorLine, $doc->getCursorCol()] !== $this->lastFollowedCursor;
        $this->lastFollowedCursor = [$cursorLine, $doc->getCursorCol()];
        $this->scroll->followCursor($cursorDisplayCol, $contentWidth, $cursorMoved);

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

        return new EditorDecorations(
            multiActive: $multiActive,
            selection: $doc->getSelectionRange(),
            carets: $multiActive ? $this->multiCaret->allCarets() : [],
            occurrences: EditorRenderer::identifierOccurrences($doc, $this->focused, $vScroll, $lastVisibleDocLine),
            brackets: $this->focused ? $this->matchingBrackets($doc) : null,
        );
    }

    /**
     * Assemble the frame's rows: the pinned sticky-scroll headers on top, then
     * one content row per visible screen line (a "~" filler past end-of-file),
     * each fully decorated and closed with its error-stripe cell.
     *
     * @param list<array{label: string, line: int}> $sticky
     * @param array<array-key, string>               $highlighted
     * @param list<string>                           $stripe
     *
     * @return list<string>
     */
    private function assembleRows(
        EditorGeometry $geo,
        EditorDecorations $decorations,
        array $sticky,
        array $highlighted,
        array $stripe,
        int $contentRows,
        int $vScroll,
        int $cursorLine,
        int $cursorDisplayCol,
        int $hScroll,
    ): array {
        $doc = $this->document;
        $stickyCount = \count($sticky);

        /** @var list<string> $result */
        $result = [];
        // Pinned sticky-scroll headers occupy the top rows; content follows below.
        foreach ($sticky as $i => $header) {
            $result[] = $this->renderStickyRow($header['line'], $geo->numberWidth, $geo->contentWidth, $highlighted).$stripe[$i];
        }
        for ($row = 0; $row < $contentRows; ++$row) {
            $lineIndex = $this->foldModel->docLineAtRow($vScroll, $row);

            if (null === $lineIndex) {
                $result[] = "\x1b[38;5;238m".str_pad('~', $geo->gutterWidth)."\x1b[0m".str_repeat(' ', $geo->contentWidth).$stripe[$stickyCount + $row];
                continue;
            }

            $isCursorLine = $lineIndex === $cursorLine;
            $lineDiags = $this->diagnostics[$lineIndex] ?? [];

            $gutter = $this->gutterCell($lineIndex, $isCursorLine, $lineDiags, $geo->numberWidth);
            $hlLine = $this->decoratedLine(
                $lineIndex,
                $highlighted[$lineIndex] ?? '',
                $decorations->multiActive ? null : $decorations->selection,
                $decorations->carets,
                $decorations->occurrences,
                $decorations->brackets,
                $lineDiags,
                $hScroll,
                $geo->contentWidth,
            );

            $content = EditorRenderer::renderContentLine(
                $hScroll,
                $hlLine,
                $geo->contentWidth,
                $isCursorLine && $this->focused && !$decorations->multiActive,
                $cursorDisplayCol,
            );

            // Current-line background (skip when a selection is being shown)
            if ($isCursorLine && $this->focused && null === $decorations->selection) {
                $content = LineDecorator::applyFullWidth($content, $geo->contentWidth, Style::POPUP_ROW_BG);
            }

            // Inline diagnostic message in the trailing space (PhpStorm-style):
            // the worst-severity message on the line, colored by severity, with a
            // "+N" count when the line carries more than one diagnostic.
            if ($this->inlineDiagnostics && [] !== $lineDiags) {
                $content = EditorRenderer::appendInlineDiagnostic($hScroll, $doc->lineDisplayWidth($lineIndex), $lineDiags, $content, $geo->contentWidth);
            }

            $result[] = $gutter.$content.$stripe[$stickyCount + $row];
        }

        return $result;
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
        $hintRow = AnsiText::padRight(str_repeat(' ', $left)."\x1b[38;5;244m".$text."\x1b[39m", $columns);

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
            $r = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($line * $rows, $lineCount), 0, $rows - 1);
            $worst = DiagnosticStyle::worst($list);
            $sev[$r] = 0 === $sev[$r] ? $worst : min($sev[$r], $worst);
        }

        // Viewport thumb: the stripe rows covering the visible doc-line range,
        // shown only when the file actually scrolls.
        $showThumb = $lineCount > $rows;
        $thumbTop = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($vScroll * $rows, $lineCount), 0, $rows - 1);
        $thumbBottom = $lineCount <= 1 ? 0 : IntMath::clamp(intdiv($lastVisible * $rows, $lineCount), 0, $rows - 1);

        $cells = [];
        for ($r = 0; $r < $rows; ++$r) {
            $bg = $showThumb && $r >= $thumbTop && $r <= $thumbBottom ? Style::POPUP_ROW_BG : '';
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

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return EditorKeymap::defaults();
    }
}
