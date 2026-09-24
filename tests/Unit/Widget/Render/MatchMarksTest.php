<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Render\MatchMarks;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class MatchMarksTest extends TestCase
{
    /** Each matched character is bold and underlined, then both switched off again; the text is unchanged. */
    public function testMatchedCharactersAreMarkedAndTheTextKept(): void
    {
        $marked = MatchMarks::apply('Greeter  ', 'Greeter', 'gt', rowBold: false);

        $this->assertSame("\e[1m\e[4mG\e[24m\e[22mree\e[1m\e[4mt\e[24m\e[22mer  ", $marked);
        $this->assertSame('Greeter  ', AnsiUtils::stripAnsiCodes($marked));
    }

    /** On an already-bold row a mark hands the bold back rather than switching it off. */
    public function testABoldRowKeepsItsWeight(): void
    {
        $this->assertSame("\e[1m\e[4mG\e[24mreeter", MatchMarks::apply('Greeter', 'Greeter', 'g', rowBold: true));
    }

    /** A match the cut hid is not drawn, and the ellipsis standing in for it is not marked. */
    public function testAMatchPastTheCutIsNotMarked(): void
    {
        $this->assertSame('Gree…', MatchMarks::apply('Gree…', 'Greeter', 'ter', rowBold: false));
        $this->assertSame("Gre\e[1m\e[4me\e[24m\e[22m…", MatchMarks::apply('Gree…', 'Greeter', 'et', rowBold: false), 'the e is shown, the t is where the cut is');
    }

    public function testNoMatchLeavesTheLabelAlone(): void
    {
        $this->assertSame('Greeter', MatchMarks::apply('Greeter', 'Greeter', 'xyz', rowBold: false));
        $this->assertSame('Greeter', MatchMarks::apply('Greeter', 'Greeter', '', rowBold: false));
    }
}
