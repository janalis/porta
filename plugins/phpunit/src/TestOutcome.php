<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * One test case as the JUnit log reported it.
 *
 * `method` is the bare method name even for a data-set run, whose `name`
 * reads `testX with data set "two"`: the method is what a filter and a
 * source line are about, the name is what the reader is shown.
 */
final readonly class TestOutcome
{
    public const string PASSED = 'passed';
    public const string FAILED = 'failed';
    public const string ERRORED = 'errored';
    public const string SKIPPED = 'skipped';

    /**
     * @param string $file absolute path of the test file
     * @param int    $line 0-based line of the method's declaration
     * @param int    $at   0-based line the failure points at in $file — the declaration when nothing better is known
     */
    public function __construct(
        public string $class,
        public string $method,
        public string $name,
        public string $file,
        public int $line,
        public string $status,
        public string $message,
        public int $at,
    ) {}

    /** A failure or an error — what Rerun Failed runs again. */
    public function broken(): bool
    {
        return self::FAILED === $this->status || self::ERRORED === $this->status;
    }

    /** The glyph a row and a mark lead with. */
    public static function glyph(string $status): string
    {
        return match ($status) {
            self::PASSED => '✓',
            self::SKIPPED => '⊘',
            default => '✗',
        };
    }

    /** The word a mark says, in English: the message id to translate. */
    public static function verdict(string $status): string
    {
        return match ($status) {
            self::PASSED => 'passed',
            self::SKIPPED => 'skipped',
            default => 'failed',
        };
    }

    /** `Class::name`, as PHPUnit itself prints a test. */
    public function label(): string
    {
        return $this->class.'::'.$this->name;
    }
}
