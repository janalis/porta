<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Graph;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\DotDiagnostics;

/** dot's reports on a source, read as the editor's diagnostics. */
final class DotDiagnosticsTest extends TestCase
{
    public function testAReportIsAnErrorOnItsLineAtTheTokenItNames(): void
    {
        $dot = "digraph G {\n  a -> ;\n}";

        $this->assertSame(
            [['line' => 1, 'message' => "syntax error near ';'", 'severity' => 1, 'column' => 7]],
            DotDiagnostics::parse("Error: <stdin>: syntax error in line 2 near ';'\n", $dot),
        );
    }

    public function testATokenTheLineDoesNotHoldStartsAtTheLineAndAWarningIsAWarning(): void
    {
        $this->assertSame(
            [['line' => 0, 'message' => "syntax error near '}'", 'severity' => 2, 'column' => 0]],
            DotDiagnostics::parse("Warning: <stdin>: syntax error in line 1 near '}'", 'digraph G {'),
        );
    }

    public function testAnErrorPastTheEndLandsOnTheLastLine(): void
    {
        // An unclosed brace: dot reports the line after the last.
        $errors = DotDiagnostics::parse("Error: <stdin>: syntax error in line 9\n", "digraph G {\n  a -> b;");

        $this->assertSame(1, $errors[0]['line']);
        $this->assertSame('syntax error', $errors[0]['message']);
    }

    public function testWhatNamesNoLineIsNotTheSources(): void
    {
        $this->assertSame([], DotDiagnostics::parse('graphviz not found', 'digraph G {}'));
        $this->assertSame([], DotDiagnostics::parse('Warning: using box for unknown shape nosuch', 'digraph G {}'));
        $this->assertSame([], DotDiagnostics::parse('dot produced invalid JSON: line 3', 'digraph G {}'), 'not a dot report');
    }

    public function testEveryReportCounts(): void
    {
        $errors = DotDiagnostics::parse("Warning: <stdin>: something in line 1\nError: <stdin>: syntax error in line 2 near 'b'\nString starting: \"x", "a\nb");

        $this->assertSame([[0, 2, 0], [1, 1, 0]], \array_map(static fn (array $e): array => [$e['line'], $e['severity'], $e['column']], $errors));
    }
}
