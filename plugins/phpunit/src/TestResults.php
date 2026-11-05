<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * One run's outcomes, failures first, and the questions the screen asks of
 * them: which failed, which files they are in, how each test line did.
 *
 * Files are compared as the file system resolves them. The IDE opens a file
 * by its real path, while the project root — and the rows, which are relative
 * to it — keep the spelling it was opened by: `/var` on macOS is a symlink.
 */
final readonly class TestResults
{
    private const array ORDER = [TestOutcome::FAILED => 0, TestOutcome::ERRORED => 0, TestOutcome::SKIPPED => 1, TestOutcome::PASSED => 2];

    /** How bad each status is, for a line several data sets share. */
    private const array RANK = [TestOutcome::PASSED => 0, TestOutcome::SKIPPED => 1, TestOutcome::FAILED => 2, TestOutcome::ERRORED => 2];

    /** @var list<TestOutcome> failures and errors, then skipped, then passed — each group in the order PHPUnit ran it */
    public array $outcomes;

    /** @param list<TestOutcome> $outcomes */
    public function __construct(array $outcomes = [])
    {
        \usort($outcomes, static fn (TestOutcome $a, TestOutcome $b): int => self::ORDER[$a->status] <=> self::ORDER[$b->status]);
        $this->outcomes = $outcomes;
    }

    /** @return list<TestOutcome> the failures and errors */
    public function broken(): array
    {
        return \array_values(\array_filter($this->outcomes, static fn (TestOutcome $outcome): bool => $outcome->broken()));
    }

    /** @return list<string> the files the broken tests are in, each once */
    public function brokenFiles(): array
    {
        return \array_values(\array_unique(\array_map(static fn (TestOutcome $outcome): string => $outcome->file, $this->broken())));
    }

    /** @return list<TestOutcome> the failures and errors in $file */
    public function brokenIn(string $file): array
    {
        $file = self::real($file);

        return \array_values(\array_filter($this->broken(), static fn (TestOutcome $outcome): bool => self::real($outcome->file) === $file));
    }

    /**
     * How each test line of $file did: the worst of its data sets, so one
     * that failed fails the method.
     *
     * @return array<int, string> 0-based line => status
     */
    public function lines(?string $file): array
    {
        $file = null === $file ? null : self::real($file);
        $worst = [];
        foreach ($this->outcomes as $outcome) {
            if (self::real($outcome->file) !== $file) {
                continue;
            }
            $was = $worst[$outcome->line] ?? TestOutcome::PASSED;
            $worst[$outcome->line] = self::RANK[$outcome->status] > self::RANK[$was] ? $outcome->status : $was;
        }

        return $worst;
    }

    private static function real(string $path): string
    {
        $real = \realpath($path);

        return false === $real ? $path : $real;
    }
}
