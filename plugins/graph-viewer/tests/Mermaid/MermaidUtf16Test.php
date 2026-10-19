<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Mermaid\MermaidText;
use Porta\GraphViewer\Mermaid\MermaidUtf16;
use Symfony\Component\Yaml\Yaml;

final class MermaidUtf16Test extends TestCase
{
    public function testQuotedKeysRoundTripCodeUnitsEscapesAndSupplementaryCharacters(): void
    {
        $text = '"\\uD800'."\n\xED\xA0\x80\xED\xAF\xBF\xED\xB0\x80\xED\xBF\xBF😀";
        $quoted = MermaidUtf16::quote($text);
        $this->assertSame(MermaidUtf16::pairs($text), Yaml::parse($quoted));
        $this->assertSame('""', MermaidUtf16::quote(''));
        $this->assertSame('"😀"', MermaidUtf16::quote("\xED\xA0\xBD\xED\xB8\x80"));
        $this->assertNotSame(MermaidUtf16::quote("\xED\xA0\x80"), MermaidUtf16::quote("\xED\xA0\x81"));
        $this->assertNotSame(MermaidUtf16::quote("\xED\xA0\x80"), MermaidUtf16::quote("\u{FFFD}"));
    }

    public function testPairsPreserveScalarIdentityAndDisplayReplacesUnpairedUnits(): void
    {
        $high = "\xED\xA0\xBD";
        $low = "\xED\xB8\x80";
        $this->assertSame('😀', MermaidUtf16::pairs($high.$low));
        $this->assertSame("\u{10000}", MermaidUtf16::pairs("\xED\xA0\x80\xED\xB0\x80"));
        $this->assertSame("\u{10FFFF}", MermaidUtf16::pairs("\xED\xAF\xBF\xED\xBF\xBF"));
        $this->assertSame($low.$high, MermaidUtf16::pairs($low.$high));
        $this->assertSame("a😀b\u{FFFD}c\u{FFFD}", MermaidText::decode('a'.$high.$low.'b'.$high.'c'.$low));
        $this->assertSame('#55357;#56832;', MermaidText::decode('#55357;#56832;'));
    }
}
