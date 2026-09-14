<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A plugin-supplied highlighting backend, end to end through the real
 * container: `registerHighlighter()` in a project plugin must actually paint
 * the editor, and the `highlight` config block must pick between backends.
 *
 * A project plugin is used (opt-out, loaded by default) rather than a bundled
 * one, so the phpunit-pinned `PORTA_EMBEDDED_PLUGINS=/nonexistent` is not in
 * the way.
 */
final class PluginHighlighterTest extends TestCase
{
    use RunsIde;

    /**
     * Paints every line with an unmistakable SGR color, so the assertion is
     * about *which backend ran*, not about grammar output.
     */
    private const string PLUGIN = <<<'PHP'
        <?php

        declare(strict_types=1);

        use Porta\Plugin\Api\PluginApi;
        use Porta\Plugin\Api\PortaPlugin;

        return new class implements PortaPlugin {
            public function name(): string
            {
                return 'shouty';
            }

            public function activate(PluginApi $api): void
            {
                $api->registerHighlighter('Shouty', ['php'], static function (string $content, string $language, \Closure $deliver): void {
                    $lines = explode("\n", $content);
                    $painted = array_map(static fn (string $line): string => "\e[38;5;201m".$line."\e[39m", $lines);

                    $deliver(implode("\n", $painted));
                });
            }
        };
        PHP;

    public function testPluginBackendPaintsTheEditor(): void
    {
        $project = new TempProject([
            '.porta/plugins/shouty/plugin.php' => self::PLUGIN,
            'a.php' => "<?php\n\$x = 1;\n",
        ]);

        $output = $this->openAndCapture($project);

        $this->assertStringContainsString("\x1b[38;5;201m", $output, 'the plugin backend painted the buffer');
    }

    /** An explicit `highlight.default` of the built-in keeps the plugin out. */
    public function testConfigCanSelectTheBuiltInOverAPluginBackend(): void
    {
        $project = new TempProject([
            '.porta.json' => '{"highlight":{"default":"tempest"}}',
            '.porta/plugins/shouty/plugin.php' => self::PLUGIN,
            'a.php' => "<?php\n\$x = 1;\n",
        ]);

        $output = $this->openAndCapture($project);

        $this->assertStringNotContainsString("\x1b[38;5;201m", $output, 'config pinned tempest');
        $this->assertStringContainsString("\x1b[38;5;", $output, 'but the file is still colored');
    }

    /** A backend that only claims other languages leaves php to the built-in. */
    public function testPluginBackendIsSkippedForLanguagesItDoesNotClaim(): void
    {
        $plugin = \str_replace("['php']", replace: "['rust']", subject: self::PLUGIN);
        $project = new TempProject([
            '.porta/plugins/shouty/plugin.php' => $plugin,
            'a.php' => "<?php\n\$x = 1;\n",
        ]);

        $output = $this->openAndCapture($project);

        $this->assertStringNotContainsString("\x1b[38;5;201m", $output);
    }

    /**
     * Awaits the buffer actually being on screen rather than sampling at a
     * fixed delay — under pcov the container recompiles, so boot is slow enough
     * for a timed sample to catch an empty editor.
     */
    private function openAndCapture(TempProject $project): string
    {
        $captured = '';
        $this->runIde($project, $project->path('a.php'), [
            60 => function ($tui, $terminal) use (&$captured): void {
                $this->await(
                    static fn (): bool => \str_contains(AnsiUtils::stripAnsiCodes($terminal->getOutput()), '$x = 1;'),
                    what: 'the buffer to paint',
                );
                $captured = $terminal->getOutput();
            },
        ]);

        return $captured;
    }
}
