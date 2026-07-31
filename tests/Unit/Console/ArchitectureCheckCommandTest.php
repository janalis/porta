<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\ArchitectureCheckCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The architecture ratchet, driven over fixture trees with fixture pins (the
 * command's own tree and pins are the production defaults, asserted last).
 * Fixtures use the real `Porta\` prefix because the checker is Porta's own
 * gate — the vendor prefix is baked in, only the scan root is a seam.
 */
final class ArchitectureCheckCommandTest extends TestCase
{
    /**
     * @param list<string>       $edges
     * @param list<list<string>> $sccs
     */
    private function tester(array $edges = [], array $sccs = []): CommandTester
    {
        return new CommandTester(new ArchitectureCheckCommand($edges, $sccs));
    }

    private function file(string $namespace, string $class, string $body = '', string $uses = ''): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace Porta\\{$namespace};\n\n{$uses}\nfinal class {$class}\n{\n{$body}\n}\n";
    }

    public function testAMatchingTreePasses(): void
    {
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'src/Beta/notes.txt' => 'not PHP: skipped by the walker',
            'tests/E2e/SmallTest.php' => "<?php\n    public function testOne(): void {}\n",
        ]);

        $tester = $this->tester(['Alpha>Beta']);
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('architecture-check: OK (1 namespace edges, 0 known SCCs', $tester->getDisplay());
    }

    public function testANewEdgeAndAVanishedEdgeBothFail(): void
    {
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
        ]);

        // Pins claim an edge the tree does not have, and miss the one it does.
        $tester = $this->tester(['Gamma>Delta']);
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);
        $errors = $tester->getErrorOutput();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('NEW edge Alpha>Beta', $errors);
        $this->assertStringContainsString('edge Gamma>Delta no longer exists', $errors);
    }

    public function testACommentOnlyReferenceIsNotAnEdgeAndNeitherIsAnUnusedImport(): void
    {
        $project = new TempProject([
            // The import is mentioned only in a docblock, and a second one is
            // never mentioned at all: neither is coupling.
            'src/Alpha/One.php' => "<?php\n\nnamespace Porta\\Alpha;\n\nuse Porta\\Beta\\Two;\nuse Porta\\Gamma\\Three;\n\n/** Prose about {@see Two} and Three. */\nfinal class One\n{\n}\n",
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'src/Gamma/Three.php' => $this->file('Gamma', 'Three'),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, 'no pins needed: prose and unused imports form no edges');
        $this->assertStringContainsString('(0 namespace edges', $tester->getDisplay());
    }

    public function testAnUnpinnedCycleFailsAndAPinnedOnePasses(): void
    {
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two', '    public function g(): void { new One(); }', "use Porta\\Alpha\\One;\n"),
        ]);
        $edges = ['Alpha>Beta', 'Beta>Alpha'];
        $cycle = ['Porta\Alpha\One', 'Porta\Beta\Two'];

        $unpinned = $this->tester($edges);
        $this->assertSame(Command::FAILURE, $unpinned->execute(['path' => $project->dir], ['capture_stderr_separately' => true]));
        $this->assertStringContainsString('new circular dependency — Porta\Alpha\One <-> Porta\Beta\Two', $unpinned->getErrorOutput());

        $pinned = $this->tester($edges, [$cycle]);
        $this->assertSame(Command::SUCCESS, $pinned->execute(['path' => $project->dir]));
        $this->assertStringContainsString('1 known SCCs', $pinned->getDisplay());
    }

    public function testAVanishedPinnedCycleFails(): void
    {
        $project = new TempProject(['src/Alpha/One.php' => $this->file('Alpha', 'One')]);

        $tester = $this->tester([], [['Porta\Alpha\One', 'Porta\Beta\Two']]);
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('no longer exists — remove it from ALLOWED_SCCS', $tester->getErrorOutput());
    }

    public function testAnOversizedE2eSuiteFails(): void
    {
        $methods = str_repeat("    public function testMany(): void {}\n", times: 61);
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'tests/E2e/HugeTest.php' => "<?php\n".$methods,
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('has 61 test methods (max 60)', $tester->getErrorOutput());
    }

    public function testAnEmptyTreeIsCleanAndNeedsNoDirectories(): void
    {
        // Neither src/ nor tests/E2e exists: nothing to check, nothing to fail.
        $project = new TempProject(['README.md' => 'no code here']);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('(0 namespace edges, 0 known SCCs', $tester->getDisplay());
    }

    public function testStderrLessOutputStillReportsFailures(): void
    {
        $project = new TempProject(['src/Alpha/One.php' => $this->file('Alpha', 'One')]);

        $tester = $this->tester(['Gone>Edge']);
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('architecture-check: FAILED', $tester->getDisplay());
    }

    public function testAPluginsOwnClassesAreGradedUnderTheirOwnNamespace(): void
    {
        // The plugin's directory name says nothing; its composer.json psr-4 does.
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'plugins/graph-viewer/composer.json' => '{"autoload": {"psr-4": {"Porta\\\\GraphViewer\\\\": "src/"}}}',
            'plugins/graph-viewer/src/Artifact.php' => $this->file('GraphViewer', 'Artifact', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
        ]);

        $tester = $this->tester(['GraphViewer>Beta']);
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('(1 namespace edges', $tester->getDisplay());
    }

    public function testAPluginUnderItsOwnVendorPrefixKeepsIt(): void
    {
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'plugins/acme/composer.json' => '{"autoload": {"psr-4": {"Acme\\\\Tools\\\\": "lib"}}}',
            'plugins/acme/lib/Greeting.php' => "<?php\n\nnamespace Acme\\Tools;\n\nuse Porta\\Beta\\Two;\n\nfinal class Greeting\n{\n    public function f(): void { new Two(); }\n}\n",
        ]);

        $tester = $this->tester(['Acme>Beta']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testAPluginThatShipsNoClassesHasNoGraph(): void
    {
        // No manifest, one with no psr-4, a prefix pointing at a directory that
        // is not there, an empty prefix, a non-string prefix and directory, an
        // autoload that is not an object, and metadata that does not parse:
        // every way to ship only a plugin.php, none of them an error.
        $project = new TempProject([
            'plugins/wires-only/plugin.php' => "<?php\n",
            'plugins/no-namespace/composer.json' => '{"extra": {"porta": {"requires": ["wires-only"]}}}',
            'plugins/no-directory/composer.json' => '{"autoload": {"psr-4": {"Porta\\\\Absent\\\\": "src/"}}}',
            'plugins/empty-prefix/composer.json' => '{"autoload": {"psr-4": {"": "src/"}}}',
            'plugins/junk-entries/composer.json' => '{"autoload": {"psr-4": {"7": "src/", "A\\\\": 7}}}',
            'plugins/not-an-object/composer.json' => '{"autoload": {"psr-4": "nope"}}',
            'plugins/no-autoload/composer.json' => '{"autoload": "nope"}',
            'plugins/junk/composer.json' => 'not json at all',
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('(0 namespace edges', $tester->getDisplay());
    }

    public function testTheProductionDefaultsGradePortasOwnTree(): void
    {
        // No path argument, no pins: the real gate over the real repository —
        // the same assertion `composer arch` makes in CI.
        $tester = new CommandTester(new ArchitectureCheckCommand());
        $status = $tester->execute([]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('architecture-check: OK', $tester->getDisplay());
        $this->assertStringContainsString('3 known SCCs', $tester->getDisplay());
    }
}
