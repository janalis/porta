<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * Reads the report `--log-junit` writes, rather than the run's console
 * output: the console is for the reader and changes with every PHPUnit
 * release, while the log names each test's file and line.
 *
 * A data-provider method is a nested `<testsuite>` of its data sets, so every
 * `<testcase>` is collected wherever it sits.
 *
 * PHPUnit names files by their real path, and a project root opened through
 * a symlink — `/var` on macOS is one — is not spelled that way. Each file is
 * put back under $root as the root spells it, so the dock can show it
 * relative to the project.
 */
final class JunitReport
{
    /**
     * @return list<TestOutcome>
     *
     * @throws \RuntimeException when $xml is not a JUnit report
     */
    public static function parse(string $xml, string $root): array
    {
        $report = Xml::load($xml, 'testsuites', 'JUnit report');
        $real = \realpath($root);
        $outcomes = [];
        $sources = [];
        foreach (Xml::all($report, '//testcase') as $case) {
            $outcomes[] = self::outcome($case, $root, false === $real ? $root : $real, $sources);
        }

        return $outcomes;
    }

    /**
     * Pest's cases differ in three ways: `file` is relative to the project and
     * ends `::<the test's name>`, there is no `line` — the test is no method,
     * so its call is looked for in the file — and a failure ends
     * `at tests/X.php:8` rather than in a trace.
     *
     * @param array<string, array<string, int>> $sources Pest files read so far, by path: each test's line by name
     */
    private static function outcome(\SimpleXMLElement $case, string $root, string $real, array &$sources): TestOutcome
    {
        $name = (string) $case['name'];
        $file = \explode('::', (string) $case['file'], limit: 2)[0];
        $absolute = '' === $file || \str_starts_with($file, '/') ? $file : $real.'/'.$file;
        $local = \str_starts_with($absolute, $real.'/') ? $root.\substr($absolute, \strlen($real)) : $absolute;
        $method = \explode(' with data set ', $name, limit: 2)[0];
        $line = null === $case['line'] ? self::declared($absolute, $method, $sources) : \max(0, (int) $case['line'] - 1);
        $verdict = Xml::all($case, 'failure|error|skipped')[0] ?? null;
        $status = match ($verdict?->getName()) {
            'failure' => TestOutcome::FAILED,
            'error' => TestOutcome::ERRORED,
            'skipped' => TestOutcome::SKIPPED,
            default => TestOutcome::PASSED,
        };
        $detail = (string) $verdict;

        return new TestOutcome(
            (string) $case['class'],
            $method,
            $name,
            $local,
            $line,
            $status,
            self::message($detail, $name),
            self::at($detail, $file) ?? $line,
        );
    }

    /**
     * The 0-based line of the Pest test called $name in $file, 0 when it
     * cannot be found there.
     *
     * @param array<string, array<string, int>> $sources
     */
    private static function declared(string $file, string $name, array &$sources): int
    {
        // Read once per file, not per test: a report lists each of its tests.
        $sources[$file] ??= PestTests::lines(\is_file($file) ? (string) \file_get_contents($file) : '');

        return $sources[$file][$name] ?? 0;
    }

    /**
     * The first line of what went wrong. The detail opens with the test's own
     * name, which the row already shows — on a line of its own from PHPUnit,
     * run into the message from Pest — and ends with the trace.
     */
    private static function message(string $detail, string $name): string
    {
        $lines = \explode("\n", \trim($detail));
        $first = $lines[0];

        return \trim(\str_starts_with($first, $name) && $first !== $name ? \substr($first, \strlen($name)) : $lines[1] ?? '');
    }

    /**
     * The 0-based line of the first trace frame in the test file itself —
     * where the assertion is. Pest says `at <file>:<line>`.
     */
    private static function at(string $detail, string $file): ?int
    {
        $match = [];
        \preg_match('~^(?:at )?'.\preg_quote($file, delimiter: '~').':(\d+)$~m', $detail, $match);
        $at = $match[1] ?? null;

        return '' === $file || null === $at ? null : (int) $at - 1;
    }
}
