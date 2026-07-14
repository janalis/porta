<?php

declare(strict_types=1);

namespace Porta\Image;

use Porta\Editor\BufferNavigator;
use Porta\Widget\ImageViewerHandler;
use Porta\Widget\StatusBarWidget;
use Symfony\Component\Tui\Tui;

/** The IDE-side implementation of the image viewer's hooks. */
final class ImageViewerActions implements ImageViewerHandler
{
    public function __construct(
        private readonly BufferNavigator $navigator,
        private readonly StatusBarWidget $statusBar,
        private readonly Tui $tui,
    ) {}

    public function cycleBuffer(int $offset): void
    {
        $this->navigator->cycle($offset);
    }

    public function closeBuffer(): void
    {
        $this->navigator->closeActive();
    }

    public function openGotoFile(): void
    {
        $this->navigator->openGoToFile();
    }

    public function saveAll(): void
    {
        $this->navigator->saveAll();
    }

    public function viewChanged(): void
    {
        $this->statusBar->invalidate();
        $this->tui->requestRender();
    }
}
