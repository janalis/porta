<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Shared plumbing for the LSP feature controllers: the transport/editor/tui
 * collaborators plus the readiness guard, URI conversion and status-flash idiom
 * that every language-feature request repeats.
 */
abstract class AbstractLspController
{
    public function __construct(
        protected readonly LspSessionPool $lsp,
        protected readonly CodeEditorWidget $editor,
        protected readonly Tui $tui,
        protected readonly Notifier $notifier,
        protected readonly Translator $translator,
    ) {}

    /**
     * Translate a user-facing message for the active UI locale.
     *
     * @param array<string, string|int> $params
     */
    protected function trans(string $id, array $params = []): string
    {
        return $this->translator->trans($id, $params);
    }

    /**
     * The active file's path when an initialized server is ready for a feature
     * request (flushing pending edits unless {@see FlushMode::Keep}), else null.
     */
    protected function ready(FlushMode $flush = FlushMode::Flush): ?string
    {
        return $this->lsp->ready($this->editor->getFilePath(), $this->editor->getDocument(), $flush);
    }

    /**
     * {@see ready()}, but it SAYS SO when there is no server to ask.
     *
     * Every user-invoked verb answered a missing server with a bare `return;`.
     * Open a Markdown, JSON or JavaScript buffer — or any file at all before a
     * PHP server finishes starting — and Go to Declaration, Find Usages, Quick
     * Documentation, Peek, File Structure, Quick Fixes, Refactor This, Inline,
     * Optimize Imports and Reformat each did nothing whatsoever, with no
     * message. The status bar's `LSP:no server` was the only clue, and it is
     * not where anyone is looking after pressing F12.
     *
     * `requestPrepareRename()` was the single verb that already flashed, and
     * this is its wording generalised. Naming the CAUSE rather than the verb is
     * deliberate: these sit in shared request helpers behind four verbs each,
     * and "no server for this file" is the fact the user needs either way. It
     * also stays true while a server is still starting, which "there is no
     * language server" would not be.
     *
     * NOT for the automatic paths: completion fires on every `->` and `::`, and
     * signature help on every `(` and `,`. A flash there would fire on
     * keystrokes in any buffer without a server, which is worse than silence —
     * {@see \Porta\Tests\Unit\Lsp\LspRefusalTest} holds the allowlist and
     * fails when a new silent site appears outside it.
     */
    protected function readyOrExplain(FlushMode $flush = FlushMode::Flush): ?string
    {
        $path = $this->ready($flush);
        if (null === $path) {
            $this->flash($this->trans('No language server ready for this file'));
        }

        return $path;
    }

    protected function uri(string $path): string
    {
        return Uri::fromPath($path);
    }

    /**
     * Standard TextDocumentPositionParams for a feature request, with any
     * request-specific extra members (context, newName, …) merged in.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    protected function positionParams(string $path, int $line, int $character, array $extra = []): array
    {
        return [
            'textDocument' => Uri::textDocument($path),
            'position' => ['line' => $line, 'character' => $character],
        ] + $extra;
    }

    /** Flash a transient status message and repaint. */
    protected function flash(string $message): void
    {
        $this->notifier->flash($message);
    }
}
