<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Markup;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\HighContrastTheme;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Theme\ThemeDefinition;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Style\Style;

/**
 * Styles::format() against the bytes the hand-written `Style::apply` chains
 * produce — the raw escapes here are the anchor the rest of the suite's
 * markup-built expectations rest on.
 */
final class StylesFormatTest extends TestCase
{
    public function testARoleTagIsThatRolesStyle(): void
    {
        $styles = Styles::dark();

        $this->assertSame("\x1b[38;5;114m+\x1b[39m", $styles->format('<added>+</>'));
        $this->assertSame($styles->of(Role::DiagWarn)->apply('w'), $styles->format('<diag-warn>w</diag-warn>'));
    }

    public function testFlatSpansConcatenate(): void
    {
        $styles = Styles::dark();

        $this->assertSame(
            ' '.$styles->of(Role::TabAccent)->apply('src/A.php').$styles->of(Role::Dim)->apply('  (3)'),
            $styles->format(' <tab-accent>src/A.php</><dim>  (3)</>'),
        );
    }

    public function testANestedSpanTakesTheMergedStyleAndClosesBackToItsParent(): void
    {
        $styles = Styles::dark();
        $outer = $styles->of(Role::SelectionBg);
        $inner = $styles->of(Role::Deleted);

        $this->assertSame(
            $outer->apply('a').Style::mergeAll([$outer, $inner])->apply('b').$outer->apply('c'),
            $styles->format('<selection-bg>a<deleted>b</>c</>'),
        );
    }

    public function testAClosedSpanGivesBackTheForegroundAroundIt(): void
    {
        $styles = Styles::dark();
        $bright = $styles->of(Role::Bright);

        $this->assertSame(
            $bright->apply('Saved ').$styles->of(Role::Added)->apply('a.php').$bright->apply(' now'),
            $styles->format('<bright>Saved <info>a.php</> now</>'),
            'the text after the inner span is bright again, not the terminal default',
        );
    }

    public function testFormatLinesStylesEachWrappedLineWhole(): void
    {
        $styles = Styles::dark();
        $info = $styles->of(Role::Added);

        $this->assertSame(
            ['a '.$info->apply('bb'), $info->apply('cc').' d'],
            $styles->formatLines('a <info>bb cc</> d', 5),
            'the span a break falls inside is closed on one line and reopened on the next',
        );
        $this->assertSame(
            [$info->apply('abcd'), $info->apply('ef')],
            $styles->formatLines('<info>abcdef</>', 4),
            'a hard break inside a word consumes no character',
        );
        $this->assertSame([''], $styles->formatLines('', 4));
    }

    public function testAnInnerLinkWinsAndALinkedRunKeepsItsStyle(): void
    {
        $linked = Styles::dark()->format('<href=https://a><info><href=https://b>x</></></>');

        $this->assertSame(Styles::dark()->of(Role::Added)->apply("\x1b]8;;https://b\x1b\\x\x1b]8;;\x1b\\"), $linked);
    }

    public function testATagPrintedAsTextStaysInsideItsSpansOneRun(): void
    {
        $info = Styles::dark()->of(Role::Added);

        $this->assertSame($info->apply('a <nope> b'), Styles::dark()->format('<info>a <nope> b</>'));
    }

    public function testAnEmptySpanEmitsNothing(): void
    {
        $this->assertSame('ab', Styles::dark()->format('a<info></info>b'));
    }

