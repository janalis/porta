<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The shipped chroma plugin (.porta/plugins/chroma/plugin.php) against stub
 * binaries — the real plugin file is loaded from the repo, and PATH is blanked
 * so a chroma that happens to be installed on the machine can never be found.
 *
 * This exercises the genuine paint-then-refine race rather than a mock of it:
 * the IDE paints, the subprocess answers later on the real event loop, and the
 * buffer repaints.
 */
final class ChromaPluginTest extends TestCase
{
    use RunsIde;

    private string $originalPath;

    protected function setUp(): void
    {
        $this->originalPath = PORTA_TEST_PRISTINE_PATH;
        putenv('PORTA_LSP');
        putenv('PATH=/nonexistent');
    }

    protected function tearDown(): void
    {
        putenv('PATH='.$this->originalPath);
    }

    /**
     * A stub that tokenises whatever it is given: the first two bytes as a
     * keyword, the rest as plain text. Built from stdin so the values always
     * reconcatenate to the source exactly — a stream that does not is a
     * *different* case, covered separately below.
     */
    private const string STUB_OK = <<<'PHP'
        $src = (string) stream_get_contents(STDIN);
        echo json_encode([
            ['type' => 'Keyword', 'value' => substr($src, 0, 2)],
            ['type' => 'Text', 'value' => substr($src, 2)],
        ]);
        PHP;

    private function project(string $stubBody, string $source = "fn main()\n"): TempProject
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/chroma/plugin.php');
        $project = new TempProject([
            '.porta/plugins/chroma/plugin.php' => $plugin,
            // Claim rust and drop the debounce so the test does not wait on it.
            '.porta.json' => '{"plugins":{"chroma":{"languages":"rust","debounce":0}}}',
            'a.rs' => $source,
        ]);

        if (!is_dir($project->path('vendor/bin'))) {
            mkdir($project->path('vendor/bin'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('vendor/bin/chroma'), '#!'.\PHP_BINARY.' -n'."\n<?php\n".$stubBody);
        chmod($project->path('vendor/bin/chroma'), permissions: 0o755);

        return $project;
    }

    public function testChromaColorsTheBufferAfterTheSubprocessAnswers(): void
    {
        $project = $this->project(self::STUB_OK);
        $captured = '';

        $this->runIde($project, $project->path('a.rs'), [
            60 => function ($tui, $terminal) use (&$captured): void {
                $this->await(
                    static fn (): bool => str_contains($terminal->getOutput(), "\x1b[38;5;176m"),
                    what: 'chroma keyword coloring to land',
                );
                $captured = $terminal->getOutput();
            },
        ]);

        $plain = AnsiUtils::stripAnsiCodes($captured);
        $this->assertStringContainsString('fn main()', $plain, 'the source survived the round trip');
    }

    /** A non-zero exit leaves Porta's current paint alone rather than blanking it. */
    public function testANonZeroExitLeavesTheBufferReadable(): void
    {
        $project = $this->project('fwrite(STDERR, "boom"); exit(2);');

        $terminal = $this->runIde($project, $project->path('a.rs'), [
            120 => static function (): void {},
        ]);

        $this->assertStringContainsString('fn main()', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** Malformed JSON must not reach the buffer as garbage. */
    public function testMalformedJsonIsIgnored(): void
    {
        $project = $this->project('echo "{not json";');

        $terminal = $this->runIde($project, $project->path('a.rs'), [
            120 => static function (): void {},
        ]);

        $plain = AnsiUtils::stripAnsiCodes($terminal->getOutput());
        $this->assertStringContainsString('fn main()', $plain);
        $this->assertStringNotContainsString('not json', $plain);
    }

    /**
     * Token values that don't reconcatenate to the source break the line
     * structure; core's line-count guard must catch it and keep plain text.
     */
    public function testAStreamThatDoesNotReconcatenateFallsBackToPlain(): void
    {
        $project = $this->project('echo json_encode([["type" => "Text", "value" => "a\nb\nc\nd\ne"]]);');

        $terminal = $this->runIde($project, $project->path('a.rs'), [
            120 => static function (): void {},
        ]);

        $this->assertStringContainsString('fn main()', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** With no binary anywhere the backend declines and the built-in serves the file. */
    public function testWithoutTheBinaryTheBackendDeclines(): void
    {
        $plugin = (string) file_get_contents(__DIR__.'/../../.porta/plugins/chroma/plugin.php');
        $project = new TempProject([
            '.porta/plugins/chroma/plugin.php' => $plugin,
            '.porta.json' => '{"plugins":{"chroma":{"languages":"php"}}}',
            'a.php' => "<?php\nreturn 1;\n",
        ]);

        $terminal = $this->runIde($project, $project->path('a.php'), [
            120 => static function (): void {},
        ]);

        $output = $terminal->getOutput();
        $this->assertStringContainsString('return 1;', AnsiUtils::stripAnsiCodes($output));
        $this->assertStringContainsString("\x1b[38;5;", $output, 'the built-in engine still colored it');
    }
}
