<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\Document;
use Porta\Widget\EditorCompletionHandler;

/**
 * "As you type" language features: completion, its deferred resolve
 * (auto-imports), inlay hints, signature help, and the debounced didChange that
 * re-requests inlay hints once the server has the new text.
 */
final class CompletionController extends AbstractLspController implements EditorCompletionHandler
{
    /** Debounce a didChange for $doc, then refresh inlay hints. */
    public function scheduleDidChange(Document $doc): void
    {
        $this->lsp->scheduleDidChange(
            $doc,
            fn () => $this->editor->getFilePath(),
            $this->requestInlayHints(...),
        );
    }

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
     * Fetch inlay hints (parameter names / inferred types) for the whole
     * document and hand them to the editor to render as dim inline text.
     */
    public function requestInlayHints(): void
    {
        $path = (string) $this->editor->getFilePath();
        $lineCount = $this->editor->getDocument()->getLineCount();
        $this->lsp->request(
            'textDocument/inlayHint',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => [
                    'start' => ['line' => 0, 'character' => 0],
                    'end' => ['line' => max(0, $lineCount - 1), 'character' => 0],
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
     * Fetch a completion item's deferred `additionalTextEdits` (auto-imports)
     * via completionItem/resolve and apply them to the buffer the completion
     * came from, without moving the caret. Both phpactor and intelephense
     * defer `use` insertions to resolve rather than the initial response.
     *
     * @param array<string, mixed> $item the raw LSP CompletionItem
     */
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
