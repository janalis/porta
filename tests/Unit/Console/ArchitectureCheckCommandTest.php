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
        $this->assertStringContainsString('Made by: Porta\\Alpha\\One uses Porta\\Beta\\Two', $errors, 'the new edge names the import to look at');
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
        // AaFineTest sits exactly AT the cap and sorts first: the boundary
        // must pass, and grading must WALK PAST it to reach the oversized one.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'tests/E2e/AaFineTest.php' => "<?php\n".\str_repeat("    public function testMany(): void {}\n", times: 60),
            'tests/E2e/HugeTest.php' => "<?php\n".\str_repeat("    public function testMany(): void {}\n", times: 61),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('HugeTest.php has 61 test methods (max 60)', $tester->getErrorOutput(), 'the failure names the file');
        $this->assertStringNotContainsString('AaFineTest', $tester->getErrorOutput(), 'exactly at the cap is not over it');
    }

    public function testAnInlineTempProjectTemporaryFails(): void
    {
        // The bad line chains off the temporary; the comment NAMES the trap
        // (allowed — prose must be able to warn about it) and the held form
        // is the fix. Only the chain trips the ratchet, with its line number.
        // The pattern is spelled split ('TempProj'.'ect') so the ratchet,
        // which scans THIS file too, only sees it inside the written fixture.
        $chain = 'new TempProject';
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'tests/Unit/LeakyTest.php' => "<?php\n// an inline {$chain}(...)->dir frees the fixture\n\$held = {$chain}([]);\n\$read = \$held->dir; // beware {$chain}([])->dir here too\n\$leak = {$chain}([])->dir;\n",
            'plugins/demo/tests/AlsoLeakyTest.php' => "<?php\n\$leak = {$chain}()->path('x');\n",
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString(
            'LeakyTest.php:5 chains off an inline `new TempProject(...)`',
            $tester->getErrorOutput(),
            'the failure names the file and line',
        );
        // Anchored, not incidental: without these, dropping the comment-skip
        // would only fail via whatever comment-mentions the REAL tree happens
        // to carry that day.
        $this->assertStringNotContainsString('Unit/LeakyTest.php:2', $tester->getErrorOutput(), 'a whole-line comment naming the trap is not flagged');
        $this->assertStringNotContainsString('Unit/LeakyTest.php:3', $tester->getErrorOutput(), 'the held construction is not flagged');
        $this->assertStringNotContainsString(
            'Unit/LeakyTest.php:4',
            $tester->getErrorOutput(),
            'a TRAILING comment naming the trap beside real code is not flagged either — the exemption is the tokenizer, not a line prefix',
        );
        $this->assertStringContainsString('AlsoLeakyTest.php:2 chains off', $tester->getErrorOutput(), 'plugin test dirs are scanned too');
    }

    public function testPrivateReflectionOutsideTestsSupportFails(): void
    {
        // Spelled split for the same reason as the TempProject fixture: the
        // ratchet scans this file too. Line 2 is a comment naming the form,
        // line 3 a public signature read, lines 4-6 the three private reads.
        $new = 'new \\Reflection';
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'tests/Unit/PeekTest.php' =>
                "<?php\n// {$new}Property(\$o, 'x') is what Probe replaces\n\$p = {$new}Method(\$c, 'm')->getParameters();\n\$v = {$new}Property(\$o, 'x')->getValue(\$o);\n\$k = {$new}Class(\$c)->getConst"
                    .'ant(\'K\');'
                    ."\n\$q = {$new}ClassConstant(\$c, 'K')->getValue();\n",
            'tests/Support/Blessed.php' => "<?php\n\$v = {$new}Property(\$o, 'x')->getValue(\$o);\n",
            'plugins/demo/tests/PeekTest.php' => "<?php\n\$m = {$new}Method(\$c, 'm');\n",
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);
        $errors = $tester->getErrorOutput();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Unit/PeekTest.php:4 reflects private state outside tests/Support', $errors, 'a private property read names its file and line');
        $this->assertStringContainsString('Unit/PeekTest.php:5 reflects', $errors, 'a constant read through the class is caught');
        $this->assertStringContainsString('Unit/PeekTest.php:6 reflects', $errors, 'a constant reflected directly is caught');
        $this->assertStringContainsString('demo/tests/PeekTest.php:2 reflects', $errors, 'plugin test dirs are scanned too');
        $this->assertStringNotContainsString('Unit/PeekTest.php:2', $errors, 'a comment naming the form is not flagged');
        $this->assertStringNotContainsString('Unit/PeekTest.php:3', $errors, 'a public signature read is not flagged');
        $this->assertStringNotContainsString('Blessed.php', $errors, 'tests/Support is the blessed seam');
    }

    public function testADotNamedFileIsStillScanned(): void
    {
        // ignoreDotFiles(false)/ignoreVCS(false) is deliberate faithfulness to
        // the raw walker this replaced: a hidden file's imports are edges too.
        // The edge is pinned, so BOTH directions prove the file was read —
        // skipping it would fail as a vanished edge.
        $project = new TempProject([
            'src/Alpha/.Hidden.php' => $this->file('Alpha', 'Hidden', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
        ]);

        $tester = $this->tester(['Alpha>Beta']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testAnOversizedPluginSuiteFailsAndAttributedTestsCount(): void
    {
        // The documented migration path — a plugin-driving E2e belongs under
        // plugins/<name>/tests — must not be the ratchet's blind spot, and a
        // #[Test]-attributed method must not silently zero a file's count.
        $methods = \str_repeat("    public function testMany(): void {}\n", times: 60)."    #[Test]\n    public function attributedStyle(): void {}\n";
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'plugins/thing/tests/HugeTest.php' => "<?php\n".$methods,
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
        // every way to ship only a porta.php, none of them an error.
        $project = new TempProject([
            'plugins/wires-only/porta.php' => "<?php\n",
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

    public function testAPluginPrefixThatIsPureBackslashesIsSkippedNotJustEmpty(): void
    {
        // The raw prefix is "\" (one backslash) — not the empty string, but
        // trim()med to it. Without the trim, the guard's own '' === $prefix
        // check misses it, and the "bad" dir (which is real and has a class)
        // gets scanned under a near-empty prefix, producing a bogus edge.
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'plugins/acme/composer.json' => '{"autoload": {"psr-4": {"\\\\": "bad"}}}',
            'plugins/acme/bad/Ghost.php' => "<?php\n\nuse Porta\\Beta\\Two;\n\nfinal class Ghost\n{\n    public function f(): void { new Two(); }\n}\n",
        ]);

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testAPluginManifestsInvalidPrefixEntryIsSkippedNotAbortingTheRest(): void
    {
        // Two psr-4 entries in one manifest: the first prefix is backslash-only
        // (invalid, trim()'d to ''), the second is a real one. `continue` skips
        // only the bad entry; `break` would abort the whole foreach and the
        // second, valid entry would never be scanned.
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'plugins/acme/composer.json' => '{"autoload": {"psr-4": {"\\\\": "bad", "Acme\\\\": "lib"}}}',
            'plugins/acme/lib/Greeting.php' => "<?php\n\nnamespace Acme;\n\nuse Porta\\Beta\\Two;\n\nfinal class Greeting\n{\n    public function f(): void { new Two(); }\n}\n",
        ]);

        $tester = $this->tester(['Acme>Beta']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testAPluginManifestsInvalidRelativeEntryIsSkippedNotAbortingTheRest(): void
    {
        // One prefix mapping to TWO relative dirs: the first is a non-string
        // (invalid), the second is real. `continue` skips only the bad one;
        // `break` would abort before the real one is ever tried.
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            'plugins/acme/composer.json' => '{"autoload": {"psr-4": {"Acme\\\\": [7, "lib"]}}}',
            'plugins/acme/lib/Greeting.php' => "<?php\n\nnamespace Acme;\n\nuse Porta\\Beta\\Two;\n\nfinal class Greeting\n{\n    public function f(): void { new Two(); }\n}\n",
        ]);

        $tester = $this->tester(['Acme>Beta']);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testNamespaceEdgesAreReportedInSortedOrder(): void
    {
        // Two edges whose insertion order (Zulu's file scans before Alpha's,
        // alphabetically) is the REVERSE of sorted order — the failure list
        // must still name the vanished-edge line in sorted order, proving
        // sort() ran rather than just relying on dict insertion order.
        $project = new TempProject([
            'src/Zulu/One.php' => $this->file('Zulu', 'One', '    public function f(): void { new Two(); }', "use Porta\\Alpha\\Two;\n"),
            'src/Alpha/Two.php' => $this->file('Alpha', 'Two', '    public function g(): void { new One(); }', "use Porta\\Beta\\One;\n"),
            'src/Beta/One.php' => $this->file('Beta', 'One'),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        // If unsorted, "NEW edge Zulu>Alpha" would list before "NEW edge
        // Alpha>Beta" — assert the reverse (sorted) ordering in the raw output.
        $errors = $tester->getErrorOutput();
        $this->assertLessThan(\strpos($errors, needle: 'NEW edge Zulu>Alpha'), \strpos($errors, needle: 'NEW edge Alpha>Beta'), 'sorted order: Alpha before Zulu');
    }

    public function testBothANewCircularDependencyAndAVanishedPinnedOneFailTogether(): void
    {
        // Two simultaneous cycle failures: a fresh unpinned SCC, plus a pinned
        // SCC that no longer exists. Both must be listed — proves the loop over
        // $sccs doesn't stop after the first non-matching entry (continue, not
        // break) and that the failures array isn't truncated to one entry.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two', '    public function g(): void { new One(); }', "use Porta\\Alpha\\One;\n"),
        ]);
        $edges = ['Alpha>Beta', 'Beta>Alpha'];
        $vanished = ['Porta\Gamma\Ghost', 'Porta\Delta\Ghost'];

        $tester = $this->tester($edges, [$vanished]);
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);
        $errors = $tester->getErrorOutput();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('new circular dependency — Porta\Alpha\One <-> Porta\Beta\Two (re-opening acyclicity needs new evidence, recorded in docs/internals/decisions.md)', $errors);
        $this->assertStringContainsString('classes: allowed SCC {Porta\Gamma\Ghost, Porta\Delta\Ghost} no longer exists — remove it from ALLOWED_SCCS', $errors);
    }

    public function testTwoPinnedSccsOnlyTheFirstMatchingProvesTheVanishedCheckLoopKeepsGoing(): void
    {
        // Two pins, one real SCC: the FIRST pin matches (must `continue`, not
        // stop), the SECOND does not (a vanished pin that must still be
        // reported). If the "is this pin still real" loop broke on the first
        // match instead of continuing past it, the vanished second pin would
        // never be reached or reported.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two', '    public function g(): void { new One(); }', "use Porta\\Alpha\\One;\n"),
        ]);
        $edges = ['Alpha>Beta', 'Beta>Alpha'];
        $realCycle = ['Porta\Alpha\One', 'Porta\Beta\Two'];
        $ghostCycle = ['Porta\Gamma\Ghost', 'Porta\Delta\Ghost'];

        $tester = $this->tester($edges, [$realCycle, $ghostCycle]);
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('classes: allowed SCC {Porta\Gamma\Ghost, Porta\Delta\Ghost} no longer exists', $tester->getErrorOutput());
    }

    public function testTwoRealCyclesOnlyTheFirstPinnedProvesTheNewCycleCheckLoopKeepsGoing(): void
    {
        // Two independent real cycles: the first is pinned (must `continue`,
        // not stop), the second is not (a new, unpinned cycle that must still
        // be reported). If the "is this a new cycle" loop broke on the first
        // pinned match instead of continuing, the second, genuinely new cycle
        // would never be reached or reported.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two', '    public function g(): void { new One(); }', "use Porta\\Alpha\\One;\n"),
            'src/Gamma/Three.php' => $this->file('Gamma', 'Three', '    public function h(): void { new Four(); }', "use Porta\\Delta\\Four;\n"),
            'src/Delta/Four.php' => $this->file('Delta', 'Four', '    public function i(): void { new Three(); }', "use Porta\\Gamma\\Three;\n"),
        ]);
        $edges = ['Alpha>Beta', 'Beta>Alpha', 'Gamma>Delta', 'Delta>Gamma'];
        $pinned = ['Porta\Alpha\One', 'Porta\Beta\Two'];

        $tester = $this->tester($edges, [$pinned]);
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('new circular dependency — Porta\Delta\Four <-> Porta\Gamma\Three', $tester->getErrorOutput());
    }

    public function testTwoOversizedSuitesAreBothReported(): void
    {
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'tests/E2e/AaHugeTest.php' => "<?php\n".\str_repeat("    public function testMany(): void {}\n", times: 61),
            'tests/E2e/ZzHugeTest.php' => "<?php\n".\str_repeat("    public function testMany(): void {}\n", times: 61),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir], ['capture_stderr_separately' => true]);
        $errors = $tester->getErrorOutput();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('AaHugeTest.php has 61', $errors);
        $this->assertStringContainsString('ZzHugeTest.php has 61', $errors);
        // Explicit pathname sort, not filesystem enumeration order (which
        // varies) — the gate's own docblock calls this a byte contract.
        $this->assertLessThan(\strpos($errors, needle: 'ZzHugeTest.php'), \strpos($errors, needle: 'AaHugeTest.php'), 'the failure list is in sorted pathname order');
    }

    public function testPluginTestDirsOnlyGlobsTestsSubdirectoriesNotThePluginRoot(): void
    {
        // A file OUTSIDE any tests/ dir, with enough test-shaped method names
        // to trip the size cap if it were scanned. If pluginTestDirs() globbed
        // the plugins root instead of `*/tests`, Finder's own recursion would
        // still find and wrongly grade this src file.
        $methods = \str_repeat("    public function testMany(): void {}\n", times: 61);
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One'),
            'plugins/thing/src/NotATest.php' => "<?php\n".$methods,
        ]);

        $tester = $this->tester();

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
    }

    public function testALeafImportWithNoFileOfItsOwnDoesNotCrashTheCycleScan(): void
    {
        // `new Two()` names an import that has no source file of its own (an
        // interface or a plugin's own vendor class, never scanned) — the
        // leaf-import branch must still mark it visited and move on rather
        // than treating it as newly-discoverable or a stack member.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
        ]);

        $tester = $this->tester(['Alpha>Beta']);
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('(1 namespace edges, 0 known SCCs', $tester->getDisplay());
    }

    public function testAThreeClassCycleIsDetectedNotJustAdjacentPairs(): void
    {
        // A -> B -> C -> A: with the onStack lookup defaulting to true instead
        // of false, every DFS-discovered (non-leaf) neighbour would be treated
        // as still on the stack, over-merging components; a real 3-cycle is the
        // simplest fixture where over- and under-merging diverge from the
        // correct single-SCC answer.
        $project = new TempProject([
            'src/Alpha/One.php' => $this->file('Alpha', 'One', '    public function f(): void { new Two(); }', "use Porta\\Beta\\Two;\n"),
            'src/Beta/Two.php' => $this->file('Beta', 'Two', '    public function g(): void { new Three(); }', "use Porta\\Gamma\\Three;\n"),
            'src/Gamma/Three.php' => $this->file('Gamma', 'Three', '    public function h(): void { new One(); }', "use Porta\\Alpha\\One;\n"),
        ]);
        $edges = ['Alpha>Beta', 'Beta>Gamma', 'Gamma>Alpha'];
        $cycle = ['Porta\Alpha\One', 'Porta\Beta\Two', 'Porta\Gamma\Three'];

        $tester = $this->tester($edges, [$cycle]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['path' => $project->dir]), $tester->getDisplay());
        $this->assertStringContainsString('1 known SCCs', $tester->getDisplay());
    }

    public function testFilesAreScannedInVcsIgnoredDirectoriesToo(): void
    {
        // ignoreVCS(false) is deliberate (see the class docblock on phpFiles):
        // a .git-named directory under the scanned tree must still be walked.
        // The IMPORTING class lives under .git/ itself, so its own edge only
        // shows up at all if that file was scanned — a class it imports being
        // there too would form the edge regardless of VCS-walking.
        $project = new TempProject([
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
            // The scanner derives its FQCN from the PATH, not this declaration
            // (a namespace segment can't syntactically start with a dot).
            'src/.git/Ghost.php' => "<?php\n\nnamespace Porta\\GitDir;\n\nuse Porta\\Beta\\Two;\n\nfinal class Ghost\n{\n    public function f(): void { new Two(); }\n}\n",
        ]);

        $tester = $this->tester(['.git>Beta']);
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('(1 namespace edges', $tester->getDisplay());
    }

    public function testADocCommentIsStrippedTheSameAsARegularComment(): void
    {
        // T_DOC_COMMENT (/** */) must be stripped exactly like T_COMMENT (// or
        // /* */) before an import is checked for real use — otherwise a
        // docblock-only {@see Two} reference would count as a code edge.
        $project = new TempProject([
            'src/Alpha/One.php' => "<?php\n\nnamespace Porta\\Alpha;\n\nuse Porta\\Beta\\Two;\n\n/**\n * Prose about Two.\n */\nfinal class One\n{\n}\n",
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, 'the /** */ reference forms no edge');
        $this->assertStringContainsString('(0 namespace edges', $tester->getDisplay());
    }

    public function testAPlainCommentIsAlsoStrippedNotJustADocComment(): void
    {
        // T_COMMENT (// and single-star /* */) is a DIFFERENT token type than
        // T_DOC_COMMENT (/** */) — both must be stripped, checked here with a
        // reference that appears ONLY in a plain `//` line comment.
        $project = new TempProject([
            'src/Alpha/One.php' => "<?php\n\nnamespace Porta\\Alpha;\n\nuse Porta\\Beta\\Two;\n\n// Prose about Two, not real code.\nfinal class One\n{\n}\n",
            'src/Beta/Two.php' => $this->file('Beta', 'Two'),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['path' => $project->dir]);

        $this->assertSame(Command::SUCCESS, $status, 'the // comment reference forms no edge');
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
        $this->assertStringContainsString('1 known SCCs', $tester->getDisplay());
    }
}
