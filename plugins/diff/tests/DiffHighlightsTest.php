<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests;

use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\DiffHighlights;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\SwitchableTerminalTheme;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class DiffHighlightsTest extends TestCase
{
    private const string RAW = "@@ -1,3 +1,3 @@\n \$a = 1;\n-\$b = 2;\n+\$c = 3;\n@@ -9,1 +9,1 @@\n+\$d = 4;\n\\ No newline at end of file\n";

    public function testTheContextAndAddedLinesOfEveryHunkAreColouredAndKeyed(): void
    {
        $out = new DiffHighlights()->of(DiffDocument::parse(self::RAW), 'a.php');

        // The deletion (0:1) and the marker (1:1) stay plain: they are not keyed.
        $this->assertSame(['0:0', '0:2', '1:0'], \array_keys($out));
        $this->assertSame(['$a = 1;', '$c = 3;', '$d = 4;'], \array_map(AnsiUtils::stripAnsiCodes(...), \array_values($out)));
        $this->assertStringContainsString("\e[", $out['0:0'], 'coloured as PHP');
    }

    public function testTheLanguageIsThePaths(): void
    {
        $highlights = new DiffHighlights();
        $document = DiffDocument::parse(self::RAW);

        $this->assertNotSame($highlights->of($document, 'a.php'), $highlights->of($document, 'a.txt'));
    }

    public function testItColoursWithTheThemeItIsGivenAndFollowsASwitch(): void
    {
        $document = DiffDocument::parse(self::RAW);
        $light = new DiffHighlights(new LightTerminalTheme())->of($document, 'a.php');
        $theme = new SwitchableTerminalTheme(new DarkTerminalTheme());
        $highlights = new DiffHighlights($theme);
        $dark = $highlights->of($document, 'a.php');
        $this->assertNotSame($light, $dark, 'the given theme, not the default');

        $theme->switchTo(new LightTerminalTheme());
        $highlights->refreshTheme();

        $this->assertSame($light, $highlights->of($document, 'a.php'));
    }
}
