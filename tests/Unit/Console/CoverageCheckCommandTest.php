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
    /** A Clover report whose files carry the given [name => [covered, statements]]. */
    private function clover(array $files, int $totalCovered, int $totalStatements): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?><coverage generated="1"><project timestamp="1">';
        foreach ($files as $name => [$covered, $statements]) {
            $xml .= \sprintf('<file name="%s"><metrics statements="%d" coveredstatements="%d"/></file>', $name, $statements, $covered);
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
            ],
            totalCovered: 14,
            totalStatements: 14,
        )]);

        $tester = $this->tester();
        $status = $tester->execute(['clover' => $project->path('clover.xml'), 'threshold' => '100']);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Editor/Alpha.php', $display);
        $this->assertStringContainsString('Widget/Zebra.php', $display);
        $this->assertStringNotContainsString('Empty.php', $display, 'a file with no statements is not a coverage subject');
        $this->assertLessThan(
            strpos($display, needle: 'Widget/Zebra.php'),
            (int) strpos($display, needle: 'Editor/Alpha.php'),
            'rows sort by display name',
        );
        $this->assertStringContainsString('TOTAL: 100.00% (14/14) — required: 100.00%', $display);
        $this->assertStringContainsString('coverage-check: OK', $display);
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
        $before = libxml_use_internal_errors(false);

        $tester = $this->tester();
        // Not captured separately: the stderr-less path also has to work.
        $status = $tester->execute(['clover' => $project->path('broken.xml')]);

        $this->assertSame(Command::INVALID, $status);
        $this->assertStringContainsString('is not valid Clover XML', $tester->getDisplay());
        $this->assertFalse(libxml_use_internal_errors($before), 'the previous libxml setting was restored');
    }

    public function testTheDefaultArgumentsAreTheComposerScriptContract(): void
    {
        $command = new CoverageCheckCommand();
        $definition = $command->getDefinition();

        $this->assertSame('coverage:check', $command->getName());
        $this->assertSame('coverage/clover.xml', $definition->getArgument('clover')->getDefault());
        $this->assertSame('100', $definition->getArgument('threshold')->getDefault());
    }
}
