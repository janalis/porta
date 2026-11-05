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
        $previous = \libxml_use_internal_errors(true);
        $report = \simplexml_load_string($xml, options: \LIBXML_NONET);
        \libxml_clear_errors();
        \libxml_use_internal_errors($previous);
        if (false === $report || 'testsuites' !== $report->getName()) {
            throw new \RuntimeException('Not a JUnit report');
        }
        $real = \realpath($root);
        $outcomes = [];
        foreach (self::all($report, '//testcase') as $case) {
            $outcomes[] = self::outcome($case, $root, false === $real ? $root : $real);
        }

        return $outcomes;
    }

    private static function outcome(\SimpleXMLElement $case, string $root, string $real): TestOutcome
    {
        $name = (string) $case['name'];
        $file = (string) $case['file'];
        $local = \str_starts_with($file, $real.'/') ? $root.\substr($file, \strlen($real)) : $file;
        $line = \max(0, (int) $case['line'] - 1);
        $verdict = self::all($case, 'failure|error|skipped')[0] ?? null;
        $status = match ($verdict?->getName()) {
            'failure' => TestOutcome::FAILED,
            'error' => TestOutcome::ERRORED,
            'skipped' => TestOutcome::SKIPPED,
            default => TestOutcome::PASSED,
        };
        $detail = (string) $verdict;

        return new TestOutcome(
            (string) $case['class'],
            \explode(' with data set ', $name, limit: 2)[0],
            $name,
            $local,
            $line,
            $status,
            self::message($detail),
            self::at($detail, $file) ?? $line,
        );
    }

    /**
     * The first line of what went wrong. The detail opens with the test's own
     * name, which the row already shows, and ends with the trace.
     */
    private static function message(string $detail): string
    {
        $lines = \explode("\n", \trim($detail));

        return \trim($lines[1] ?? '');
    }

    /** The 0-based line of the first trace frame in the test file itself — where the assertion is. */
    private static function at(string $detail, string $file): ?int
    {
        $match = [];
        \preg_match('~^'.\preg_quote($file, delimiter: '~').':(\d+)$~m', $detail, $match);
        $at = $match[1] ?? null;

        return '' === $file || null === $at ? null : (int) $at - 1;
    }

    /** @return list<\SimpleXMLElement> what $path selects from $node, none when it selects nothing */
    private static function all(\SimpleXMLElement $node, string $path): array
    {
        $found = $node->xpath($path);

        return \is_array($found) ? \array_values($found) : [];
    }
}
