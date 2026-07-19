<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Sgr;
use Symfony\Component\Tui\Style\Style;

final class SgrTest extends TestCase
{
    public function testResetForABackgroundStyleIsTheBackgroundReset(): void
    {
        // The colored themes' span closes were always DEFAULT_BG; resetFor must
        // reproduce that byte-for-byte so they stay unchanged.
        $this->assertSame(Sgr::DEFAULT_BG, Sgr::resetFor(new Style(background: 236)));
    }

    public function testResetForReverseCancelsReverse(): void
    {
        $this->assertSame(Sgr::NO_REVERSE, Sgr::resetFor(new Style(reverse: true)));
    }

    public function testResetForUnderlineCancelsUnderline(): void
    {
        $this->assertSame(Sgr::NO_UNDERLINE, Sgr::resetFor(new Style(underline: true)));
    }

    public function testResetForUnderlineAndBoldCancelsBoth(): void
    {
        // The Monochrome current-search match is underline + bold at its call site.
        $this->assertSame(Sgr::NO_UNDERLINE.Sgr::NORMAL_INTENSITY, Sgr::resetFor(new Style(underline: true, bold: true)));
    }

    public function testResetForBackgroundAndBoldMatchesTheOldHardcodedClose(): void
    {
        // The colored current-search close used to be "\x1b[49m\x1b[22m".
        $this->assertSame(Sgr::DEFAULT_BG.Sgr::NORMAL_INTENSITY, Sgr::resetFor(new Style(background: 236, bold: true)));
    }

    public function testResetForAPlainStyleIsEmpty(): void
    {
        $this->assertSame('', Sgr::resetFor(new Style()));
    }
}
