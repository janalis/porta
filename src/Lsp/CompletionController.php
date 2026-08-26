<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCompletionHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * "As you type" language features: completion, its deferred resolve
 * (auto-imports), inlay hints, signature help, and the debounced didChange that
 * re-requests inlay hints once the server has the new text.
 */
final class CompletionController extends AbstractLspController implements EditorCompletionHandler
{
    /**
     * Lines fetched beyond the viewport on each side. Generous: a file under
     * ~1000 lines is always fully hinted, and the settle re-fetch below only
     * fires when a scroll actually leaves the window.
     */
    private const int HINT_MARGIN_LINES = 500;

    /** How long the viewport must hold still before hints re-fetch. */
    private const float SCROLL_SETTLE_SECONDS = 0.25;

    /** @var array{string, int, int}|null path + line range of the last hint request */
    private ?array $hintedRange = null;

    /** Pending settle timer; null when none is armed. */
    private ?string $settleTimer = null;

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly ?Scheduler $scheduler = null,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
    }

    /** Debounce a didChange for $doc, then refresh inlay hints. */
    #[\Override]
    public function scheduleDidChange(Document $doc): void
    {
        $this->lsp->scheduleDidChange(
            $doc,
            fn () => $this->editor->getFilePath(),
            $this->requestInlayHints(...),
        );
    }

    #[\Override]
    public function requestCompletion(int $line, int $utf16Col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/completion',
            $this->positionParams($path, $line, $utf16Col),
            function (mixed $result, ?array $error): void {
                if (null !== $error || null === $result) {
                    return;
                }
                /** @var list<array<string, mixed>> $items */
                $items = $result['items'] ?? (\is_array($result) && array_is_list($result) ? $result : []);
                $this->editor->showCompletions(LspMapper::mapCompletionItems($items));
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Fetch inlay hints (parameter names / inferred types) for the viewport
     * (± {@see self::HINT_MARGIN_LINES}) and hand them to the editor to render
     * as dim inline text. Servers that never advertised `inlayHintProvider`
     * are not asked — the old unconditional request earned an error response
     * per edit burst.
     */
    public function requestInlayHints(): void
    {
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or an InlayHintOptions object */
        $capability = $this->lsp->serverCapability($this->editor->getFilePath(), 'inlayHintProvider');
        if (null === $capability || false === $capability) {
            return;
        }
        $path = (string) $this->editor->getFilePath();
        $lastLine = max(0, $this->editor->getDocument()->getLineCount() - 1);
        $viewport = $this->editor->viewport();
        $first = max(0, min($viewport->vScroll - self::HINT_MARGIN_LINES, $lastLine));
        $last = min($lastLine, $viewport->vScroll + $viewport->renderRows + self::HINT_MARGIN_LINES);
        $this->hintedRange = [$path, $first, $last];
        $this->lsp->request(
            'textDocument/inlayHint',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => [
                    'start' => ['line' => $first, 'character' => 0],
                    'end' => ['line' => $last, 'character' => 0],
                ],
            ],
            function (mixed $result) use ($path): void {
                // Ignore errors (result is null) and stale responses after a switch.
                if (\is_array($result) && $path === $this->editor->getFilePath()) {
                    /** @var list<array<string, mixed>> $result */
                    $this->editor->setInlayHints(LspMapper::mapInlayHints($this->editor->getDocument(), $result));
                    $this->tui->requestRender();
                }
            },
        );
    }

    /**
     * A detach-scroll moved the viewport: once it settles, re-fetch hints if
     * the visible lines left the last requested window. (No scheduler = bare
     * test construction: act immediately, the container always injects one.)
     */
    #[\Override]
    public function viewportScrolled(): void
    {
        if (null === $this->scheduler) {
            $this->refetchHintsIfViewportLeftRange();

            return;
        }
        if (null !== $this->settleTimer) {
            $this->scheduler->cancel($this->settleTimer);
        }
        $this->settleTimer = $this->scheduler->delay(self::SCROLL_SETTLE_SECONDS, function (): void {
            $this->settleTimer = null;
            $this->refetchHintsIfViewportLeftRange();
        });
    }

    private function refetchHintsIfViewportLeftRange(): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path) {
            return;
        }
        $viewport = $this->editor->viewport();
        $lastVisible = min(max(0, $this->editor->getDocument()->getLineCount() - 1), $viewport->vScroll + $viewport->renderRows);
        $range = $this->hintedRange;
        if (null !== $range && $range[0] === $path && $range[1] <= $viewport->vScroll && $lastVisible <= $range[2]) {
            return; // still inside the hinted window — nothing new to fetch
        }
        $this->requestInlayHints();
    }

    /**
     * Fetch a completion item's deferred `additionalTextEdits` (auto-imports)
     * via completionItem/resolve and apply them to the buffer the completion
     * came from, without moving the caret. Both phpactor and intelephense
     * defer `use` insertions to resolve rather than the initial response.
     *
     * @param array<string, mixed> $item the raw LSP CompletionItem
     */
    #[\Override]
    public function resolveCompletion(array $item): void
    {
        $path = (string) $this->editor->getFilePath();
        $document = $this->editor->getDocument();
        $this->lsp->request('completionItem/resolve', $item, function (mixed $result) use ($path, $document): void {
            /** @var list<array{range: array{start: array{line: int, character: int}, end: array{line: int, character: int}}, newText: string}> $edits */
            $edits = \is_array($result) ? $result['additionalTextEdits'] ?? [] : [];
            if ([] === $edits) {
                return;
            }
            // Imports may insert lines above an active snippet; drop the session
            // rather than let its tab stops drift.
            $this->editor->clearSnippetSession();
            $document->applyEditsKeepingCursor($edits);
            $this->editor->invalidate();
            $this->lsp->notifyFullChange($path, $document);
            $this->tui->requestRender();
        });
    }

    #[\Override]
    public function requestSignatureHelp(int $line, int $col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        $this->lsp->request(
            'textDocument/signatureHelp',
            $this->positionParams($path, $line, $col),
            function (mixed $result, ?array $error): void {
                /** @var array{signatures?: list<array<string, mixed>>, activeSignature?: int} $result */
                $signature = $result['signatures'][$result['activeSignature'] ?? 0] ?? $result['signatures'][0] ?? null;
                $label = \is_array($signature) ? (string) ($signature['label'] ?? '') : '';
                $this->editor->setSignatureHint('' !== $label ? $label : null);
                $this->tui->requestRender();
            },
        );
    }
}
