<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\TerminalBidi;

/**
 * Which terminals order right-to-left text themselves.
 *
 * Getting this wrong in EITHER direction breaks the screen: saying yes to a
 * terminal that draws what it is given leaves every Hebrew row in logical
 * order, and saying no to one that reorders makes it reorder twice.
 */
final class TerminalBidiTest extends TestCase
{
    public function testAPlainTerminalIsNotAssumedToReorder(): void
    {
        $this->assertFalse(TerminalBidi::isApplied(['TERM' => 'xterm-256color']));
    }

    public function testGhosttyAloneIsNotEnough(): void
    {
        // Ghostty's own engine has NO bidi. Keying off TERM_PROGRAM would
        // disable Porta's reorder for everyone running Ghostty directly, and
        // their Hebrew would come out backwards.
        $this->assertFalse(TerminalBidi::isApplied(['TERM_PROGRAM' => 'ghostty']));
    }

    public function testCmuxIsDetectedByItsBundleId(): void
    {
        // It shapes rows through CoreText, which applies UAX#9 unconditionally.
        $this->assertTrue(TerminalBidi::isApplied(['CMUX_BUNDLE_ID' => 'com.cmuxterm.app', 'TERM_PROGRAM' => 'ghostty']));
    }

    public function testTheOverrideTurnsItOnForATerminalNotYetKnownAbout(): void
    {
        $this->assertTrue(TerminalBidi::isApplied(['PORTA_TERMINAL_BIDI' => '1']));
    }

    public function testTheOverrideAlsoTurnsItOffAgain(): void
    {
        // Both directions, so a wrong guess here is always recoverable.
        $this->assertFalse(TerminalBidi::isApplied(['PORTA_TERMINAL_BIDI' => '0', 'CMUX_BUNDLE_ID' => 'com.cmuxterm.app']));
        $this->assertFalse(TerminalBidi::isApplied(['PORTA_TERMINAL_BIDI' => 'false', 'CMUX_BUNDLE_ID' => 'com.cmuxterm.app']));
    }

    public function testAnEmptyOverrideDefersToDetection(): void
    {
        // getenv() gives '' for a variable that is set but empty; that must not
        // read as "off" and strand a cmux user.
        $this->assertTrue(TerminalBidi::isApplied(['PORTA_TERMINAL_BIDI' => '', 'CMUX_BUNDLE_ID' => 'com.cmuxterm.app']));
        $this->assertFalse(TerminalBidi::isApplied(['PORTA_TERMINAL_BIDI' => false]));
    }
}
