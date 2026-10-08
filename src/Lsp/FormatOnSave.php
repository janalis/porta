<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Editor\Buffer;
use Porta\Editor\EditorInvalidator;
use Porta\I18n\Translator;
use Porta\Support\RequestRound;
use Porta\Support\Scheduler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Formats the active buffer through the language server before a save. The
 * LSP round-trip is async, so the save itself becomes the continuation: the
 * response applies the edits (only if the document did not change underneath
 * the request), then saves; a timeout saves anyway and drops the late
 * response; a re-entrant save supersedes the pending round and starts its
 * own — whichever path wins, each call's continuation runs at most once and
 * Ctrl+S can never hang on a dead server.
 *
 * Rejected shapes: format-after-save-then-resave double-dispatches BufferSaved
 * (LSP didSave, local history, git refresh, plugin hooks) and can loop with a
 * non-idempotent formatter; willSaveWaitUntil needs a client capability we
 * don't advertise and has spotty server support.
 */
final class FormatOnSave
{
    private bool $enabled = false;

    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly EditorInvalidator $editor,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
        private readonly RequestRound $round = new RequestRound(),
    ) {}

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** @param \Closure(): void $save the actual write; runs exactly once per call unless superseded by a newer save */
    public function formatThenSave(Buffer $buffer, \Closure $save): void
    {
        // ready() flushes the debounced didChange so the server formats the
        // current text; no ready server → plain save.
        $path = $this->lsp->ready($buffer->path, $buffer->document);
        if (null === $path) {
            $save();

            return;
        }

        $token = $this->round->start();
        $doc = $buffer->document;
        $version = $doc->version;
        $options = $doc->formattingOptions();

        // Ctrl+S must never hang: after 2 s the save proceeds unformatted and
        // the late response is dropped — with a flash, so a skipped format is
        // distinguishable from an already-clean file.
        $timer = $this->scheduler->delay(2.0, function () use ($token, $save): void {
            if (!$this->round->supersede($token)) {
                return;
            }
            $this->notifier->warn($this->translator->trans('Formatting timed out — saved unformatted'));
            $save();
        });

        $this->lsp->request(
            'textDocument/formatting',
            ['textDocument' => Uri::textDocument($path), 'options' => $options],
            function (mixed $result, ?array $error) use ($token, $timer, $buffer, $version, $save): void {
                if (!$this->round->supersede($token)) {
                    return; // superseded by the timeout or a newer save
                }
                $this->scheduler->cancel($timer);
                // Edits computed against stale text must never apply — the
                // version guard skips them and the save keeps the user's text.
                if (null === $error && \is_array($result) && [] !== $result && $buffer->document->version === $version) {
                    /** @var list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $result */
                    // A refusal (overlapping edits) leaves the buffer exactly as
                    // the user wrote it and the save below still happens — which
                    // is the whole contract of format-on-save: the save is the
                    // point, the formatting is the courtesy.
                    if (WorkspaceEditor::applyTextEdits($buffer->document, $result)) {
                        $this->lsp->notifyFullChange($buffer->path, $buffer->document);
                        $this->editor->invalidate();
                        $this->tui->requestRender();
                    }
                }
                $save();
            },
        );
    }
}
