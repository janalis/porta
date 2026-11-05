<?php

declare(strict_types=1);

namespace Porta\PhpUnit;

/**
 * The tests a Pest file declares — `test()` and `it()` calls, inside any
 * `describe()` groups — each by the name Pest reports it under and the lines
 * its call spans.
 *
 * Pest names `test('adds')` "adds", `it('adds')` "it adds", and a test in
 * `describe('math')` "`math` → adds", a group per level. Those names are what
 * its JUnit log says and what `--filter` matches, so they are what a target
 * and a mark go by. Read from PHP's own tokens: a description may hold
 * anything a string can, parentheses included.
 */
final class PestTests
{
    private const array CALLS = ['test', 'it', 'describe'];

    /** @return list<array{name: string, line: int, end: int}> 0-based lines, the call's first and last */
    public static function in(string $text): array
    {
        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($text),
            static fn (\PhpToken $piece): bool => !$piece->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]),
        ));
        $depth = 0;
        /** @var list<array{name: string, line: int, depth: int, group: bool}> $open */
        $open = [];
        $tests = [];
        foreach ($tokens as $index => $token) {
            if ('(' === $token->text) {
                ++$depth;
                $call = self::call($tokens, $index);
                if (null !== $call) {
                    $open[] = [...$call, 'name' => self::prefix($open).$call['name'], 'depth' => $depth];
                }

                continue;
            }
            if (')' !== $token->text) {
                continue;
            }
            $closing = \end($open);
            if (false !== $closing && $closing['depth'] === $depth) {
                \array_pop($open);
                if (!$closing['group']) {
                    $tests[] = ['name' => $closing['name'], 'line' => $closing['line'], 'end' => $token->line - 1];
                }
            }
            --$depth;
        }

        return $tests;
    }

    /** The 0-based line of the test Pest reports as $name, or null when the text declares none of that name. */
    public static function lineOf(string $text, string $name): ?int
    {
        foreach (self::in($text) as $test) {
            if ($name === $test['name']) {
                return $test['line'];
            }
        }

        return null;
    }

    /**
     * The `test(`, `it(` or `describe(` call the `(` at $index opens, read:
     * its kind and its description — or null when that `(` opens anything
     * else, a method's `->it(` or a description that is not a plain string.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array{name: string, line: int, group: bool}|null
     */
    private static function call(array $tokens, int $index): ?array
    {
        $function = $tokens[$index - 1] ?? null;
        $before = $tokens[$index - 2] ?? null;
        $description = $tokens[$index + 1] ?? null;
        $called = \ltrim((string) $function?->text, characters: '\\');
        if (
            null === $function
            || null === $description
            || !\in_array($called, self::CALLS, strict: true)
            || !$description->is(\T_CONSTANT_ENCAPSED_STRING)
            || (null !== $before && $before->is([\T_OBJECT_OPERATOR, \T_NULLSAFE_OBJECT_OPERATOR, \T_DOUBLE_COLON, \T_FUNCTION]))
        ) {
            return null;
        }
        $said = self::unquote($description->text);

        return [
            'name' => match ($called) {
                'it' => 'it '.$said,
                'describe' => '`'.$said.'`',
                default => $said,
            },
            'line' => $function->line - 1,
            'group' => 'describe' === $called,
        ];
    }

    /**
     * What the groups open around a call put before its name: the innermost
     * group's name, which already holds those around it.
     *
     * @param list<array{name: string, line: int, depth: int, group: bool}> $open
     */
    private static function prefix(array $open): string
    {
        $groups = \array_filter($open, static fn (array $call): bool => $call['group']);
        $innermost = \end($groups);

        return false === $innermost ? '' : $innermost['name'].' → ';
    }

    /** A string literal's value: its quotes off, its escapes read. */
    private static function unquote(string $literal): string
    {
        $inner = \substr($literal, offset: 1, length: -1);

        return '"' === $literal[0] ? \stripcslashes($inner) : \str_replace(['\\\'', '\\\\'], ['\'', '\\'], $inner);
    }
}
