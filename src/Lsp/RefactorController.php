<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorRefactorHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * Mutating language features: rename (validated via prepareRename), quick fixes
 * / code actions, and reformatting. All apply their results through the shared
 * {@see WorkspaceEditor}.
 */
#[AsIdeService]
final class RefactorController extends AbstractLspController implements EditorRefactorHandler
{
    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly BufferManager $buffers,
        private readonly WorkspaceEditor $workspaceEditor,
        private readonly ProjectPaths $paths,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
    }

    /**
     * Validate a rename position with textDocument/prepareRename before opening
     * the rename prompt, pre-filling the server's placeholder when it offers one.
     */
    #[\Override]
    public function requestPrepareRename(int $line, int $col, string $word): void
    {
        $path = $this->editor->getFilePath();
        if (null === $path || !$this->lsp->isInitialized()) {
            $this->flash($this->trans('Rename needs a language server'));

            return;
        }

        $this->lsp->request(
            'textDocument/prepareRename',
            $this->positionParams($path, $line, $col),
            function (mixed $result, ?array $error) use ($line, $col, $word): void {
                if (null !== $error || null === $result || false === $result) {
                    $this->flash($this->trans('Cannot rename this element'));

                    return;
                }
                $this->editor->openPrompt($this->trans('Rename to'), $word, function (string $value) use ($line, $col): void {
                    if ('' !== trim($value)) {
                        $this->requestRename(trim($value), $line, $col);
                    }
                });
                $this->tui->requestRender();
            },
        );
    }

    private function requestRename(string $newName, int $line, int $col): void
    {
        // Only reached after prepareRename validated the position, so the
        // language server and file are known-good here.
        $path = (string) $this->editor->getFilePath();
        $this->lsp->flushPendingChange($this->editor->getFilePath(), $this->editor->getDocument());
        $this->lsp->request(
            'textDocument/rename',
            $this->positionParams($path, $line, $col, ['newName' => $newName]),
            function (mixed $result, ?array $error): void {
                if (null !== $error || !\is_array($result)) {
                    $this->flash($this->trans('Rename failed: %message%', ['%message%' => (string) ($error['message'] ?? 'no result')]));

                    return;
                }
                /** @var array<string, mixed> $result */
                $fileCount = $this->workspaceEditor->applyWorkspaceEdit($result);
                $this->flash($this->trans('Renamed in %count% file|Renamed in %count% files', ['%count%' => $fileCount]));
            },
        );
    }

    #[\Override]
    public function requestCodeActions(int $line, int $col): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        // Use the diagnostic's range when the cursor line carries one, so
        // servers offer their fix-this-problem actions.
        $diagnostics = [];
        $range = [
            'start' => ['line' => $line, 'character' => $col],
            'end' => ['line' => $line, 'character' => $col],
        ];
        foreach ($this->buffers->active()?->diagnostics[$line] ?? [] as $diag) {
            $endCol = $diag['endCol'] ?? \PHP_INT_MAX;
            $endCol = \PHP_INT_MAX === $endCol ? $diag['col'] + 1 : $endCol;
            $lspDiag = [
                'range' => [
                    'start' => ['line' => $line, 'character' => $diag['col']],
                    'end' => ['line' => $line, 'character' => $endCol],
                ],
                'severity' => $diag['severity'],
                'message' => $diag['message'],
            ];
            $diagnostics[] = $lspDiag;
            $range = $lspDiag['range'];
        }

        $this->lsp->request(
            'textDocument/codeAction',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => $range,
                'context' => ['diagnostics' => $diagnostics],
            ],
            function (mixed $result, ?array $error): void {
                if (null !== $error || !\is_array($result) || [] === $result) {
                    $this->flash($this->trans('No quick fixes here'));

                    return;
                }

                /** @var list<array<string, mixed>> $actions */
                $actions = array_values(array_filter($result, static fn ($a) => \is_array($a) && '' !== (string) ($a['title'] ?? '')));
                $items = array_map(
                    static fn (array $a, int $i) => [
                        'label' => (string) $a['title'],
                        'hint' => (string) ($a['kind'] ?? ''),
                        'value' => (string) $i,
                    ],
                    $actions,
                    array_keys($actions),
                );

                $this->editor->openListOverlay($this->trans('Quick Fixes'), $items, function (string $index) use ($actions): void {
                    $this->applyCodeAction($actions[(int) $index] ?? []);
                });
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Apply a CodeAction (edit and/or command) or a bare Command.
     *
     * @param array<string, mixed> $action
     */
    private function applyCodeAction(array $action): void
    {
        $applied = false;
        if (($action['edit'] ?? null) !== null) {
            /** @var array<string, mixed> $edit */
            $edit = $action['edit'];
            $count = $this->workspaceEditor->applyWorkspaceEdit($edit);
            $title = (string) ($action['title'] ?? 'fix');
            $this->flash(
                $this->trans('Applied "%title%" to %count% file|Applied "%title%" to %count% files', ['%title%' => $title, '%count%' => $count]),
            );
            $applied = true;
        }

        // CodeAction.command, or the action IS a bare Command
        /** @var array<string, mixed>|string|null $command */
        $command = $action['command'] ?? null;
        if (\is_string($command)) {
            $command = ['command' => $command, 'arguments' => $action['arguments'] ?? []];
        }
        if (\is_array($command) && ($command['command'] ?? null) !== null) {
            $title = (string) ($action['title'] ?? $command['title'] ?? 'command');
            $this->lsp->request(
                'workspace/executeCommand',
                [
                    'command' => $command['command'],
                    'arguments' => $command['arguments'] ?? [],
                ],
                function (mixed $result, ?array $error) use ($title): void {
                    $this->flash(
                        null !== $error
                            ? $this->trans('"%title%" failed: %message%', ['%title%' => $title, '%message%' => (string) ($error['message'] ?? 'unknown error')])
                            : $this->trans('Ran "%title%"', ['%title%' => $title]),
                    );
                },
            );
            $applied = true;
        }

        if (!$applied) {
            $this->flash($this->trans('Quick fix has nothing to apply'));
        }
        $this->tui->requestRender();
    }

    #[\Override]
    public function requestReformat(): void
    {
        if (null === ($path = $this->ready())) {
            return;
        }

        // Format the selection (rangeFormatting) when one exists, else the file.
        $doc = $this->editor->getDocument();
        $options = ['tabSize' => $doc->usesTabs() ? $doc->tabWidth() : $doc->indentSize(), 'insertSpaces' => !$doc->usesTabs()];
        $selection = $doc->getSelectionRange();
        [$method, $params] = null !== $selection
            ? [
                'textDocument/rangeFormatting',
                [
                    'textDocument' => ['uri' => $this->uri($path)],
                    'range' => [
                        'start' => ['line' => $selection['startLine'], 'character' => $doc->byteColToUtf16($selection['startLine'], $selection['startCol'])],
                        'end' => ['line' => $selection['endLine'], 'character' => $doc->byteColToUtf16($selection['endLine'], $selection['endCol'])],
                    ],
                    'options' => $options,
                ],
            ]
            : [
                'textDocument/formatting',
                [
                    'textDocument' => ['uri' => $this->uri($path)],
                    'options' => $options,
                ],
            ];

        $this->lsp->request($method, $params, function (mixed $result, ?array $error) use ($path): void {
            if (null !== $error || !\is_array($result) || [] === $result) {
                $this->flash($this->trans('Formatting not supported by %server%', ['%server%' => $this->lsp->statusFor($this->editor->getFilePath())]));

                return;
            }

            $buffer = $this->buffers->get($path);
            if (null !== $buffer) {
                /** @var list<array{range: array{start: array{line?: int, character?: int}, end: array{line?: int, character?: int}}, newText: string}> $result */
                $this->workspaceEditor->applyTextEdits($buffer->document, $result);
                if ($buffer === $this->buffers->active()) {
                    $this->editor->invalidate();
                }
                $this->lsp->notifyFullChange($buffer->path, $buffer->document);
                $this->flash($this->trans('Reformatted %file%', ['%file%' => $this->paths->relative($path)]));
            }
            $this->tui->requestRender();
        });
    }
}
