<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\I18n\Translator;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorCodeLensHandler;
use Porta\Workbench\Notifier;
use Symfony\Component\Tui\Tui;

/**
 * PhpStorm's code vision: the dim "3 usages" line a server offers above a
 * declaration, via `textDocument/codeLens`.
 *
 * Rejected once as too deep, on the grounds that a lens is a VIRTUAL ROW and
 * the row assembler mapped screen rows to document lines one for one. Soft wrap
 * gave it segments, so a lens row is one more thing a line can occupy — see
 * `docs/internals/decisions.md`.
 *
 * **Gated on the capability**, like every other optional request here: phpantom
 * advertises `codeLensProvider`, phpactor does not, and asking a server that
 * never offered it earns an error per edit burst.
 *
 * Lenses are taken as they arrive and NOT resolved. phpantom advertises
 * `resolveProvider: false`, so a lens comes complete; one that arrives without
 * a title has nothing to show and is dropped rather than rendered blank.
 */
#[AsIdeService]
// Not readonly any more: merging several servers' answers means holding the
// ones already in hand while the rest arrive.
final class CodeLensController implements EditorCodeLensHandler
{
    public function __construct(
        private readonly LspSessionPool $lsp,
        private readonly CodeEditorWidget $editor,
        private readonly EditorDecorationSink $decorations,
        private readonly Tui $tui,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
    ) {}

    /**
     * Which codeLens request the gutter is currently showing — several servers
     * answer one refresh, and an answer to the request before last describes
     * a file the user may have left.
     */
    private int $generation = 0;

    /**
     * The lenses merged so far for {@see $generation}.
     *
     * @var array<int, list<array{title: string, command: string, arguments: list<mixed>, owner: string}>>
     */
    private array $lenses = [];

    public function requestCodeLenses(): void
    {
        $path = $this->editor->getFilePath();
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a CodeLensOptions object */
        $capability = $this->lsp->serverCapability($path, 'codeLensProvider');
        if (null === $path || null === $capability || false === $capability) {
            return;
        }

        $generation = ++$this->generation;
        $this->lenses = [];
        // Every server that lenses is asked, and the gutter grows as they
        // answer: a Symfony server's route lens sits above the same method a
        // PHP server counts usages on.
        $this->lsp->requestAll(
            'textDocument/codeLens',
            ['textDocument' => ['uri' => Uri::fromPath($path)]],
            'codeLensProvider',
            function (mixed $result, ?array $error, string $owner) use ($path, $generation): void {
                // A stale answer after a buffer switch belongs to a file that is
                // no longer on screen; painting it would label the wrong code.
                if (!\is_array($result) || $path !== $this->editor->getFilePath() || $generation !== $this->generation) {
                    return;
                }
                /** @var list<array<string, mixed>> $lensesFromServer */
                $lensesFromServer = \array_values($result);
                foreach (self::byLine($lensesFromServer, $owner) as $line => $lenses) {
                    foreach ($lenses as $lens) {
                        $this->lenses[$line][] = $lens;
                    }
                }
                \ksort($this->lenses);
                $this->decorations->setCodeLenses($this->lenses);
                $this->tui->requestRender();
            },
        );
    }

    /**
     * Run the lens the user clicked. A lens with no verb is informational —
     * "3 usages" reads the same either way — so clicking it says so rather
     * than failing silently or pretending something ran.
     */
    #[\Override]
    public function runCodeLens(int $line, int $index): void
    {
        $lens = $this->editor->decorations->codeLensesAt($line)[$index] ?? null;
        if (null === $lens || '' === $lens['command']) {
            $this->notifier->flash($this->translator->trans('This lens has nothing to run'));

            return;
        }

        $title = $lens['title'];
        // To the server whose command id this is. Another server would at best
        // answer "unknown command" and at worst run something of its own that
        // happens to share the name.
        $this->lsp->requestTo(
            $lens['owner'],
            'workspace/executeCommand',
            ['command' => $lens['command'], 'arguments' => $lens['arguments']],
            function (mixed $result, ?array $error) use ($title): void {
                $this->notifier->flash(
                    null !== $error
                        ? $this->translator->trans('"%title%" failed: %message%', ['%title%' => $title, '%message%' => (string) ($error['message'] ?? 'unknown error')])
                        : $this->translator->trans('Ran "%title%"', ['%title%' => $title]),
                );
                // A command usually edits: ask the server for fresh lenses.
                $this->requestCodeLenses();
            },
        );
    }

    /**
     * Group lenses by the line they sit above, keeping server order within a
     * line. A lens with no title has nothing to show; a lens with no range has
     * no line to show it on.
     *
     * @param list<array<string, mixed>> $lenses
     *
     * @return array<int, list<array{title: string, command: string, arguments: list<mixed>, owner: string}>>
     */
    private static function byLine(array $lenses, string $owner): array
    {
        $byLine = [];
        foreach ($lenses as $lens) {
            /** @var array<string, mixed> $command */
            $command = \is_array($lens['command'] ?? null) ? $lens['command'] : [];
            /** @var array<string, mixed> $start */
            $start = \is_array($lens['range']['start'] ?? null) ? $lens['range']['start'] : [];
            $title = \is_string($command['title'] ?? null) ? $command['title'] : '';
            $line = \is_int($start['line'] ?? null) ? $start['line'] : -1;
            if ('' === $title || $line < 0) {
                continue;
            }
            $arguments = \is_array($command['arguments'] ?? null) ? \array_values($command['arguments']) : [];
            // A lens with no command is still worth showing — "3 usages" reads
            // the same whether or not it can be clicked — so an empty verb is
            // kept and refused at click time rather than dropping the row.
            $byLine[$line][] = [
                'title' => $title,
                'command' => \is_string($command['command'] ?? null) ? $command['command'] : '',
                'arguments' => $arguments,
                'owner' => $owner,
            ];
        }

        return $byLine;
    }
}