    /** @return iterable<string, array{string, Style}> */
    public static function inlineSpecs(): iterable
    {
        yield 'a Console name' => ['fg=red', new Style(color: 'red')];
        yield 'a bright Console name' => ['fg=bright-blue', new Style(color: 'bright_blue')];
        yield 'gray' => ['fg=gray', new Style(color: 'gray')];
        yield 'default' => ['fg=default', new Style(color: 'default')];
        yield 'a hex' => ['fg=#ff8800', new Style(color: '#ff8800')];
        yield 'a short hex, uppercase' => ['FG=#F80', new Style(color: '#ff8800')];
        yield 'a palette index' => ['bg=236', new Style(background: 236)];
        yield 'both colors' => ['fg=black;bg=cyan', new Style(color: 'black', background: 'cyan')];
        yield 'bold' => ['options=bold', new Style(bold: true)];
        yield 'underscore' => ['options=underscore', new Style(underline: true)];
        yield 'underline' => ['options=underline', new Style(underline: true)];
        yield 'reverse' => ['options=reverse', new Style(reverse: true)];
        yield 'dim' => ['options=dim', new Style(dim: true)];
        yield 'italic' => ['options=italic', new Style(italic: true)];
        yield 'strikethrough' => ['options=strikethrough', new Style(strikethrough: true)];
        yield 'blink is dropped' => ['options=blink', new Style()];
        yield 'conceal is dropped' => ['options=conceal', new Style()];
        yield 'several options' => ['fg=green;options=bold,underscore', new Style(color: 'green', bold: true, underline: true)];
    }

    #[DataProvider('inlineSpecs')]
    public function testAnInlineSpecIsTheTuiStyleItNames(string $tag, Style $expected): void
    {
        $this->assertSame($expected->apply('x'), Styles::dark()->format("<{$tag}>x</>"));
    }

    public function testMonochromeDropsInlineColorsButKeepsOptions(): void
    {
        $styles = new Styles(MonochromeTheme::definition());

        $this->assertSame(new Style(bold: true)->apply('x'), $styles->format('<fg=red;bg=blue;options=bold>x</>'));
        $this->assertSame(
            new Style(color: 'red')->apply('x'),
            Styles::dark()->format('<fg=red>x</>'),
            'positive control: a colored theme keeps the same color',
        );
    }

    public function testAnHrefIsAnOsc8LinkOfNoWidth(): void
    {
        $linked = Styles::dark()->format('see <href=https://example.com/a?b=1>docs</> here');

        $this->assertSame("see \x1b]8;;https://example.com/a?b=1\x1b\\docs\x1b]8;;\x1b\\ here", $linked);
        $this->assertSame(13, AnsiUtils::visibleWidth($linked));
        $this->assertSame('see docs…', AnsiUtils::stripAnsiCodes(AnsiText::truncate($linked, 9)));
    }

    public function testAnEscapedAngleInAnHrefIsTheAngle(): void
    {
        $this->assertSame("\x1b]8;;https://x/?q=<a>\x1b\\t\x1b]8;;\x1b\\", Styles::dark()->format('<href=https://x/?q=\\<a\\>>t</>'));
    }

    public function testConsoleStylesResolveInEveryTheme(): void
    {
        $roles = ['info' => Role::Added, 'comment' => Role::StatusWarning, 'question' => Role::Emphasis, 'error' => Role::StatusError];
        foreach ([DarkTheme::definition(), LightTheme::definition(), HighContrastTheme::definition(), MonochromeTheme::definition()] as $definition) {
            $styles = new Styles($definition);
            foreach ($roles as $tag => $role) {
                $this->assertSame($styles->of($role)->apply('x'), $styles->format("<{$tag}>x</>"));
            }
        }
    }

    public function testTheSameMarkupRecolorsAfterAThemeSwitch(): void
    {
        $styles = Styles::dark();
        $dark = $styles->format('<info>ok</>');

        $styles->switchTo(LightTheme::definition());

        $this->assertNotSame($dark, $styles->format('<info>ok</>'));
        $this->assertSame(new Styles(LightTheme::definition())->of(Role::Added)->apply('ok'), $styles->format('<info>ok</>'));
    }

    public function testARoleTheThemeLeavesPlainRendersBare(): void
    {
        $plain = new Styles(new ThemeDefinition([], [], hasColor: true));

        $this->assertSame('x', $plain->format('<added>x</>'));
    }
}
