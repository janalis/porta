<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Widget\Render\EditorRenderer;

final class EditorRendererTest extends TestCase
{
    public function testInlayShiftAtCountsOnlyHintsAtOrBeforeTheTarget(): void
    {
        $doc = new Document("abcdefgh\n");
        // One hint before the target display column, one after it.
        $hints = [['col' => 2, 'text' => '»'], ['col' => 6, 'text' => ': int']];

        // Target display column 4: the col-6 hint sits past it and is skipped
        // (the `continue`), so only the col-2 hint contributes to the shift.
        $this->assertSame(mb_strwidth('»'), EditorRenderer::inlayShiftAt($doc, 0, $hints, 4));

        // A target past both hints includes both widths.
        $this->assertSame(mb_strwidth('»') + mb_strwidth(': int'), EditorRenderer::inlayShiftAt($doc, 0, $hints, 10));
    }
}
