<?php

declare(strict_types=1);

namespace Porta\Lsp;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorDecorationSink;
use Porta\Widget\CodeEditorWidget;
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
final readonly class CodeLensController
{
    public function __construct(
        private LspSessionPool $lsp,
        private CodeEditorWidget $editor,
        private EditorDecorationSink $decorations,
        private Tui $tui,
    ) {}

    public function requestCodeLenses(): void
    {
        $path = $this->editor->getFilePath();
        /** @var array<string, mixed>|bool|null $capability LSP: boolean or a CodeLensOptions object */
        $capability = $this->lsp->serverCapability($path, 'codeLensProvider');
        if (null === $path || null === $capability || false === $capability) {
            return;
        }

        $this->lsp->request(
            'textDocument/codeLens',
            ['textDocument' => ['uri' => Uri::fromPath($path)]],
            function (mixed $result) use ($path): void {
                // A stale answer after a buffer switch belongs to a file that is
                // no longer on screen; painting it would label the wrong code.
                if (!\is_array($result) || $path !== $this->editor->getFilePath()) {
                    return;
                }
                /** @var list<array<string, mixed>> $result */
                $this->decorations->setCodeLenses(self::byLine($result));
                $this->tui->requestRender();
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
     * @return array<int, list<string>>
     */
    private static function byLine(array $lenses): array
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
            $byLine[$line][] = $title;
        }

        return $byLine;
    }
}
