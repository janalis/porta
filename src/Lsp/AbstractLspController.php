<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\StatusBarWidget;
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
        protected readonly StatusBarWidget $statusBar,
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
     * request (flushing pending edits unless $flush is false), else null.
     */
    protected function ready(bool $flush = true): ?string
    {
        return $this->lsp->ready($this->editor->getFilePath(), $this->editor->getDocument(), $flush);
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
            'textDocument' => ['uri' => $this->uri($path)],
            'position' => ['line' => $line, 'character' => $character],
        ] + $extra;
    }

    /** Flash a transient status message and repaint. */
    protected function flash(string $message): void
    {
        $this->statusBar->flash($message);
        $this->tui->requestRender();
    }
}
