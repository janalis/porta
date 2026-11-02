<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The shipped chroma plugin (plugins/chroma/porta.php) against stub
 * binaries — the real plugin file is loaded from the repo, and PATH is blanked
 * so a chroma that happens to be installed on the machine can never be found.
 *
 * This exercises the genuine paint-then-refine race rather than a mock of it:
 * the IDE paints, the subprocess answers later on the real event loop, and the
 * buffer repaints.
 */
final class ChromaPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /**
     * A stub that tokenises whatever it is given: the first two bytes as a
     * keyword, the rest as plain text. Built from stdin so the values always
     * reconcatenate to the source exactly — a stream that does not is a
     * *different* case, covered separately below.
     */
    private const string STUB_OK = <<<'PHP'
        // A sentinel beside the project, so a failure can say whether the
        // subprocess ever ran — see the await below.
        file_put_contents(dirname(__DIR__, 2).'/stub.ran', "1\n", FILE_APPEND);
        $src = (string) stream_get_contents(STDIN);
        echo json_encode([
            ['type' => 'Keyword', 'value' => substr($src, 0, 2)],
            ['type' => 'Text', 'value' => substr($src, 2)],
        ]);
        PHP;

    private function project(string $stubBody, string $source = "fn main()\n"): TempProject
    {
        $plugin = (string) \file_get_contents(__DIR__.'/../../plugins/chroma/porta.php');
        $project = new TempProject([
            '.porta/plugins/chroma/porta.php' => $plugin,
            'a.rs' => $source,
        ]);
        // Named by its setting: chroma is a system tool, never looked for in
        // vendor/bin, and the PATH is not this test's to change. Two levels
        // under the project, where the stub's sentinel expects to be.
        \mkdir($project->path('vendor/bin'), permissions: 0o777, recursive: true);
        $stub = $project->path('vendor/bin/chroma');
        \file_put_contents($stub, '#!'.\PHP_BINARY.' -n'."\n<?php\n".$stubBody);
        \chmod($stub, permissions: 0o755);
        // Claim rust and drop the debounce so the test does not wait on it.
        \file_put_contents($project->path('.porta.json'), \json_encode(['plugins' => ['chroma' => ['languages' => 'rust', 'debounce' => 0, 'binary' => $stub]]], \JSON_THROW_ON_ERROR));

        return $project;
    }

    public function testChromaColorsTheBufferAfterTheSubprocessAnswers(): void
    {
        $project = $this->project(self::STUB_OK);
        $captured = '';

        $this->runIde($project, $project->path('a.rs'), [
            60 => function ($tui, $terminal) use (&$captured, $project): void {
                // A bare timeout here says nothing, and this test has a history
                // of failing about 1 run in 10 under a full parallel suite and
                // never once on its own — 24 serial runs under sixteen CPU
                // burners, a whole suite run serially in one process, and a dozen
                // parallel suites all green. Whoever catches it next needs to
                // know which half broke: the subprocess never ran, or it ran and
                // its paint never landed.
                try {
                    $this->await(
                        static fn (): bool => \str_contains($terminal->getOutput(), "\x1b[38;5;176m"),
                        what: 'chroma keyword coloring to land',
                    );
                } catch (\RuntimeException $timeout) {
                    self::fail(
                        $timeout->getMessage()
                        .' — the stub '
                        .(
                            \is_file($project->path('stub.ran'))
                                ? 'DID run, so the answer or the repaint was lost'
                                : 'never ran, so the dispatch or the spawn was lost'
                        ),
                    );
                }
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
            // Past the 250ms debounce, then long enough for the child to exit
            // inside a RUNNING loop: this test asserts what does NOT reach the
            // buffer, so it never awaits the backend, and walking out between
            // the debounce and the exit strands its stdout watcher.
            //
            // What stays armed after this is CodeHighlighter's 2s answer
            // deadline — correct, since the backend died without delivering and
            // that timer is how it gets dropped to plain. A test that stops the
            // loop before it fires reports a [loop-leak] and should.
            400 => function (): void {
                $this->pump(0.3);
            },
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
            // Past the 250ms debounce, then long enough for the child to exit
            // inside a RUNNING loop: this test asserts what does NOT reach the
            // buffer, so it never awaits the backend, and walking out between
            // the debounce and the exit strands its stdout watcher.
            //
            // What stays armed after this is CodeHighlighter's 2s answer
            // deadline — correct, since the backend died without delivering and
            // that timer is how it gets dropped to plain. A test that stops the
            // loop before it fires reports a [loop-leak] and should.
            400 => function (): void {
                $this->pump(0.3);
            },
        ]);

        $this->assertStringContainsString('fn main()', AnsiUtils::stripAnsiCodes($terminal->getOutput()));
    }

    /** With no binary anywhere the backend declines and the built-in serves the file. */
    public function testWithoutTheBinaryTheBackendDeclines(): void
    {
        $plugin = (string) \file_get_contents(__DIR__.'/../../plugins/chroma/porta.php');
        $project = new TempProject([
            '.porta/plugins/chroma/porta.php' => $plugin,
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
