<?php

declare(strict_types=1);

namespace Porta\Bootstrap;

use Porta\Container\AsIdeService;
use Porta\Editor\EditorPanes;
use Porta\I18n\Translator;
use Porta\Image\ImageDecoder;
use Porta\Image\SvgConverter;
use Porta\Support\ProjectPaths;
use Porta\Widget\HelpViewerWidget;
use Porta\Widget\ImageViewerWidget;
use Porta\Widget\ViewerTabHandler;

/**
 * Registers the panes core ships — the image viewer and the help browser — into
 * the same
 * registry a plugin's `registerPane()` reaches through
 * {@see \Porta\Editor\PaneRegistrar}.
 *
 * Core registering through the plugin extension point is what keeps the point
 * honest: the registry has three consumers inside `src/` before any plugin
 * depends on it, and when the two viewers move out (their code is all that
 * changes) the registration moves to their `plugin.php` with the loader intact.
 *
 * A loader THROWS to refuse a file — too large to decode, not UTF-8 — and
 * `BufferService` flashes the message it carries. That is the one place a pane
 * gets to say "not this file" after its claim already said "mine".
 */
#[AsIdeService]
final class CorePanes
{
    public function __construct(
        private readonly EditorPanes $panes,
        private readonly ImageViewerWidget $imageViewer,
        private readonly HelpViewerWidget $helpViewer,
        private readonly ImageDecoder $imageDecoder,
        private readonly Translator $translator,
        private readonly ViewerTabHandler $tabs,
        private readonly string $rootDir,
    ) {}

    public function register(): void
    {
        $this->imageViewer->setHandler($this->tabs);
        $this->panes->register($this->imageViewer, $this->loadImage(...));
        $this->panes->register($this->helpViewer, $this->loadHelp(...));
    }

    /**
     * An SVG rides as its SOURCE (the pane rasterizes it per zoom level), a
     * bitmap as decoded pixels. No size guard on the vector branch on purpose:
     * there is no decoder to protect, and the viewer claim's own `maxBytes` IS
     * the limit — re-checking it against a constant would contradict a user who
     * deliberately raised that setting.
     */
    private function loadImage(string $path): mixed
    {
        if (SvgConverter::isSvgPath($path)) {
            return $this->text($path);
        }
        if ((int) filesize($path) > ImageDecoder::MAX_BYTES) {
            throw new \RuntimeException($this->translator->trans('Image too large (> 20 MB)'));
        }

        return $this->imageDecoder->decode($path) ?? throw new \RuntimeException($this->translator->trans('Cannot display image %file%', ['%file%' => ProjectPaths::relative($this->rootDir, $path)]));
    }

    private function loadHelp(string $path): string
    {
        return $this->text($path);
    }

    /** A pane payload that is text: read it, refusing anything that is not UTF-8. */
    private function text(string $path): string
    {
        $content = (string) file_get_contents($path);
        if ('' !== $content && !mb_check_encoding($content, encoding: 'UTF-8')) {
            throw new \RuntimeException($this->translator->trans('Not a UTF-8 text file'));
        }

        return $content;
    }
}
