<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsEager;
use Porta\Container\AsIdeService;
use Porta\Editor\OccurrenceHighlights;
use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Ui\FrameHooks;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * PhpStorm's identifier highlight, done semantically: `documentHighlight` says
 * which occurrences of the symbol under the caret are READS and which are
 * WRITES, where the editor's own fallback scan can only match text.
 *
 * Three things keep it cheap. The caret must SETTLE — a held arrow key crosses
 * a dozen symbols and asks about none of them; a request is skipped while the
 * answer already on hand is for this same document version and caret word; and
 * a server that never advertised the capability is never asked.
 *
 * It watches the caret from the per-frame hook rather than from an editor
 * callback, because every way the caret moves — a key, a click, a menu jump, a
 * navigation result — ends in a frame, and only one of them ends in
 * `handleInput`.
 */
// Eager: nothing injects this controller, and its constructor is what installs
// the per-frame caret watch. Without the tag it is simply never built, and the
// whole feature is silently dead — which is exactly what happened until a boot
// probe showed the hook missing.
#[AsEager]
#[AsIdeService]
final class HighlightController extends AbstractLspController
{
    /** How long the caret must hold still before the symbol under it is looked up. */
    private const float CARET_SETTLE_SECONDS = 0.15;

    /** Pending settle timer; null when none is armed. */
    private ?string $settleTimer = null;

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly OccurrenceHighlights $occurrences,
        private readonly ?Scheduler $scheduler = null,
        ?FrameHooks $frames = null,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
        $frames?->onBeforeFrame($this->caretMoved(...));
    }

    /**
     * Called before every frame: cheap and idempotent, per the hook's contract.
     * Only a caret that actually moved re-arms the settle timer — re-arming on
     * every frame would let it fire never.
     */
    public function caretMoved(): void
    {
        $document = $this->editor->getDocument();
        if (!$this->occurrences->caretMovedTo($document->getVersion(), $document->getCursorLine(), $document->getCursorCol())) {
            return;
        }
        if (null === $this->scheduler) {
            $this->requestHighlights(); // bare construction (tests); the container always injects one

            return;
        }
        if (null !== $this->settleTimer) {
            $this->scheduler->cancel($this->settleTimer);
        }
        $this->settleTimer = $this->scheduler->delay(self::CARET_SETTLE_SECONDS, function (): void {
            $this->settleTimer = null;
            $this->requestHighlights();
        });
    }

    public function requestHighlights(): void
    {
        $path = $this->editor->getFilePath();
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a DocumentHighlightOptions object */
        $capability = $this->lsp->serverCapability($path, 'documentHighlightProvider');
        if (null === $path || null === $capability || false === $capability) {
            return;
        }
        $document = $this->editor->getDocument();
        $line = $document->getCursorLine();
        $version = $document->getVersion();
        $column = $document->getCursorColUtf16();
        if ($this->occurrences->isFresh($path, $version, $line, $column)) {
            return; // the caret is parked on a symbol we already have the answer for
        }
        // For the flush, not the answer: pending edits must reach the server
        // before it is asked, or the ranges come back against stale text. The
        // path cannot be null here — a server only advertises a capability once
        // its session is initialized, which is also what makes ready() answer.
        $this->ready();

        $this->lsp->request(
            'textDocument/documentHighlight',
            $this->positionParams($path, $line, $column),
            function (mixed $result, ?array $error) use ($path, $version, $line, $column): void {
                if (null !== $error || !\is_array($result)) {
                    return;
                }
                /** @var list<array<string, mixed>> $result */
                $this->occurrences->publish($path, $version, $line, $column, LspMapper::mapDocumentHighlights($this->editor->getDocument(), $result));
                $this->editor->invalidate();
                $this->tui->requestRender();
            },
        );
    }
}
