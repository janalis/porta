<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Buffer;
use Porta\Editor\BufferManager;
use Porta\Event\ThemeChanged;
use Porta\Highlight\HighlightThemeListener;
use Porta\Tests\Support\Probe;
use Porta\Ui\Theme\Theme;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The listener that drops live highlight caches after a theme switch, replacing
 * the loop that used to run inline in LiveSettingsApplier before the event was
 * even dispatched.
 */
final class HighlightThemeListenerTest extends TestCase
{
    public function testDropsEveryOpenBuffersHighlightCache(): void
    {
        $buffers = new BufferManager();
        $one = $buffers->open('/x/a.php', "<?php\nreturn 1;");
        $two = $buffers->open('/x/b.php', "<?php\nreturn 2;");

        foreach ([$one, $two] as $buffer) {
            $buffer->highlighter->highlight(['<?php', 'return 1;'], 1);
        }

        $before = \array_map(static fn (Buffer $b): int => Probe::get($b->highlighter, 'fullParses'), [$one, $two]);

        new HighlightThemeListener($buffers)->onThemeChanged(new ThemeChanged(Theme::Light));

        // The cache is gone, so the next paint re-parses rather than replaying.
        foreach ([$one, $two] as $buffer) {
            $buffer->highlighter->highlight(['<?php', 'return 1;'], 1);
        }

        $after = \array_map(static fn (Buffer $b): int => Probe::get($b->highlighter, 'fullParses'), [$one, $two]);

        $this->assertSame([$before[0] + 1, $before[1] + 1], $after);
    }

    public function testRepaintAfterTheSwitchStillProducesTheSameText(): void
    {
        $buffers = new BufferManager();
        $buffer = $buffers->open('/x/a.php', "<?php\nreturn 1;");
        $lines = ['<?php', 'return 1;'];

        $buffer->highlighter->highlight($lines, 1);
        new HighlightThemeListener($buffers)->onThemeChanged(new ThemeChanged(Theme::Light));

        $repainted = $buffer->highlighter->highlight($lines, 1);

        $this->assertSame($lines, \array_map(AnsiUtils::stripAnsiCodes(...), $repainted));
    }
}
