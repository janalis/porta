<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticStyle;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;

final class DiagnosticStyleTest extends TestCase
{
    public function testWorstPicksTheLowestSeverityNumber(): void
    {
        $this->assertSame(1, DiagnosticStyle::worst([
            ['severity' => 3],
            ['severity' => 1],
            ['severity' => 2],
        ]));
        $this->assertSame(2, DiagnosticStyle::worst([['severity' => 4], ['severity' => 2]]));
        $this->assertSame(4, DiagnosticStyle::worst([]), 'empty → hint');
        $this->assertSame(1, DiagnosticStyle::worst([[]]), 'missing severity defaults to error');
    }

    public function testNormalizeClampsUnknownToHint(): void
    {
        $this->assertSame(1, DiagnosticStyle::normalize(1));
        $this->assertSame(4, DiagnosticStyle::normalize(4));
        $this->assertSame(4, DiagnosticStyle::normalize(0), 'unknown → hint');
        $this->assertSame(4, DiagnosticStyle::normalize(99), 'unknown → hint');
    }

    public function testRolePerSeverity(): void
    {
        $this->assertSame(Role::DiagError, DiagnosticStyle::role(1));
        $this->assertSame(Role::DiagWarn, DiagnosticStyle::role(2));
        $this->assertSame(Role::DiagInfo, DiagnosticStyle::role(3));
        $this->assertSame(Role::DiagHint, DiagnosticStyle::role(4));
        $this->assertSame(Role::DiagHint, DiagnosticStyle::role(9), 'unknown → hint role');

        $this->assertSame(Role::DiagErrorDim, DiagnosticStyle::dimRole(1));
        $this->assertSame(Role::DiagWarnDim, DiagnosticStyle::dimRole(2));
        $this->assertSame(Role::DiagInfoDim, DiagnosticStyle::dimRole(3));
        $this->assertSame(Role::DiagHintDim, DiagnosticStyle::dimRole(9), 'unknown → hint dim role');
    }

    public function testGlyphPerSeverity(): void
    {
        $this->assertSame('✗', DiagnosticStyle::glyph(1));
        $this->assertSame('▲', DiagnosticStyle::glyph(2));
        $this->assertSame('◦', DiagnosticStyle::glyph(3));
        $this->assertSame('·', DiagnosticStyle::glyph(4));
        $this->assertSame('·', DiagnosticStyle::glyph(9), 'unknown → hint glyph');
        foreach ([1, 2, 3, 4] as $severity) {
            $this->assertSame(1, \mb_strwidth(DiagnosticStyle::glyph($severity)), 'glyphs are one column wide');
        }
    }

    public function testMarkDensityPerSeverity(): void
    {
        $this->assertSame('█', DiagnosticStyle::mark(1));
        $this->assertSame('▓', DiagnosticStyle::mark(2));
        $this->assertSame('▒', DiagnosticStyle::mark(3));
        $this->assertSame('░', DiagnosticStyle::mark(4));
        $this->assertSame('░', DiagnosticStyle::mark(0), 'unknown → hint mark');
    }

    public function testUnderlineOpenEncodesSeverityInStyleAndColor(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $this->assertSame("\x1b[4:3m\x1b[58;5;203m", DiagnosticStyle::underlineOpen(1, $styles), 'error: curly');
        $this->assertSame("\x1b[4:5m\x1b[58;5;179m", DiagnosticStyle::underlineOpen(2, $styles), 'warning: dashed');
        $this->assertSame("\x1b[4:4m\x1b[58;5;75m", DiagnosticStyle::underlineOpen(3, $styles), 'info: dotted');
        $this->assertSame("\x1b[4:1m\x1b[58;5;244m", DiagnosticStyle::underlineOpen(4, $styles), 'hint: straight');
        $this->assertSame("\x1b[59m\x1b[24m", DiagnosticStyle::underlineClose());
    }
}
