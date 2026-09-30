<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use PHPUnit\Framework\Assert;
use Porta\Editor\BufferManager;
use Porta\Editor\BufferService;
use Porta\Editor\PreviewMode;
use Porta\Ide;

/**
 * A previewed file (an SVG, a graph) opens as TEXT beside its preview now. A
 * test about the PANE — its keys, its zoom, its status segment — wants the
 * arrangement the viewer tab used to be: the preview alone, with the keys.
 */
final class Previews
{
    public static function alone(Ide $ide): void
    {
        $active = Services::resolve($ide, BufferManager::class)->active();
        Assert::assertNotNull($active, 'a previewed file is open');
        Assert::assertNotNull($active->previewArrangement(), \basename($active->path).' opened with a preview');
        Services::resolve($ide, BufferService::class)->arrangePreview($active, PreviewMode::Preview);
    }
}
