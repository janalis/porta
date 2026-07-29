<?php

declare(strict_types=1);

namespace Porta\Graph;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Notifier;

/**
 * Palette verbs on the ACTIVE graph tab (any .dot tab, not just the
 * architecture artifact): export the document as SVG/PNG beside itself via
 * the dot binary.
 */
#[AsIdeService]
final class GraphTabActions
{
    public function __construct(
        private readonly BufferManager $buffers,
        private readonly LayoutEngine $engine,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    public function exportActive(GraphExportFormat $format): void
    {
        $active = $this->buffers->active();
        if (null === $active || !$active->isGraph()) {
            return; // the command's `when:` gate keeps keys away; the palette handler self-guards
        }
        $outPath = \sprintf('%s/%s.%s', \dirname($active->path), pathinfo($active->path, \PATHINFO_FILENAME), $format->extension());
        $this->engine->export((string) $active->graphDot, $format, $outPath, function (?string $error) use ($outPath): void {
            if (null !== $error) {
                $this->notifier->error($this->translator->trans('Export failed: %error%', ['%error%' => $error]));

                return;
            }
            $this->notifier->success($this->translator->trans('Exported %file%', ['%file%' => ProjectPaths::relative($this->rootDir, $outPath)]));
        });
    }
}
