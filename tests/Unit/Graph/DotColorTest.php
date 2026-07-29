<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Graph;

use PHPUnit\Framework\TestCase;
use Porta\Graph\DotColor;

final class DotColorTest extends TestCase
{
    public function testHexFormsNormalize(): void
    {
        $this->assertSame('#ffcc00', DotColor::normalize('#ffcc00'));
        $this->assertSame('#ffcc00', DotColor::normalize('#FFcc00'), 'lowercased');
        $this->assertSame('#ffcc00', DotColor::normalize('#fc0'), '#rgb expands');
        $this->assertSame('#ffcc00', DotColor::normalize('#ffcc0080'), 'alpha stripped');
        $this->assertNull(DotColor::normalize('#zzz'), 'non-hex digits');
        $this->assertNull(DotColor::normalize('#ffcc0'), 'wrong length');
    }

    public function testNamesAndUnknowns(): void
    {
        $this->assertSame('#ff0000', DotColor::normalize('red'));
        $this->assertSame('#add8e6', DotColor::normalize('LightBlue'), 'case-insensitive');
        $this->assertSame('#bebebe', DotColor::normalize('grey'));
        $this->assertNull(DotColor::normalize('chartreuse4'), 'unlisted name falls back to theme');
        $this->assertNull(DotColor::normalize(null));
        $this->assertNull(DotColor::normalize('   '));
    }

    public function testHsvTriples(): void
    {
        $this->assertSame('#ff0000', DotColor::normalize('0.0,1.0,1.0'));
        $this->assertSame('#00ff00', DotColor::normalize('0.333333 1.0 1.0'), 'space-separated accepted');
        $this->assertSame('#ffffff', DotColor::normalize('0.5,0.0,1.0'), 'zero saturation is white');
        $this->assertSame('#00ff66', DotColor::normalize('0.4,1.0,1.0'), 'the green-cyan sextant');
        $this->assertSame('#8000ff', DotColor::normalize('0.75,1.0,1.0'), 'the blue-violet sextant');
        $this->assertSame('#ff004d', DotColor::normalize('0.95,1.0,1.0'), 'the magenta-red sextant');
    }

    public function testColorListsTakeTheFirstEntry(): void
    {
        $this->assertSame('#ff0000', DotColor::normalize('red:blue'));
        $this->assertSame('#ff0000', DotColor::normalize('red;0.5:blue'), 'gradient weight stripped');
    }
}
