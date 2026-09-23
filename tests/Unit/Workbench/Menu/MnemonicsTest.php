<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Menu;

use PHPUnit\Framework\TestCase;
use Porta\Workbench\Menu\Mnemonics;

final class MnemonicsTest extends TestCase
{
    public function testAWordInitialWinsAndNoTwoLabelsShareALetter(): void
    {
        // Save, then Save As: S is taken, so the second word's A.
        $this->assertSame([0, 5, 0, 0], Mnemonics::assign(['Save', 'Save As', 'Close', 'Quit']));
    }

    public function testWithNoFreeInitialAnyOtherLetterWillDo(): void
    {
        $this->assertSame([0, 1], Mnemonics::assign(['Save', 'Stash']));
    }

    public function testLettersAreCaseFolded(): void
    {
        $this->assertSame([0, 1], Mnemonics::assign(['Open', 'open']), 'o and O are one key');
    }

    public function testALabelWithNoFreeLetterHasNone(): void
    {
        $this->assertSame([0, 0, null], Mnemonics::assign(['ab', 'ba', 'ab']));
    }

    public function testPunctuationAndSpacesAreSkipped(): void
    {
        $this->assertSame([1, 1], Mnemonics::assign(['…Find', '(x) Other']), 'the first letter, not the ellipsis or the paren — a letter after one begins a word');
    }

    public function testNonLatinScriptsGetLettersOfTheirOwn(): void
    {
        $this->assertSame([0, 0, 0], Mnemonics::assign(['קובץ', 'עריכה', '表示']));
        $this->assertSame('ק', \mb_substr('קובץ', start: 0, length: 1));
    }

    public function testTheKeyForACharacter(): void
    {
        $this->assertSame('s', Mnemonics::key('S'));
        $this->assertSame('7', Mnemonics::key('7'));
        $this->assertNull(Mnemonics::key('…'));
        $this->assertNull(Mnemonics::key(' '));
    }
}
