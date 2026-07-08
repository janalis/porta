<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticStyle;
use Porta\Ui\ColorPalette;
use Porta\Ui\Role;

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

        // The stripe marker is a single plain block; severity is conveyed by color.
        $this->assertSame('▉', DiagnosticStyle::MARK);
    }

    public function testUnderlineOpenIsCurlyAndColored(): void
    {
        $palette = ColorPalette::dark();
        $this->assertSame("\x1b[4:3m\x1b[58;5;203m", DiagnosticStyle::underlineOpen(1, $palette));
        $this->assertSame("\x1b[4:3m\x1b[58;5;179m", DiagnosticStyle::underlineOpen(2, $palette));
        $this->assertSame("\x1b[59m\x1b[24m", DiagnosticStyle::underlineClose());
    }
}
