<?php

declare(strict_types=1);

namespace Porta\Custos\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferService;
use Porta\Editor\OpenFocus;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\Toasts;
use Porta\Tests\Support\UsesBundledPlugins;
use Porta\Tests\Support\Widgets;
use Symfony\Component\Tui\Tui;

/**
 * The custos bundled plugin through a real IDE, against a stub binary: `lsp`
 * execs the logging mock server (so the asserts read the initialize payload
 * off the wire log), and `fix` records its argv and appends a marker to the
 * file it was handed, the way the real fixer edits in place.
 */
final class IdeCustosPluginTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use UsesBundledPlugins;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    /**
     * @param array<string, string> $settings extra plugins.custos.* settings
     */
    private static function project(array $settings = []): TempProject
    {
        $project = new TempProject([
            'a.php' => "<?php\nif (\$x == null) {}\n",
            'notes.txt' => "plain\n",
        ]);
        $server = \dirname(__DIR__, levels: 3).'/tests/Support/logging-lsp-server.php';
        // PATH is /nonexistent: only shell builtins, and the php binary by path.
        $php = \PHP_BINARY;
        $stub = <<<SH
            #!/bin/sh
            if [ "\$1" = lsp ]; then
                exec "{$php}" "{$server}" "{$project->path('lsp.log')}"
            fi
            if [ "\$1" = fix ]; then
                echo "\$@" > "{$project->path('fix.log')}"
                for last; do :; done
                printf '// fixed\\n' >> "\$last"
                exit 0
            fi
            exit 64

            SH;
        \file_put_contents($project->path('custos-stub'), $stub);
        \chmod($project->path('custos-stub'), permissions: 0o755);
        \file_put_contents(
            $project->path('.porta.json'),
            \json_encode(
                [
                    'enabledPlugins' => ['custos'],
                    'plugins' => ['custos' => ['binary' => $project->path('custos-stub'), ...$settings]],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        return $project;
    }

    /**
     * Every `initialize` the stub logged, oldest first: whether it carried
     * `initializationOptions`, and what they were.
     *
     * @return list<array<string, mixed>|null>
     */
    private static function initializeOptions(string $log): array
    {
        if (!\is_file($log)) {
            return [];
        }
        $options = [];
        foreach (\array_filter(\explode("\n", (string) \file_get_contents($log))) as $line) {
            /** @var array{method?: string, params?: array{initializationOptions?: array<string, mixed>}}|null $message */
            $message = \json_decode($line, associative: true, flags: \JSON_THROW_ON_ERROR);
            if (\is_array($message) && 'initialize' === ($message['method'] ?? null)) {
                $options[] = $message['params']['initializationOptions'] ?? null;
            }
        }

        return $options;
    }

    public function testTheServerLaunchesAsTheLspSubcommandWithTheChosenOverrides(): void
    {
        $project = self::project(['php' => '8.2', 'comparisonStyle' => 'yoda']);
        $log = $project->path('lsp.log');

        $this->runIde($project, $project->path('a.php'), [
            300 => function () use ($log): void {
                // The stub only execs the server for `lsp`: a log at all is
                // the argv assert.
                $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the first initialize to reach the wire log');
                $this->assertSame(['php' => '8.2', 'comparisonStyle' => 'yoda'], self::initializeOptions($log)[0]);
            },
        ]);
    }

    public function testWithNothingChosenTheHandshakeCarriesNoOptions(): void
    {
        $project = self::project();
        $log = $project->path('lsp.log');

        $this->runIde($project, $project->path('a.php'), [
            300 => function () use ($log): void {
                $this->await(static fn (): bool => [] !== self::initializeOptions($log), what: 'the first initialize to reach the wire log');
                $this->assertNull(self::initializeOptions($log)[0], 'the project config decides: nothing overrides it');
            },
        ]);
    }

    public function testFixFileRunsTheFixerAndAppliesItIntoTheBufferAsOneUndoStep(): void
    {
        $project = self::project(['php' => '8.3', 'comparisonStyle' => 'yoda']);

        $this->runIde($project, $project->path('a.php'), [
            200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                Services::resolve($ide, CommandRegistry::class)->run('custos:fix');
                $editor = Widgets::editor($tui);
                $this->await(static fn (): bool => \str_contains($editor->getDocument()->getText(), '// fixed'), what: 'the fixed file to land in the buffer');
                $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'custos: fixed a.php'), what: 'the success toast');
                // Compared through realpath(): the root and the open file can
                // name one temp dir by two spellings (/var vs /private/var).
                $args = \explode(' ', \trim((string) \file_get_contents($project->path('fix.log'))));
                $this->assertCount(8, $args);
                $this->assertSame(
                    ['fix', '--config', '--php', '8.3', '--comparison-style', 'yoda'],
                    [$args[0], $args[1], ...\array_slice($args, offset: 3, length: 4)],
                    'the overrides the server gets reach the fixer too',
                );
                $this->assertSame(\realpath($project->dir), \realpath($args[2]), 'the config is the project root\'s, as the server reads it');
                $this->assertSame(\realpath($project->path('a.php')), \realpath($args[7]));
                $this->assertFalse($editor->getDocument()->isDirty(), 'the buffer matches disk');

                $editor->handleInput("\x1a"); // ctrl+z
                $this->assertStringNotContainsString('// fixed', $editor->getDocument()->getText(), 'one undo step takes it back');
            },
        ]);
    }

    public function testFixFileRefusesADirtyBufferAndAFileThatIsNotPhp(): void
    {
        $project = self::project();

        $this->runIde($project, $project->path('a.php'), [
            200 => function (Tui $tui, $terminal, Ide $ide) use ($project): void {
                $commands = Services::resolve($ide, CommandRegistry::class);
                Widgets::editor($tui)->handleInput('x');
                $commands->run('custos:fix');
                $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'custos: save a.php first'), what: 'the save-first flash');
                $this->assertFileDoesNotExist($project->path('fix.log'), 'the fixer never ran over the saved text');

                Services::resolve($ide, BufferService::class)->openFile($project->path('notes.txt'), OpenFocus::Editor);
                $commands->run('custos:fix');
                $this->await(static fn (): bool => \str_contains(Toasts::last($ide), 'custos: open a PHP file first'), what: 'the not-PHP flash');
                $this->assertFileDoesNotExist($project->path('fix.log'));
            },
        ]);
    }
}
