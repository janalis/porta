<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferService;
use Porta\GraphViewer\ArchitectureArtifact;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\PumpsLoop;
use Porta\Tests\Support\TempProject;

/**
 * Where the artifact lands, and what it writes there. IdeGraphViewerTest drives
 * the same class through the real plugin file and a real IDE; these are the
 * plugin's own tests of its own class, which is what keeps it at 100% now that
 * it lives here — a plugin's code is graded by a serial pass over
 * `plugins/*&#47;tests` (see phpunit.xml.dist).
 */
final class ArchitectureArtifactTest extends TestCase
{
    use PumpsLoop;

    /** The one call $done receives: [$error, $path]. */
    private function generate(ArchitectureArtifact $artifact, ?string $command): array
    {
        $result = null;
        $artifact->generate($command, static function (?string $error, ?string $path) use (&$result): void {
            $result = [$error, $path];
        });
        // By REFERENCE: an arrow fn would capture $result by value at creation
        // and never see the callback's write (the recorded await trap).
        $this->await(static function () use (&$result): bool {
            return null !== $result;
        }, what: 'the generation to report');

        /** @var array{0: ?string, 1: ?string} $result */
        return $result;
    }

    public function testTheDefaultIsTheProjectRoot(): void
    {
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p')->path());
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p', '')->path(), 'an empty setting reads as the root');
        $this->assertSame('/p/architecture.dot', new ArchitectureArtifact('/p', '   ')->path(), 'so does a blank one');
    }

    public function testARelativeDirectoryResolvesFromTheProjectRoot(): void
    {
        $this->assertSame('/p/docs/arch/architecture.dot', new ArchitectureArtifact('/p', 'docs/arch')->path());
        $this->assertSame('/p/docs/architecture.dot', new ArchitectureArtifact('/p', 'docs/')->path(), 'a trailing slash is not a doubled one');
    }

    public function testAnAbsoluteDirectoryIsTakenAsGiven(): void
    {
        $this->assertSame('/tmp/out/architecture.dot', new ArchitectureArtifact('/p', '/tmp/out')->path());
    }

    public function testTheBuiltInScannerWritesTheArtifact(): void
    {
        $project = new TempProject([
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'src/Thing.php' => "<?php\n\nnamespace App;\n\nuse App\\Other\\Bit;\n\nfinal class Thing { public function f(): void { new Bit(); } }\n",
            'src/Other/Bit.php' => "<?php\n\nnamespace App\\Other;\n\nfinal class Bit {}\n",
        ]);
        $artifact = new ArchitectureArtifact($project->dir);

        [$error, $path] = $this->generate($artifact, null);

        $this->assertNull($error);
        $this->assertSame(\realpath($project->path('architecture.dot')), $path, 'the REALPATH, so a path-keyed tab reuse matches');
        $this->assertStringContainsString('digraph', (string) \file_get_contents((string) $path));
    }

    public function testAConfiguredCommandSuppliesTheDotAndItsDirectoryIsCreated(): void
    {
        $project = new TempProject();
        $artifact = new ArchitectureArtifact($project->dir, 'docs/deep/arch');

        [$error, $path] = $this->generate($artifact, "printf 'digraph { FromCommand }'");

        $this->assertNull($error);
        $this->assertSame('digraph { FromCommand }', (string) \file_get_contents((string) $path), 'stdout is the artifact');
        $this->assertDirectoryExists($project->path('docs/deep/arch'), 'a configured directory need not exist yet');
    }

    public function testAFailingCommandReportsItsStderrAndAnEmptyOneSaysSo(): void
    {
        $project = new TempProject();
        $artifact = new ArchitectureArtifact($project->dir);

        [$error, $path] = $this->generate($artifact, '>&2 printf "no generator here"; exit 3');
        $this->assertNull($path);
        $this->assertStringContainsString('no generator here', (string) $error);

        [$error, $path] = $this->generate($artifact, 'exit 0');
        $this->assertNull($path);
        $this->assertStringContainsString('produced no output', (string) $error);
        $this->assertFileDoesNotExist($project->path('architecture.dot'), 'nothing is written when there is nothing to write');
    }

    public function testAnUnwritableOutputDirectoryIsReportedRatherThanThrown(): void
    {
        $project = new TempProject(['blocked' => 'a file where the directory should go']);
        $artifact = new ArchitectureArtifact($project->dir, 'blocked');

        [$error, $path] = $this->generate($artifact, "printf 'digraph {}'");

        $this->assertNull($path);
        $this->assertStringContainsString('Cannot write', (string) $error);
    }

    public function testANewerGenerationSupersedesOneStillRunning(): void
    {
        $project = new TempProject();
        $artifact = new ArchitectureArtifact($project->dir);

        $first = null;
        $artifact->generate("sleep 5; printf 'digraph { Slow }'", static function (?string $error, ?string $path) use (&$first): void {
            $first = [$error, $path];
        });
        [$error, $path] = $this->generate($artifact, "printf 'digraph { Fast }'");

        $this->assertNull($error);
        $this->assertSame('digraph { Fast }', (string) \file_get_contents((string) $path));
        $this->assertNull($first, 'the superseded run reports nothing at all');
    }

    public function testTheNameCoreTitlesTabsByIsTheNameThisWrites(): void
    {
        // Core titles a graph tab "Project Architecture" by file name, and it
        // must not import a plugin to do it — so the two constants are separate
        // and this assertion is what keeps them equal. It lives on the plugin
        // side because dependency direction runs this way only: the plugin may
        // know about core, never the reverse.
        $this->assertSame(
            Probe::getStatic(BufferService::class, 'ARCHITECTURE_FILENAME'),
            ArchitectureArtifact::FILENAME,
            'rename one and the tab silently loses its title',
        );
    }
}
