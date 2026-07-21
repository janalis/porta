<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CapturingHighlightSink;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\DarkTerminalTheme;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\LightTerminalTheme;
use Porta\Highlight\PlainTextHighlightProvider;
use Porta\Highlight\SwitchableTerminalTheme;
use Porta\Highlight\TempestHighlightProvider;
use Porta\Highlight\ThemeAwareHighlightProvider;
use Porta\Tests\Support\ClosureHighlightProvider;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The backends behind {@see \Porta\Highlight\SyntaxHighlightProvider}.
 */
final class HighlightProviderTest extends TestCase
{
    public function testPlainTextProviderReturnsTheSourceUnchanged(): void
    {
        $provider = new PlainTextHighlightProvider();

        $this->assertSame('plain', $provider->id());
        $this->assertSame("<?php\necho 1;", $this->markup($provider, "<?php\necho 1;"));
    }

    /** The fallback must never decline — that is the whole point of it. */
    public function testPlainTextProviderClaimsEveryLanguage(): void
    {
        $provider = new PlainTextHighlightProvider();

        foreach (['php', 'rust', 'not-a-language', ''] as $language) {
            $this->assertTrue($provider->supports($language));
        }
    }

    public function testTempestProviderColorsAndIdentifiesItself(): void
    {
        $provider = new TempestHighlightProvider(new DarkTerminalTheme());

        $this->assertSame('tempest', $provider->id());
        $this->assertStringContainsString("\x1b[", $this->markup($provider, "<?php\nreturn 'x';"));
    }

    /** Tempest falls back to its own TextLanguage, so it claims anything named. */
    public function testTempestProviderClaimsAnyNamedLanguage(): void
    {
        $provider = new TempestHighlightProvider(new DarkTerminalTheme());

        $this->assertTrue($provider->supports('php'));
        $this->assertTrue($provider->supports('rust'));
        $this->assertFalse($provider->supports(''));
    }

    /**
     * Tempest bakes the theme's escape codes at construction, so a live switch
     * has to rebuild it — swapping the holder alone leaves the old colors.
     */
    public function testTempestProviderRebuildsOnThemeRefresh(): void
    {
        $holder = new SwitchableTerminalTheme(new DarkTerminalTheme());
        $provider = new TempestHighlightProvider($holder);
        $source = "<?php\nreturn 'x';";

        $dark = $this->markup($provider, $source);

        $holder->switchTo(new LightTerminalTheme());
        $provider->refreshTheme();
        $light = $this->markup($provider, $source);

        $this->assertNotSame($dark, $light, 'rebuilt against the swapped theme');
        $this->assertSame(AnsiUtils::stripAnsiCodes($dark), AnsiUtils::stripAnsiCodes($light));
    }

    public function testTempestProviderIsThemeAware(): void
    {
        $this->assertInstanceOf(
            ThemeAwareHighlightProvider::class,
            new TempestHighlightProvider(new DarkTerminalTheme()),
        );
    }

    /** A theme-unaware backend must still get its cache dropped on a switch. */
    public function testRefreshThemeInvalidatesEvenForAThemeUnawareProvider(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $content);
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('x.php');

        $highlighter->highlight(['a'], 1);
        $this->assertSame(1, $provider->calls);

        $highlighter->refreshTheme();
        $highlighter->highlight(['a'], 1);

        $this->assertSame(2, $provider->calls, 'cache dropped, so the backend ran again');
    }

    /** Drive a provider through the sink and return what it delivered. */
    private function markup(\Porta\Highlight\SyntaxHighlightProvider $provider, string $source): string
    {
        $sink = new CapturingHighlightSink();
        $provider->highlight(new HighlightRequest('/x/a.php', 'php', $source, 1), $sink);

        return (string) $sink->markup;
    }

    /**
     * The seam sits below CodeHighlighter's version check, so repeated renders
     * at one document version never reach the backend. This is what keeps
     * plugin-supplied engines off the per-frame path.
     */
    public function testRepeatedRendersAtOneVersionInvokeTheBackendOnce(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $content);
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('x.php');

        for ($i = 0; $i < 25; ++$i) {
            $highlighter->highlight(['<?php', 'echo 1;'], 7);
        }

        $this->assertSame(1, $provider->calls);
    }
}
