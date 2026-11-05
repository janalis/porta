<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Event\ReferencesFollowRequested;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Notifier;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Finder\Glob;

/**
 * When a file moves in the tree, asks its language server what follows —
 * `workspace/willRenameFiles` — and applies the answer as one refactoring:
 * the moved class's namespace, every `use` of it. Undo Refactoring takes it
 * back.
 *
 * Asked only of a server that registered for such a file, and AFTER the move,
 * where the protocol says before. The tree has moved the file by the time it
 * says so, and phpactor — the one PHP server that offers this — reads the
 * file at its NEW path to work out its new name: asked before, it fails.
 * phpantom offers nothing here, so with it a move changes only the file's place.
 */
final readonly class FileMoveFollower
{
    public function __construct(
        private LspSessionPool $lsp,
        private WorkspaceEditor $workspaceEditor,
        private Notifier $notifier,
        private Translator $translator,
        private ProjectPaths $paths,
    ) {}

    #[AsEventListener]
    public function onReferencesFollowRequested(ReferencesFollowRequested $event): void
    {
        if (!\is_file($event->to) || !$this->registered($event->to)) {
            return;
        }
        $this->lsp->requestFor(
            $event->to,
            'workspace/willRenameFiles',
            ['files' => [['oldUri' => Uri::fromPath($event->from), 'newUri' => Uri::fromPath($event->to)]]],
            function (mixed $result, ?array $error) use ($event): void {
                if (null !== $error) {
                    $this->notifier->warn($this->translator->trans('%file% moved; its references were not updated: %message%', [
                        '%file%' => \basename($event->to),
                        '%message%' => (string) ($error['message'] ?? ''),
                    ]));

                    return;
                }
                if (!\is_array($result) || [] === $result) {
                    return; // nothing names it, or nothing needed to change
                }
                /** @var array<string, mixed> $result */
                $outcome = $this->workspaceEditor->applyWorkspaceEdit($result);
                $this->notifier->flash(
                    $outcome->isRefused()
                        ? $this->translator->trans('Refused: %reason% — nothing was changed', ['%reason%' => (string) $outcome->refused])
                        : $this->translator->trans('Updated %count% file for the move — Undo Last Refactoring takes it back|Updated %count% files for the move — Undo Last Refactoring takes it back', [
                            '%count%' => $outcome->touched,
                        ]),
                );
            },
        );
    }

    /** Whether the server for $path asked to hear of a file like it moving — of a FILE: a folder filter does not count. */
    private function registered(string $path): bool
    {
        /** @var array{fileOperations?: array{willRename?: array{filters?: list<array{pattern: array{glob: string, matches?: string}}>}}}|null $workspace LSP: WorkspaceServerCapabilities */
        $workspace = $this->lsp->serverCapability($path, 'workspace');
        $relative = $this->paths->relative($path);

        return \array_any(
            $workspace['fileOperations']['willRename']['filters'] ?? [],
            static fn (array $filter): bool => (
                'folder' !== ($filter['pattern']['matches'] ?? null)
                && 1 === \preg_match(
                    Glob::toRegex($filter['pattern']['glob'], strictLeadingDot: false, strictWildcardSlash: true, delimiter: '#'),
                    \str_starts_with($filter['pattern']['glob'], '/') ? $path : $relative,
                )
            ),
        );
    }
}
