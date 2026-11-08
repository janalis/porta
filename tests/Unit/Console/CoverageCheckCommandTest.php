<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Porta\Console\CoverageCheckCommand;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The coverage gate's own gate: the exit-code contract CI reads (2 unusable
 * input, 1 shortfall, 0 pass) and the per-file table it prints.
 */
final class CoverageCheckCommandTest extends TestCase
{
    /**
     * A Clover report whose files carry the given [name => [covered, statements]].
     *
     * @param array<string, array<int, int>> $lines       name => [line number => hit count], for the
     *                                                     files whose `<line>` entries a test needs;
     *                                                     a report merged with another is graded from
     *                                                     these rather than from `<metrics>`
     * @param array<string, array<int, int>> $methodLines   the same for `type="method"` entries, whose
     *                                                     count is a MAX over the method's whole range
     *                                                     and so is never one line's truth
     */
    private function clover(array $files, int $totalCovered, int $totalStatements, array $lines = [], array $methodLines = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage generated="1"><project timestamp="1">';
        foreach ($files as $name => [$covered, $statements]) {
            $xml .= \sprintf('<file name="%s">', $name);
            foreach ($methodLines[$name] ?? [] as $number => $count) {
                $xml .= \sprintf('<line num="%d" type="method" count="%d"/>', $number, $count);
            }
            foreach ($lines[$name] ?? [] as $number => $count) {
                $xml .= \sprintf('<line num="%d" type="stmt" count="%d"/>', $number, $count);
            }
            $xml .= \sprintf('<metrics statements="%d" coveredstatements="%d"/></file>', $statements, $covered);
        }
        $xml .= \sprintf('<metrics statements="%d" coveredstatements="%d"/>', $totalStatements, $totalCovered);

        return $xml.'</project></coverage>';
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new CoverageCheckCommand());
    }

    public function testFullCoveragePassesAndPrintsThePerFileTable(): void
    {
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Widget/Zebra.php' => [4, 4],
                '/p/src/Editor/Alpha.php' => [10, 10],
                '/p/src/Empty.php' => [0, 0], // no executable statements: skipped
                '/p/plugins/graph-viewer/src/Artifact.php' => [6, 6],
            ],
            totalCovered: 20,
            totalStatements: 20,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Editor/Alpha.php', $display);
        $this->assertStringContainsString('Widget/Zebra.php', $display);
        $this->assertStringNotContainsString('Empty.php', $display, 'a file with no statements is not a coverage subject');
        $this->assertLessThan(
            \strpos($display, needle: 'Widget/Zebra.php'),
            (int) \strpos($display, needle: 'Editor/Alpha.php'),
            'rows sort by display name',
        );
        $this->assertStringContainsString(
            'graph-viewer/src/Artifact.php',
            $display,
            'plugin-owned code is named from its plugin: `src/Artifact.php` would read as core\'s',
        );
        $this->assertStringContainsString('TOTAL: 100.00% (20/20) — required: 100.00%', $display);
        $this->assertStringContainsString('coverage-check: OK', $display);
        $this->assertStringContainsString('File', $display, 'the header row names the file column');
        $this->assertStringContainsString('Coverage', $display, 'the header row names the coverage column');
        $this->assertStringNotContainsString('/graph-viewer', $display, 'the plugin path is fully stripped to `plugin/...`, not left with a leading slash or the raw prefix');
        $this->assertStringContainsString("\n\n  TOTAL", $display, 'a blank line separates the table from the total');
        $this->assertStringContainsString("\n\ncoverage-check: OK", $display, 'a blank line separates the total from the final status');
    }

    public function testPerFileRowsShowTheExactPercentageAndFraction(): void
    {
        // Two rows whose fractions differ from each other AND from the
        // combined total (4/12 = 33.33%), so an assertion on either row's
        // exact string can only match the per-row formula, not a total-line
        // coincidence. 100*3/8 = 37.50% exactly — chosen so a corrupted
        // formula (99*, a division swapped for the numerator, +1/-1, or
        // dropping the division entirely) all produce a visibly different
        // number.
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Partial.php' => [3, 8],
                '/p/src/Other.php' => [1, 4],
            ],
            totalCovered: 4,
            totalStatements: 12,
        )]);

        $tester = $this->tester();
        $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '0']);
        $display = $tester->getDisplay();

        $this->assertStringContainsString('37.50% (3/8)', $display, 'the per-row percentage is computed from the real map, not a corrupted formula');
        $this->assertStringContainsString('25.00% (1/4)', $display);
    }

    public function testAShortfallFailsWithTheThresholdMessage(): void
    {
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Half.php' => [1, 2],
            ],
            totalCovered: 1,
            totalStatements: 2,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100'], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('TOTAL: 50.00% (1/2)', $tester->getDisplay());
        $this->assertStringContainsString('below the 100% line-coverage gate', $tester->getErrorOutput(), 'the failure goes to stderr');
        $this->assertStringStartsWith("\ncoverage-check: FAILED", $tester->getErrorOutput(), 'a blank line opens the error stream, directly before the failure message');
    }

    /** A failing gate names the lines it missed, file by file — the log may be all a CI run leaves. */
    public function testAShortfallNamesTheMissedLines(): void
    {
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Whole.php' => [2, 2],
                '/p/src/Zeta/Half.php' => [2, 4],
                '/p/src/Alpha.php' => [1, 2],
            ],
            totalCovered: 5,
            totalStatements: 8,
            lines: [
                '/p/src/Whole.php' => [3 => 1, 4 => 2],
                '/p/src/Zeta/Half.php' => [12 => 0, 7 => 1, 9 => 0, 10 => 3],
                '/p/src/Alpha.php' => [5 => 0, 6 => 1],
            ],
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100'], ['capture_stderr_separately' => true]);
        $errors = $tester->getErrorOutput();

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString("  Zeta/Half.php: 9, 12\n  src/Alpha.php: 5\n", $errors, 'named as the table names them, sorted, lines ascending');
        $this->assertStringNotContainsString('Whole.php', $errors, 'a fully covered file is not listed');
    }

    public function testAThresholdBelowTheActualCoveragePasses(): void
    {
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Half.php' => [1, 2],
            ],
            totalCovered: 1,
            totalStatements: 2,
        )]);

        $status = $this->tester()->execute(['clover' => $project->path('clover.xml'), 'threshold' => '50']);

        $this->assertSame(Command::SUCCESS, $status, '50% meets a 50% gate — the 1e-9 epsilon absorbs float drift');
    }

    public function testTheEpsilonAllowsExactEqualityButNotLessThan(): void
    {
        // percentage + 1e-9 must be strictly LESS than the threshold to fail
        // — an exact match, down to the same epsilon, still passes.
        $percentage = 100 / 2; // 50.0, exact in IEEE754 — matches the command's own (100 * covered) / statements for covered=1, statements=2
        $thresholdAtTheBoundary = \sprintf('%.20f', $percentage + 1e-9);

        $project = new TempProject(['clover.xml' => $this->clover(
            ['/p/src/Half.php' => [1, 2]],
            totalCovered: 1,
            totalStatements: 2,
        )]);

        $status = $this->tester()->execute(['clover' => $project->path('clover.xml'), 'threshold' => $thresholdAtTheBoundary]);

        $this->assertSame(Command::SUCCESS, $status, 'strictly-less-than, not less-or-equal, at the exact epsilon boundary');
    }

    public function testANonNumericThresholdFallsBackToZero(): void
    {
        // A non-numeric threshold reads as 0.0 — the same tolerance the old
        // positional-argv script had — so any real coverage passes.
        $project = new TempProject(['clover.xml' => $this->clover(
            ['/p/src/Half.php' => [1, 2]],
            totalCovered: 1,
            totalStatements: 2,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => 'not-a-number']);

        $this->assertSame(Command::SUCCESS, $status, 'a 0% gate is trivially met');
        $this->assertStringContainsString('required: 0.00%', $tester->getDisplay(), 'the parsed threshold itself is 0.0, not some other fallback');
    }

    public function testAnEmptyReportIsUsableAndReportsZero(): void
    {
        // No files with statements: the table is empty (the old script crashed
        // here on max([])) and the project total divides by zero safely.
        $project = new TempProject(['clover.xml' => $this->clover([], totalCovered: 0, totalStatements: 0)]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '0']);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('TOTAL: 0.00% (0/0)', $tester->getDisplay());
    }

    public function testAMissingReportIsInvalidInput(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['clover' => '/nonexistent/clover.xml'], ['capture_stderr_separately' => true]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('not found — run `composer coverage` first', $tester->getErrorOutput());
    }

    public function testMalformedXmlIsInvalidInputAndLeavesLibxmlAsItFoundIt(): void
    {
        $project = new TempProject(['broken.xml' => '<coverage><project></coverage>']);
        $before = \libxml_use_internal_errors(false);

        $tester = $this->tester();
        // Not captured separately: the stderr-less path also has to work.
        $status = $tester->execute(['clover' => $project->path('broken.xml')]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('is not valid Clover XML', $tester->getDisplay());
        $this->assertFalse(\libxml_use_internal_errors($before), 'the previous libxml setting was restored');
    }

    public function testTheErrorBufferIsClearedEvenWhenTheCallerAlreadyHadInternalErrorsOn(): void
    {
        // Restoring the previous setting only clears PHP's own internal error
        // buffer as a side effect when that restore is an actual OFF->ON or
        // ON->OFF transition. When the caller already had internal errors ON
        // (so the restore is a no-op transition), only the explicit
        // libxml_clear_errors() call prevents this command's parse errors
        // from leaking into whatever libxml call runs next in the process.
        $before = \libxml_use_internal_errors(true);
        try {
            $project = new TempProject(['broken.xml' => '<coverage><project></coverage>']);
            $this->tester()->execute(['clover' => $project->path('broken.xml')]);

            $this->assertSame([], \libxml_get_errors(), 'the internal error buffer was cleared');
        } finally {
            \libxml_use_internal_errors($before);
            \libxml_clear_errors();
        }
    }

    public function testTheDefaultArgumentsAreTheComposerScriptContract(): void
    {
        $command = new CoverageCheckCommand();
        $definition = $command->getDefinition();

        $this->assertSame('coverage:check', $command->getName());
        $this->assertSame('coverage/clover.xml', $definition->getArgument('clover')->getDefault());
        $this->assertSame('100', $definition->getArgument('threshold')->getDefault());
    }

    public function testAPluginsVendoredCodeIsNotACoverageSubject(): void
    {
        // A plugin may ship its composer dependencies beside its own code, and
        // the plugin pass scopes by the `/plugins/` prefix — without the vendor
        // skip a third party's uncovered lines would fail our own gate.
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/plugins/demo/src/Tool.php' => [5, 5],
                '/p/plugins/demo/vendor/vend/lib/src/Helper.php' => [1, 40],
            ],
            totalCovered: 6,
            totalStatements: 45,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringNotContainsString('Helper.php', $display, 'the vendored file is not ours to cover');
        $this->assertStringContainsString('demo/src/Tool.php', $display);
        $this->assertStringContainsString(
            'TOTAL (/plugins/): 100.00% (5/5)',
            $display,
            'a scoped run sums the kept rows (5/5), not the report-wide totals (6/45, which count the vendored file)',
        );
    }

    public function testAFileTheReportNeverMentionsFailsEvenAtFullCoverage(): void
    {
        // The hole --require exists to close. Clover runs with
        // includeUncoveredFiles="false", so a file nothing loads is not a 0%
        // row — it is no row at all, and the percentage is computed from the
        // rows that ARE there. src/ cannot hit this (compiling the container
        // touches every file); plugin code loads only when a plugin activates,
        // which is exactly the guarantee a file moving INTO a plugin loses.
        $project = new TempProject([
            'plugins/demo/src/Graded.php' => "<?php\n",
            'plugins/demo/src/Ghost.php' => "<?php\n",
            // Hidden-name faithfulness: the walker this replaced saw dot
            // files, so the presence check must too.
            'plugins/demo/src/.Hidden.php' => "<?php\n",
        ]);
        $project->write('clover.xml', $this->clover(
            [(string) \realpath($project->path('plugins/demo/src/Graded.php')) => [5, 5]],
            totalCovered: 5,
            totalStatements: 5,
        ));

        $tester = $this->tester();
        $arguments = ['clover' => $project->path('clover.xml'), 'threshold' => '100', '--only' => '/plugins/'];

        $this->assertSame(Command::SUCCESS, $tester->execute($arguments), 'without --require the ghost is invisible');

        $status = $tester->execute([...$arguments, '--require' => $project->path('plugins')]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('100.00%', $display, 'the percentage was green the whole time — that is the point');
        $this->assertStringContainsString(
            "\n\ncoverage-check: FAILED — these files are absent",
            $display,
            'a blank line separates the total from the failure message',
        );
        // The graded file still appears in the table above; what matters is
        // which files the failure itself names.
        $named = \substr($display, offset: (int) \strpos($display, needle: 'absent from the report'));
        $this->assertStringContainsString('Ghost.php', $named, 'the ungraded file is named');
        $this->assertStringContainsString('.Hidden.php', $named, 'a dot-named file is a coverage subject too');
        $this->assertStringNotContainsString('Graded.php', $named, 'and the graded one is not');
        $this->assertStringContainsString(
            '  '.$project->path('plugins/demo/src/Ghost.php'),
            $named,
            'each entry is indented exactly two spaces before the path, not concatenated on the wrong side',
        );
    }

    public function testRequireStripsTheCurrentWorkingDirectoryPrefixFromAbsentPaths(): void
    {
        $project = new TempProject(['plugins/demo/src/Ghost.php' => "<?php\n"]);
        $project->write('clover.xml', $this->clover([], totalCovered: 0, totalStatements: 0));

        // Empty mayBeAbsent: the default excuse list is checked with
        // is_file() against the (about to change) cwd, and would otherwise
        // report every default entry as stale once we chdir below.
        //
        // --require must be ABSOLUTE here: Finder::in() with a relative
        // directory already returns relative pathnames, which would make
        // the str_replace()-based stripping a no-op with nothing to prove.
        // An absolute --require (which the option's contract permits, even
        // though the composer.json script always passes a relative one)
        // forces Finder to return absolute pathnames, so the cwd-prefix
        // stripping actually has something to strip.
        $tester = new CommandTester(new CoverageCheckCommand(mayBeAbsent: []));
        $originalCwd = (string) \getcwd();
        \chdir($project->dir);
        try {
            // realpath, not $project->path(): on macOS /tmp is itself a
            // symlink to /private/tmp, and getcwd() after chdir() reports
            // the resolved form — an unresolved --require would silently
            // mismatch $root on the /private prefix alone, unrelated to
            // any mutant.
            $status = $tester->execute([
                'clover' => 'clover.xml',
                'threshold' => '0',
                '--require' => (string) \realpath($project->path('plugins')),
            ]);
        } finally {
            \chdir($originalCwd);
        }
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $named = \substr($display, offset: (int) \strpos($display, needle: 'absent from the report'));
        $this->assertStringContainsString(
            '  plugins/demo/src/Ghost.php',
            $named,
            'the cwd prefix is stripped to a clean relative path — no leading slash or absolute prefix left over',
        );
    }

    public function testAFileInsideAVcsLikeDirectoryIsStillAGradingSubject(): void
    {
        // ignoreVCS(false): unlike Finder's default, a `.git`-named
        // directory's .php files are NOT silently skipped — matching the raw
        // walker this replaced, which had no VCS-awareness at all.
        $project = new TempProject(['plugins/demo/.git/Stray.php' => "<?php\n"]);
        $project->write('clover.xml', $this->clover([], totalCovered: 0, totalStatements: 0));

        $tester = $this->tester();
        $status = $tester->execute([
            'clover' => $project->path('clover.xml'),
            'threshold' => '0',
            '--require' => $project->path('plugins'),
        ]);

        $this->assertSame(Command::FAILURE, $status, 'a .git-named directory is still walked, so its ungraded file is a real gap');
        $this->assertStringContainsString('Stray.php', $tester->getDisplay());
    }

    public function testAReportWithNoProjectMetricsNodeDefaultsCleanlyToZero(): void
    {
        // No <project><metrics> element at all (not just a missing
        // attribute) — exercises element()'s "found but empty" branch and
        // number()'s "no metrics at all" branch together, both of which must
        // degrade to 0 without ever indexing into an empty/null value.
        $project = new TempProject(['broken-metrics.xml' => '<?xml version="1.0"?><coverage><project></project></coverage>']);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('broken-metrics.xml'), 'threshold' => '0']);

        $this->assertSame(Command::SUCCESS, $status, $tester->getDisplay());
        $this->assertStringContainsString('TOTAL: 0.00% (0/0)', $tester->getDisplay(), 'a missing metrics node reads as zero, not -1/1 or a warning');
    }

    public function testRequireIgnoresVendoredAndTestFilesAndAnAbsentDirectory(): void
    {
        // The same exclusions rows() makes: neither is a coverage subject, so
        // neither may fail the presence check either.
        $project = new TempProject([
            'plugins/demo/src/Graded.php' => "<?php\n",
            'plugins/demo/vendor/vend/lib/Helper.php' => "<?php\n",
            'plugins/demo/tests/DemoTest.php' => "<?php\n",
        ]);
        $project->write('clover.xml', $this->clover(
            [(string) \realpath($project->path('plugins/demo/src/Graded.php')) => [5, 5]],
            totalCovered: 5,
            totalStatements: 5,
        ));

        $tester = $this->tester();
        $arguments = ['clover' => $project->path('clover.xml'), 'threshold' => '100', '--only' => '/plugins/'];

        $this->assertSame(
            Command::SUCCESS,
            $tester->execute([...$arguments, '--require' => $project->path('plugins')]),
            $tester->getDisplay(),
        );
        $this->assertSame(
            Command::SUCCESS,
            $tester->execute([...$arguments, '--require' => $project->path('nowhere')]),
            'a directory that does not exist is not a gap',
        );
    }

    public function testAnExternalToolPluginEntryPointMayBeAbsent(): void
    {
        // Its tests skipWithoutBinary(), so on a machine without the tool
        // porta.php is never required and pcov has nothing to record. The list
        // permits that absence; it does not excuse a neighbour's.
        $project = new TempProject([
            'plugins/psalm/porta.php' => "<?php\n",
            'plugins/demo/porta.php' => "<?php\n",
        ]);
        $project->write('clover.xml', $this->clover([], totalCovered: 0, totalStatements: 0));

        $tester = $this->tester();
        $status = $tester->execute([
            'clover' => $project->path('clover.xml'),
            'threshold' => '0',
            '--require' => $project->path('plugins'),
        ]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('demo/porta.php', $display, 'an ordinary plugin entry point is still required');
        $this->assertStringNotContainsString('psalm', $display, 'the external-tool one is excused');
    }

    /**
     * A file that only declares — an interface, an enum of cases — has nothing
     * pcov can record, and is recorded only when it happens to be compiled
     * while coverage runs: absent from one run and present in the next, it
     * failed the gate on files nothing could cover. Its absence is no gap. A
     * method body, a property hook, a top-level call: those stay required.
     */
    public function testAFileThatOnlyDeclaresMayBeAbsentAndOneThatRunsCodeMayNot(): void
    {
        $project = new TempProject([
            'plugins/demo/src/Contract.php' => "<?php\ndeclare(strict_types=1);\nnamespace Demo;\n#[\\Attribute]\ninterface Contract { public function x(): void; }\n",
            'plugins/demo/src/Kind.php' => "<?php\nnamespace Demo;\nenum Kind: string { case A = 'a'; }\n",
            'plugins/demo/src/Shape.php' => "<?php\ndeclare(strict_types=1, ticks=1);\nnamespace Demo;\nuse Other\\Thing;\n#[Route('/x', methods: ['GET', ['nested']])]\nabstract class Shape implements \\Countable { public const array SIDES = [1, 2]; protected ?Thing \$thing = null; abstract public function area(): float; }\n",
            'plugins/demo/src/Nothing.php' => "<?php\nnamespace Demo;\n",
            'plugins/demo/src/Returned.php' => "<?php\nnamespace Demo;\nfinal class Returned {}\nreturn 1;\n",
            'plugins/demo/src/Doer.php' => "<?php\nnamespace Demo;\nfinal class Doer { public function x(): int { return 1; } }\n",
            'plugins/demo/src/Hooked.php' => "<?php\nnamespace Demo;\nfinal class Hooked { public int \$x { get => 1; } }\n",
            'plugins/demo/src/Aliased.php' => "<?php\nnamespace Demo;\nfinal class Aliased {}\n\\class_alias(Aliased::class, 'Other');\n",
        ]);
        $project->write('clover.xml', $this->clover([], totalCovered: 0, totalStatements: 0));

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '0', '--require' => $project->path('plugins')]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        foreach (['Doer.php', 'Hooked.php', 'Aliased.php', 'Nothing.php', 'Returned.php'] as $runs) {
            $this->assertStringContainsString($runs, $display, $runs.' runs code, or declares no type: its absence is a gap');
        }
        foreach (['Contract.php', 'Kind.php', 'Shape.php'] as $declares) {
            $this->assertStringNotContainsString($declares, $display, $declares.' only declares');
        }
    }

    public function testADeadMayBeAbsentEntryFailsTheGate(): void
    {
        // The arch gate's both-directions doctrine, applied to this allowlist:
        // an excuse whose file is gone from the tree is dead config. (Presence
        // in the report is NOT staleness — a machine without the wrapped
        // binary still needs the excuse.)
        $project = new TempProject(['plugins/demo/src/Graded.php' => "<?php\n"]);
        $project->write('clover.xml', $this->clover(
            [(string) \realpath($project->path('plugins/demo/src/Graded.php')) => [5, 5]],
            totalCovered: 5,
            totalStatements: 5,
        ));

        $tester = new CommandTester(new CoverageCheckCommand(mayBeAbsent: ['plugins/ghost/porta.php']));
        $status = $tester->execute([
            'clover' => $project->path('clover.xml'),
            'threshold' => '100',
            '--only' => '/plugins/',
            '--require' => $project->path('plugins'),
        ]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('no longer exist', $display);
        $this->assertStringContainsString('plugins/ghost/porta.php', $display, 'the dead entry is named');
        $this->assertStringContainsString(
            "\n\ncoverage-check: FAILED — MAY_BE_ABSENT excuses",
            $display,
            'a blank line separates the total from the failure message',
        );
        $this->assertStringContainsString(
            '  plugins/ghost/porta.php',
            $display,
            'the entry is indented exactly two spaces before the path, not concatenated on the wrong side',
        );
    }

    public function testOneReportUnscopedIsGradedByItsOwnTotals(): void
    {
        // The two totals differ exactly where the rows skip something the
        // report counted: a vendored file. That is why `composer coverage`
        // excludes vendor at the PCOV level rather than leaving it to this —
        // a report carrying third-party lines would dilute an unscoped total,
        // and the gate would pass on someone else's code.
        $project = new TempProject(['clover.xml' => $this->clover(
            [
                '/p/src/Widget/Zebra.php' => [4, 4],
                '/p/vendor/acme/Lib.php' => [1, 6], // skipped as a ROW, counted in the totals
            ],
            totalCovered: 5,
            totalStatements: 10,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100']);
        $display = $tester->getDisplay();

        $this->assertStringNotContainsString('Lib.php', $display, 'a vendored file is never a row');
        $this->assertStringContainsString('TOTAL: 50.00% (5/10)', $display, "and the report's own totals are what an unscoped run grades");
        $this->assertSame(Command::FAILURE, $status);
    }

    public function testTwoReportsAreGradedTogether(): void
    {
        // The sharded plugin pass: several serial runs, one report each, and
        // nothing merges them until here.
        $project = new TempProject([
            'shards/a.xml' => $this->clover(['/p/plugins/git/src/Blame.php' => [6, 6]], totalCovered: 6, totalStatements: 6),
            'shards/b.xml' => $this->clover(['/p/plugins/todo/src/Scan.php' => [4, 4]], totalCovered: 4, totalStatements: 4),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '100', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringContainsString('git/src/Blame.php', $display);
        $this->assertStringContainsString('todo/src/Scan.php', $display);
        $this->assertStringContainsString('TOTAL (/plugins/): 100.00% (10/10)', $display, 'both reports, summed once each');
    }

    public function testAFileTwoReportsBothCarryIsCountedOnceAtTheUnionOfItsLines(): void
    {
        // Booting an IDE loads every default-enabled plugin, so a shard's
        // report holds files another shard also covered. Summing the two rows
        // would grade this file 8/8 statements when it has 4; taking either
        // row alone would grade it 2/4 and fail a file that IS fully covered.
        $file = '/p/plugins/tempest/porta.php';
        $project = new TempProject([
            // The method entry is noise the union must ignore: counting it would
            // make this file's line map disagree with its own metrics.
            'shards/a.xml' => $this->clover([$file => [2, 4]], totalCovered: 2, totalStatements: 4, lines: [$file => [10 => 1, 11 => 3, 12 => 0, 13 => 0]], methodLines: [$file => [9 => 3]]),
            'shards/b.xml' => $this->clover([$file => [2, 4]], totalCovered: 2, totalStatements: 4, lines: [$file => [10 => 0, 11 => 0, 12 => 5, 13 => 1]]),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '100', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringContainsString('TOTAL (/plugins/): 100.00% (4/4)', $display, 'one file, four statements, covered by the two runs between them');
        $this->assertStringContainsString('100.00% (4/4)', $display);
    }

    public function testAFileSplitAcrossReportsWhoseLinesCannotRebuildItsMetricsIsInvalid(): void
    {
        // Clover writes a method's declaration line as type="method" with a
        // count that is the MAX over the method's range, and omits the stmt
        // entry for it — so when that line is itself executable, neither the
        // statement nor its count survives. Merging on such a file would grade
        // a shrunken denominator, which is the one direction 100% cannot see.
        $file = '/p/plugins/git/src/Blame.php';
        $project = new TempProject([
            'shards/a.xml' => $this->clover([$file => [3, 4]], totalCovered: 3, totalStatements: 4, lines: [$file => [10 => 1, 11 => 1, 12 => 1]]),
            'shards/b.xml' => $this->clover([$file => [1, 4]], totalCovered: 1, totalStatements: 4, lines: [$file => [10 => 0, 11 => 0, 12 => 1]]),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '100', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::INVALID, $status, $display);
        $this->assertStringContainsString('cannot merge them losslessly', $display);
        $this->assertStringContainsString($file, $display, 'the file is named, or nobody can act on it');
    }

    public function testADirectoryWithNoReportInItIsInvalidInput(): void
    {
        $project = new TempProject(['shards/README.md' => "not a report\n"]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '100']);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('holds no *.xml report', $tester->getDisplay(), 'an empty shard directory is not a green gate');
    }

    public function testADirectoryHoldingOneReportGradesExactlyLikeThatFile(): void
    {
        // The no-regression pin: the directory form must not be a second,
        // subtly different gate.
        $files = ['/p/plugins/git/src/Blame.php' => [3, 4], '/p/plugins/todo/src/Scan.php' => [4, 4]];
        $report = $this->clover($files, totalCovered: 7, totalStatements: 8);
        $project = new TempProject(['clover.xml' => $report, 'shards/only.xml' => $report]);

        $direct = $this->tester();
        $direct->execute(['clover' => $project->path('clover.xml'), 'threshold' => '80', '--only' => '/plugins/']);
        $viaDirectory = $this->tester();
        $viaDirectory->execute(['clover' => $project->path('shards'), 'threshold' => '80', '--only' => '/plugins/']);

        $this->assertSame($direct->getDisplay(), $viaDirectory->getDisplay());
    }

    public function testRequireIsSatisfiedByAFilePresentInOnlyOneReport(): void
    {
        // --require asks whether ANY report loaded the file, so it reads the
        // union unscoped: a shard that never touched a plugin says nothing
        // about it, and the shard that did is the answer.
        $project = new TempProject([
            'plugins/demo/src/Graded.php' => "<?php\n",
            'plugins/demo/src/Other.php' => "<?php\n",
        ]);
        $graded = (string) \realpath($project->path('plugins/demo/src/Graded.php'));
        $other = (string) \realpath($project->path('plugins/demo/src/Other.php'));
        $project->write('shards/a.xml', $this->clover([$graded => [5, 5]], totalCovered: 5, totalStatements: 5));
        $project->write('shards/b.xml', $this->clover([$other => [2, 2]], totalCovered: 2, totalStatements: 2));

        $tester = $this->tester();
        $status = $tester->execute([
            'clover' => $project->path('shards'),
            'threshold' => '100',
            '--only' => '/plugins/',
            '--require' => $project->path('plugins'),
        ]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status, $display);
        $this->assertStringNotContainsString('absent from the report', $display, 'each file is in one report, and one is enough');
    }

    public function testAStatementNoReportExecutedStaysUncoveredInTheUnion(): void
    {
        $file = '/p/plugins/git/src/Blame.php';
        $project = new TempProject([
            'shards/a.xml' => $this->clover([$file => [1, 4]], totalCovered: 1, totalStatements: 4, lines: [$file => [10 => 2, 11 => 0, 12 => 0, 13 => 0]]),
            'shards/b.xml' => $this->clover([$file => [2, 4]], totalCovered: 2, totalStatements: 4, lines: [$file => [10 => 0, 11 => 1, 12 => 4, 13 => 0]]),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '80', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::FAILURE, $status, $display);
        $this->assertStringContainsString('TOTAL (/plugins/): 75.00% (3/4)', $display, 'line 13 was executed by neither run, and a union of misses is still a miss');
    }

    public function testReportsThatDisagreeAboutWhichLinesAreStatementsAreInvalid(): void
    {
        // Same file, same statement COUNT, different line numbers — so the two
        // maps are not the same file's map, and a union over them would report
        // more covered lines than the file has statements.
        $file = '/p/plugins/git/src/Blame.php';
        $project = new TempProject([
            'shards/a.xml' => $this->clover([$file => [2, 2]], totalCovered: 2, totalStatements: 2, lines: [$file => [10 => 1, 11 => 1]]),
            'shards/b.xml' => $this->clover([$file => [2, 2]], totalCovered: 2, totalStatements: 2, lines: [$file => [20 => 1, 21 => 1]]),
        ]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('shards'), 'threshold' => '100', '--only' => '/plugins/']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::INVALID, $status, $display);
        $this->assertStringContainsString('cannot merge them losslessly', $display);
    }
}
