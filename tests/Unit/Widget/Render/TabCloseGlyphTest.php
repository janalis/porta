<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TabCloseGlyph;

final class TabCloseGlyphTest extends TestCase
{
    /**
     * Under the pointer the × is underlined, not only brightened: the one
     * change of face that still reads in the monochrome theme, and what sets
     * the hover apart from the active tab's bright ×.
     */
    public function testTheHoveredCloseIsUnderlinedInEveryTheme(): void
    {
        foreach ([Styles::dark(), new Styles(MonochromeTheme::definition())] as $styles) {
            $this->assertStringContainsString("\x1b[4m", TabCloseGlyph::hovered($styles));
            $this->assertStringNotContainsString("\x1b[4m", TabCloseGlyph::active($styles));
            $this->assertNotSame(TabCloseGlyph::active($styles), TabCloseGlyph::hovered($styles));
        }
    }
}
