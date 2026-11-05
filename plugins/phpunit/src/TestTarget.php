<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * What "the test at the caret" means, read from the text as it is on screen,
 * unsaved edits included: the test class the file declares and, when the
 * caret is inside one, the test method around it.
 *
 * A line scan, not a parse. A test file is one class of methods, and what is
 * asked is only "which `function` did the caret pass last" — the language
 * server would answer the same thing a round trip later.
 */
final readonly class TestTarget
{
    private const string FUNCTION = '~^\s*(?:(?:public|protected|private|static|final|abstract)\s+)*function\s+&?(\w+)\s*\(~';

    public function __construct(
        public string $class,
        public ?string $method,
    ) {}

    /** The target at 0-based $line of $text, or null when the text declares no class. */
    public static function at(string $text, int $line): ?self
    {
        $class = self::className($text);

        return null === $class ? null : new self($class, self::method(\explode("\n", $text), $line));
    }

    /**
     * The test method declared at or above $line — the last `function` the
     * caret passed — or null when that is no test, or there is none.
     *
     * @param list<string> $lines
     */
    private static function method(array $lines, int $line): ?string
    {
        $row = \min($line, \count($lines) - 1);
        $match = [];
        while ($row >= 0 && 1 !== \preg_match(self::FUNCTION, $lines[$row], $match)) {
            --$row;
        }

        return $row >= 0 && self::isTest($lines, $row, $match[1]) ? $match[1] : null;
    }

    /** The class's fully qualified name: its `namespace`, then the first `class` it declares. */
    private static function className(string $text): ?string
    {
        $class = [];
        $match = [];
        $named = 1 === \preg_match('~^\s*(?:(?:final|abstract|readonly)\s+)*class\s+(\w+)~m', $text, $class);
        $namespace = 1 === \preg_match('~^\s*namespace\s+([\w\\\\]+)\s*[;{]~m', $text, $match) ? $match[1].'\\' : '';

        return $named ? $namespace.$class[1] : null;
    }

    /**
     * Whether the method declared on $row is a test: named `test…`, or carrying
     * `#[Test]` (or a `@test` docblock tag) among the attributes and docblock
     * lines just above it.
     *
     * @param list<string> $lines
     */
    private static function isTest(array $lines, int $row, string $method): bool
    {
        $above = $row - 1;
        while ($above >= 0 && !self::marksTest($lines[$above]) && self::decorates($lines[$above])) {
            --$above;
        }

        return \str_starts_with($method, 'test') || ($above >= 0 && self::marksTest($lines[$above]));
    }

    private static function marksTest(string $line): bool
    {
        return 1 === \preg_match('~^\s*(?:#\[(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\b|\*\s*@test\b)~', $line);
    }

    /** An attribute or docblock line — what may stand between a method and its test marker. */
    private static function decorates(string $line): bool
    {
        $text = \ltrim($line);

        return \str_starts_with($text, '#[') || \str_starts_with($text, '*') || \str_starts_with($text, '/**');
    }

    /**
     * The `--filter` that runs exactly these methods of these classes, every
     * data set of each included.
     *
     * @param list<array{0: string, 1: string}> $tests [class, method] pairs
     */
    public static function filter(array $tests): string
    {
        $names = \array_map(static fn (array $test): string => \preg_quote($test[0].'::'.$test[1], delimiter: '/'), $tests);

        return '/^(?:'.\implode('|', \array_values(\array_unique($names))).')(?:\s|$)/';
    }
}
