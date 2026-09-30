<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\DiagnosticMessageMode;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Ui\Glyphs;
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
    /** Which codeAction request the list on screen belongs to. */
    private int $actionGeneration = 0;

    /**
     * The actions merged so far for {@see $actionGeneration}.
     *
     * @var list<array<string, mixed>>
     */
    private array $actions = [];

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
                    $this->notifier->error($this->trans('Cannot rename this element'));

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
                    $this->notifier->error($this->trans('Rename failed: %message%', ['%message%' => (string) ($error['message'] ?? 'no result')]));

                    return;
                }
                /** @var array<string, mixed> $result */
                $outcome = $this->workspaceEditor->applyWorkspaceEdit($result);
                $this->flash($this->report($outcome, $this->trans('Renamed in %count% file|Renamed in %count% files', ['%count%' => $outcome->touched])));
            },
        );
    }

    /**
     * Alt+Enter. With diagnostic messages shown in this menu, the caret line's
     * problems head the list, above what can be done about them — PhpStorm's
     * shape — and a line with problems but no fix (a tool plugin's
     * diagnostics, or no server up yet) still opens the list to read them.
     */
    #[\Override]
    public function requestCodeActions(int $line, int $col): void
    {
        $problems = $this->problemRows($line);
        if ([] !== $problems && null === $this->ready()) {
            $this->pick($this->trans('Quick Fixes'), [], $problems);

            return;
        }
        $this->requestActions(
            $line,
            $col,
            only: null,
            present: function (array $actions) use ($problems): void {
                $this->pick($this->trans('Quick Fixes'), $actions, $problems);
            },
            empty: [] === $problems
                ? 'No quick fixes here'
                : function () use ($problems): void {
                    $this->pick($this->trans('Quick Fixes'), [], $problems);
                },
        );
    }

    /**
     * The caret line's diagnostics as rows to read, worst first — only while
     * the messages are shown in this menu. Notes: the list never selects or
     * runs a row that is there to be read, and draws it plain rather than
     * struck through like a disabled action.
     *
     * @return list<array{label: string, hint: string, value: string, note: true}>
     */
    private function problemRows(int $line): array
    {
        if (DiagnosticMessageMode::Menu !== $this->editor->display->diagnosticMessages) {
            return [];
        }
        $diagnostics = $this->buffers->active()?->diagnostics[$line] ?? [];
        \usort($diagnostics, static fn (array $a, array $b): int => $a['severity']->value <=> $b['severity']->value);

        return \array_map(
            static fn (array $d): array => [
                'label' => $d['severity']->glyph().' '.\trim((string) \preg_replace('/\s+/u', replacement: ' ', subject: $d['message'])),
                'hint' => (string) ($d['source'] ?? $d['code'] ?? ''),
                'value' => '',
                'note' => true,
            ],
            $diagnostics,
        );
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
     * @param string|\Closure(): void $empty what to flash when the answer is nothing, or what to do instead
     */
    private function requestActions(int $line, int $col, ?array $only, \Closure $present, string|\Closure $empty = 'No quick fixes here'): void
    {
        if (null === ($path = $this->readyOrExplain())) {
            return;
        }

        $diagnostics = $this->diagnosticsAt($line);
        $context = ['diagnostics' => $diagnostics, 'triggerKind' => self::TRIGGER_INVOKED];
        if (null !== $only) {
            $context['only'] = $only;
        }

        $generation = ++$this->actionGeneration;
        $this->actions = [];
        $this->lsp->requestAll(
            'textDocument/codeAction',
            [
                'textDocument' => ['uri' => $this->uri($path)],
                'range' => $this->actionRange($line, $col, $diagnostics),
                'context' => $context,
            ],
            'codeActionProvider',
            function (mixed $result, ?array $error, string $owner) use ($present, $empty, $generation): void {
                if ($generation !== $this->actionGeneration) {
                    return; // a later request is what the user is waiting on
                }
                /** @var list<array<string, mixed>> $offered */
                $offered = null === $error && \is_array($result)
                    ? \array_values(\array_filter($result, static fn ($a): bool => \is_array($a) && '' !== (string) ($a['title'] ?? '')))
                    : [];
                foreach (ServerOwner::stampAll($offered, $owner) as $action) {
                    $this->actions[] = $action;
                }
                // Said on EVERY answer, not only the last: a server with
                // nothing to offer must not leave the screen silent while
                // another is still thinking, and must not overwrite what that
                // other one has already put up.
                if ([] === $this->actions) {
                    \is_string($empty) ? $this->flash($this->trans($empty)) : $empty();

                    return;
                }
                $present($this->preferredFirst($this->actions));
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
                'severity' => $diag['severity']->value,
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
     * @param list<array<string, mixed>>                                                $actions
     * @param list<array{label: string, hint: string, value: string, note: true}>     $problems rows to read above the actions
     */
    private function pick(string $title, array $actions, array $problems = []): void
    {
        // The server's own pick leads with ★. An action it cannot run here
        // comes with its reason, which stands in the hint's place, and it is
        // greyed rather than left out: knowing why is worth a row.
        $items = \array_map(
            static fn (array $a, int $i): array => [
                'label' => (true === ($a['isPreferred'] ?? null) ? Glyphs::PREFERRED.' ' : '').(string) $a['title'],
                'hint' => \is_array($a['disabled'] ?? null) ? (string) ($a['disabled']['reason'] ?? '') : self::kindLabel((string) ($a['kind'] ?? '')),
                'value' => (string) $i,
                'disabled' => \is_array($a['disabled'] ?? null),
            ],
            $actions,
            \array_keys($actions),
        );

        // Each action's value is its index in $actions, not its row: the
        // problem rows above are never picked, so they need no value.
        $this->editor->openListOverlay($title, [...$problems, ...$items], function (string $index) use ($actions): void {
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
        if (($action['edit'] ?? null) !== null || ($action['command'] ?? null) !== null || !$this->supportsResolve($action)) {
            $this->applyCodeAction($action);

            return;
        }

        [$owner, $bare] = ServerOwner::take($action);
        $this->lsp->requestTo($owner, 'codeAction/resolve', $bare, function (mixed $result, ?array $error) use ($action): void {
            // Falling back to the unresolved action is deliberate: it flashes
            // "nothing to apply", which is the truth when a resolve fails.
            /** @var array<string, mixed> $resolved */
            $resolved = null === $error && \is_array($result) ? $result : $action;
            $this->applyCodeAction($resolved);
        });
    }

    /**
     * Whether the server that OFFERED $action resolves.
     *
     * Its own capability, not a merged one: one server resolving says nothing
     * about another, and the point of the gate is to stop sending a resolve
     * to a server that can only answer an error.
     *
     * @param array<string, mixed> $action
     */
    private function supportsResolve(array $action): bool
    {
        [$owner] = ServerOwner::take($action);
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a CodeActionOptions object */
        $capability = $this->lsp->capabilityOf($owner, 'codeActionProvider');

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
            /** @var array<string, mixed> $owned */
            $owned = $action;
            [$owner] = ServerOwner::take($owned);
            // The command id belongs to the server that offered it; another
            // would at best answer "unknown command".
            $this->lsp->requestTo(
                $owner,
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
        $options = $doc->formattingOptions();
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
                    $this->notifier->warn($this->trans('The formatter sent overlapping edits; nothing changed'));

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
