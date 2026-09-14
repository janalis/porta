<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DiagnosticSeverity;
use Porta\Ui\Role;

final class DiagnosticSeverityTest extends TestCase
{
    public function testTheWireValueBecomesACaseWithTheOldDefaults(): void
    {
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticSeverity::fromWire(null), 'absent → error, the LSP default');
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticSeverity::fromWire(1));
        $this->assertSame(DiagnosticSeverity::Warning, DiagnosticSeverity::fromWire(2));
        $this->assertSame(DiagnosticSeverity::Information, DiagnosticSeverity::fromWire(3));
        $this->assertSame(DiagnosticSeverity::Hint, DiagnosticSeverity::fromWire(4));
        $this->assertSame(DiagnosticSeverity::Warning, DiagnosticSeverity::fromWire('2'), 'a numeric string is the number');
        $this->assertSame(DiagnosticSeverity::Hint, DiagnosticSeverity::fromWire(0), 'out of range → hint, as it was always drawn');
        $this->assertSame(DiagnosticSeverity::Hint, DiagnosticSeverity::fromWire(99));
        $this->assertSame(DiagnosticSeverity::Hint, DiagnosticSeverity::fromWire('error'), 'not a number → hint');
    }

    public function testWorseAndOutranksFollowTheScale(): void
    {
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticSeverity::Warning->worse(DiagnosticSeverity::Error));
        $this->assertSame(DiagnosticSeverity::Error, DiagnosticSeverity::Error->worse(DiagnosticSeverity::Hint));
        $this->assertSame(DiagnosticSeverity::Warning, DiagnosticSeverity::Warning->worse(DiagnosticSeverity::Warning));
        $this->assertTrue(DiagnosticSeverity::Warning->outranks(DiagnosticSeverity::Information));
        $this->assertFalse(DiagnosticSeverity::Information->outranks(DiagnosticSeverity::Information), 'strictly');
        $this->assertFalse(DiagnosticSeverity::Hint->outranks(DiagnosticSeverity::Information));
    }

    public function testRolePerSeverity(): void
    {
        $this->assertSame(Role::DiagError, DiagnosticSeverity::Error->role());
        $this->assertSame(Role::DiagWarn, DiagnosticSeverity::Warning->role());
        $this->assertSame(Role::DiagInfo, DiagnosticSeverity::Information->role());
        $this->assertSame(Role::DiagHint, DiagnosticSeverity::Hint->role());

        $this->assertSame(Role::DiagErrorDim, DiagnosticSeverity::Error->dimRole());
        $this->assertSame(Role::DiagWarnDim, DiagnosticSeverity::Warning->dimRole());
        $this->assertSame(Role::DiagInfoDim, DiagnosticSeverity::Information->dimRole());
        $this->assertSame(Role::DiagHintDim, DiagnosticSeverity::Hint->dimRole());
    }

    public function testGlyphPerSeverity(): void
    {
        $this->assertSame('✗', DiagnosticSeverity::Error->glyph());
        $this->assertSame('▲', DiagnosticSeverity::Warning->glyph());
        $this->assertSame('◦', DiagnosticSeverity::Information->glyph());
        $this->assertSame('·', DiagnosticSeverity::Hint->glyph());
        foreach (DiagnosticSeverity::cases() as $severity) {
            $this->assertSame(1, \mb_strwidth($severity->glyph()), 'glyphs are one column wide');
        }
    }

    public function testMarkDensityPerSeverity(): void
    {
        $this->assertSame('█', DiagnosticSeverity::Error->mark());
        $this->assertSame('▓', DiagnosticSeverity::Warning->mark());
        $this->assertSame('▒', DiagnosticSeverity::Information->mark());
        $this->assertSame('░', DiagnosticSeverity::Hint->mark());
    }
}
