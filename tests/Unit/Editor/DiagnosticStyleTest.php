<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticSeverity;
use Porta\Editor\DiagnosticStyle;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;

final class DiagnosticStyleTest extends TestCase
{
    public function testWorstPicksTheMostSevere(): void
    {
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticStyle::worst([
            ['severity' => DiagnosticSeverity::Information],
            ['severity' => DiagnosticSeverity::Error],
            ['severity' => DiagnosticSeverity::Warning],
        ]));
        $this->assertSame(DiagnosticSeverity::Warning, DiagnosticStyle::worst([['severity' => DiagnosticSeverity::Hint], ['severity' => DiagnosticSeverity::Warning]]));
        $this->assertSame(DiagnosticSeverity::Hint, DiagnosticStyle::worst([]), 'empty → hint');
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticStyle::worst([[]]), 'missing severity defaults to error');
    }

    public function testUnderlineOpenEncodesSeverityInStyleAndColor(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $this->assertSame("\x1b[4:3m\x1b[58;5;203m", DiagnosticStyle::underlineOpen(DiagnosticSeverity::Error, $styles), 'error: curly');
        $this->assertSame("\x1b[4:5m\x1b[58;5;179m", DiagnosticStyle::underlineOpen(DiagnosticSeverity::Warning, $styles), 'warning: dashed');
        $this->assertSame("\x1b[4:4m\x1b[58;5;75m", DiagnosticStyle::underlineOpen(DiagnosticSeverity::Information, $styles), 'info: dotted');
        $this->assertSame("\x1b[4:1m\x1b[58;5;244m", DiagnosticStyle::underlineOpen(DiagnosticSeverity::Hint, $styles), 'hint: straight');
        $this->assertSame("\x1b[59m\x1b[24m", DiagnosticStyle::underlineClose());
    }
}
