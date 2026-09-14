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
    /** CodeActionTriggerKind.Invoked — the user asked, rather than the editor polling as they type. */
    private const int TRIGGER_INVOKED = 1;

    public function __construct(
        LspSessionPool $lsp,
        CodeEditorWidget $editor,
        Tui $tui,
        Notifier $notifier,
        Translator $translator,
        private readonly BufferManager $buffers,
        private readonly WorkspaceEditor $workspaceEditor,
        private readonly RefactoringUndo $undo,
        private readonly ProjectPaths $paths,
    ) {
        parent::__construct($lsp, $editor, $tui, $notifier, $translator);
    }

    /**
     * Take back the last multi-file refactoring, whole.
     *
     * A separate verb from `Ctrl+Z` because an undo stack is per DOCUMENT, and
     * a rename across twenty files was twenty undos in the buffers that were
     * open and nothing for the files that were not. Making `Ctrl+Z` reach other
     * files would be a bigger lie than a second key.
     *
     * Refuses rather than guesses: if anything it would touch has moved on, it
     * names the file and does nothing, because undoing a buffer typed into
     * since would take back the typing instead.
     */
    public function undoLastRefactoring(): void
    {
        if (!$this->undo->hasPending()) {
            $this->flash($this->trans('No refactoring to undo'));

            return;
        }

        // No requestRender() after the flash: Notifier::notify() ends with one,
        // and adding a second is this repo's own hard rule. The mutation run is
        // what caught it — the extra call could be deleted with nothing
        // noticing, which is exactly what "already requests a render" means.
        $blocker = $this->undo->undo();
        $this->flash(
            null === $blocker
                ? $this->trans('Refactoring undone')
                : $this->trans('%file% changed since; nothing was undone', ['%file%' => $this->paths->relative($blocker)]),
        );
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
                    if ('' !== \trim($value)) {
                        $this->requestRename(\trim($value), $line, $col);
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
                $outcome = $this->workspaceEditor->applyWorkspaceEdit($result);
                $this->flash($this->report($outcome, $this->trans('Renamed in %count% file|Renamed in %count% files', ['%count%' => $outcome->touched])));
            },
        );
    }

    #[\Override]
    public function requestCodeActions(int $line, int $col): void
    {
        $this->requestActions($line, $col, only: null, present: function (array $actions): void {
            $this->pick($this->trans('Quick Fixes'), $actions);
        });
    }

    /**
     * PhpStorm's "Refactor This": the same request narrowed to `refactor` kinds,
     * so the list is what you can restructure rather than everything the server
     * can say about this position.
     */
    #[\Override]
    public function requestRefactorings(int $line, int $col): void
    {
        $this->requestActions($line, $col, only: ['refactor'], present: function (array $actions): void {
            $this->pick($this->trans('Refactor This'), $actions);
        });
    }

    /**
     * Inline is the one refactoring servers name precisely enough to run without
     * asking which one you meant, so a single answer applies straight away —
     * `refactor.extract` stays a picker because it covers variable, constant and
     * method behind one kind.
     */
    #[\Override]
    public function requestInline(int $line, int $col): void
    {
        $this->requestActions($line, $col, only: ['refactor.inline'], present: function (array $actions): void {
            if (1 === \count($actions)) {
                $this->applyOrResolve($actions[0]);

                return;
            }
            $this->pick($this->trans('Inline'), $actions);
        });
    }

    /**
     * PhpStorm's Ctrl+Alt+O. `source.organizeImports` has been in the client
     * capabilities since the refactoring round, which is a promise a server
     * reads and answers — this is the verb that finally asks.
     *
     * Applied straight away when the server offers exactly one, like inline:
     * organising imports is a whole-file operation, not a choice between
     * alternatives, so a picker with one row would be a question with one
     * answer. The empty case gets its own words, because "no quick fixes here"
     * would be answering a question nobody asked.
     */
    public function requestOrganizeImports(int $line, int $col): void
    {
        $this->requestActions(
            $line,
            $col,
            only: ['source.organizeImports'],
            present: function (array $actions): void {
                if (1 === \count($actions)) {
                    $this->applyOrResolve($actions[0]);

                    return;
                }
                $this->pick($this->trans('Organize Imports'), $actions);
            },
            empty: 'Nothing to organize',
        );
    }

    /**
     * One code-action request. `$only` narrows it to a kind hierarchy; `$present`
     * decides what to do with what comes back, which is the difference between
     * the three entry points above.
     *
     * @param list<string>|null                  $only
     * @param \Closure(list<array<string, mixed>>): void $present
     * @param string                             $empty what to flash when the answer is nothing
     */
    private function requestActions(int $line, int $col, ?array $only, \Closure $present, string $empty = 'No quick fixes here'): void
    {
        if (null === ($path = $this->readyOrExplain())) {
            return;
        }

        $diagnostics = $this->diagnosticsAt($line);
        $context = ['diagnostics' => $diagnostics, 'triggerKind' => self::TRIGGER_INVOKED];
        if (null !== $only) {
            $context['only'] = $only;
        }

        $this->lsp->request(
            'textDocument/codeAction',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => $this->actionRange($line, $col, $diagnostics),
                'context' => $context,
            ],
            function (mixed $result, ?array $error) use ($present, $empty): void {
                if (null !== $error || !\is_array($result) || [] === $result) {
                    $this->flash($this->trans($empty));

                    return;
                }
                /** @var list<array<string, mixed>> $actions */
                $actions = \array_values(\array_filter($result, static fn ($a): bool => \is_array($a) && '' !== (string) ($a['title'] ?? '')));
                if ([] === $actions) {
                    $this->flash($this->trans($empty));

                    return;
                }
                $present($this->preferredFirst($actions));
                $this->tui->requestRender();
            },
        );
    }

    /**
     * What the request is ABOUT. A selection wins outright — `refactor.extract`
     * needs a real range and a caret point can never produce one, which is why
     * extract could not be offered at all before. Otherwise a diagnostic on the
     * line, so fix-this-problem actions still match, and otherwise the caret.
     *
     * @param list<array<string, mixed>> $diagnostics
     *
     * @return array{start: array{line: int, character: int}, end: array{line: int, character: int}}
     */
    private function actionRange(int $line, int $col, array $diagnostics): array
    {
        $doc = $this->editor->getDocument();
        $selection = $doc->getSelectionRange();
        if (null !== $selection) {
            return [
                'start' => ['line' => $selection['startLine'], 'character' => $doc->byteColToUtf16($selection['startLine'], $selection['startCol'])],
                'end' => ['line' => $selection['endLine'], 'character' => $doc->byteColToUtf16($selection['endLine'], $selection['endCol'])],
            ];
        }
        if ([] !== $diagnostics) {
            /** @var array{start: array{line: int, character: int}, end: array{line: int, character: int}} */
            return $diagnostics[\count($diagnostics) - 1]['range'];
        }

        return [
            'start' => ['line' => $line, 'character' => $col],
            'end' => ['line' => $line, 'character' => $col],
        ];
    }

    /**
     * The caret line's diagnostics in LSP shape. `code`, `source`, `data` and
     * `tags` ride along when the server gave them: a server that matches its own
     * fix by code sees nothing to match without them, and the context is meant
     * to hand back the diagnostic it published, not a summary of it.
     *
     * @return list<array<string, mixed>>
     */
    private function diagnosticsAt(int $line): array
    {
        $diagnostics = [];
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
            foreach (['code', 'source', 'data', 'tags'] as $key) {
                /** @var mixed $extra */
                $extra = $diag[$key] ?? null;
                if (null !== $extra) {
                    $lspDiag[$key] = $extra;
                }
            }
            $diagnostics[] = $lspDiag;
        }

        return $diagnostics;
    }

    /**
     * The server's preferred action first, everything else in the order it sent.
     * `usort` is stable, so this only lifts the preferred one.
     *
     * @param list<array<string, mixed>> $actions
     *
     * @return list<array<string, mixed>>
     */
    private function preferredFirst(array $actions): array
    {
        \usort($actions, static fn (array $a, array $b): int => (true === ($b['isPreferred'] ?? null) ? 1 : 0) <=> (true === ($a['isPreferred'] ?? null) ? 1 : 0));

        return $actions;
    }

    /**
     * @param list<array<string, mixed>> $actions
     */
    private function pick(string $title, array $actions): void
    {
        $items = \array_map(
            static fn (array $a, int $i): array => [
                'label' => (string) $a['title'],
                'hint' => self::kindLabel((string) ($a['kind'] ?? '')),
                'value' => (string) $i,
            ],
            $actions,
            \array_keys($actions),
        );

        $this->editor->openListOverlay($title, $items, function (string $index) use ($actions): void {
            $this->applyOrResolve($actions[(int) $index] ?? []);
        });
    }

    /**
     * The dim right-hand column. The raw LSP kind is a dotted identifier
     * (`refactor.extract`, `quickfix.import_class`) — the family is the part
     * worth reading, so only that is shown.
     */
    private static function kindLabel(string $kind): string
    {
        $family = \strstr($kind, needle: '.', before_needle: true);

        return false === $family ? $kind : $family;
    }

    /**
     * A server may answer with the action's title and defer its edit
     * ({@see resolveProvider}); phpantom does exactly that for extract and
     * inline. Applying such an action without resolving it does nothing at all,
     * which is what "has nothing to apply" used to mean.
     *
     * @param array<string, mixed> $action
     */
    private function applyOrResolve(array $action): void
    {
        if (($action['edit'] ?? null) !== null || ($action['command'] ?? null) !== null || !$this->supportsResolve()) {
            $this->applyCodeAction($action);

            return;
        }

        $this->lsp->request('codeAction/resolve', $action, function (mixed $result, ?array $error) use ($action): void {
            // Falling back to the unresolved action is deliberate: it flashes
            // "nothing to apply", which is the truth when a resolve fails.
            /** @var array<string, mixed> $resolved */
            $resolved = null === $error && \is_array($result) ? $result : $action;
            $this->applyCodeAction($resolved);
        });
    }

    private function supportsResolve(): bool
    {
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a CodeActionOptions object */
        $capability = $this->lsp->serverCapability($this->editor->getFilePath(), 'codeActionProvider');

        return \is_array($capability) && true === ($capability['resolveProvider'] ?? null);
    }

    /**
     * What to tell the user about an applied edit: the caller's own success
     * wording when it all landed, and otherwise the truth. A refusal and a
     * partial write are both things the count alone used to hide.
     */
    private function report(WorkspaceEditOutcome $outcome, string $success): string
    {
        if ($outcome->isRefused()) {
            return $this->trans('Refused: %reason% — nothing was changed', ['%reason%' => (string) $outcome->refused]);
        }
        if ([] === $outcome->skipped) {
            return $success;
        }

        return $this->trans(
            'Applied to %count% file, %skipped% could not be written|Applied to %count% files, %skipped% could not be written',
            ['%count%' => $outcome->touched, '%skipped%' => \count($outcome->skipped)],
        );
    }

    private function applyCodeAction(array $action): void
    {
        $applied = false;
        if (($action['edit'] ?? null) !== null) {
            /** @var array<string, mixed> $edit */
            $edit = $action['edit'];
            $outcome = $this->workspaceEditor->applyWorkspaceEdit($edit);
            $title = (string) ($action['title'] ?? 'fix');
            $this->flash($this->report(
                $outcome,
                $this->trans('Applied "%title%" to %count% file|Applied "%title%" to %count% files', ['%title%' => $title, '%count%' => $outcome->touched]),
            ));
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
        if (null === ($path = $this->readyOrExplain())) {
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
                if (!$this->workspaceEditor->applyTextEdits($buffer->document, $result)) {
                    // Overlapping edits: the file is untouched, and saying so
                    // beats "Reformatted" over a file that is not.
                    $this->flash($this->trans('The formatter sent overlapping edits; nothing changed'));

                    return;
                }
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
