<?php

declare(strict_types=1);

namespace Porta\Highlight\Json;

use Tempest\Highlight\Languages\Base\Patterns\AdditionEndTokenPattern;
use Tempest\Highlight\Languages\Base\Patterns\AdditionStartTokenPattern;
use Tempest\Highlight\Languages\Base\Patterns\DeletionEndTokenPattern;
use Tempest\Highlight\Languages\Base\Patterns\DeletionStartTokenPattern;
use Tempest\Highlight\Languages\Base\Patterns\InjectionTokenPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * The rule set shared by {@see JsonLanguage} and {@see JsoncLanguage}.
 *
 * Two mechanics of Tempest's tokenizer shape these patterns:
 *
 * - **Delimiters.** `IsPattern::match()` wraps a pattern in `/…/` unless it
 *   already starts with `/`, so a literal slash must be escaped — hence
 *   `\/\/` in {@see comments()}.
 * - **Overlap resolution is positional, not pattern-order.** `GroupTokens`
 *   sorts by start ascending then end descending and drops any later token that
 *   overlaps an earlier one. So the *earliest, longest* match wins: digits and
 *   `//` inside a string lose to the string token, and a `//` comment beats a
 *   string that opens after it — both correct, and neither depends on the order
 *   below. Order only breaks an exact-span tie, which is why {@see core()} puts
 *   the key rule before the value rule: they match the identical span on a
 *   quoted key, and the first one listed wins.
 */
final class JsonPatterns
{
    /** A complete double-quoted JSON string, treating `\<any>` as one unit. */
    private const string STRING = '"(?:\\\\.|[^"\\\\])*"';

    /**
     * Tempest's own diff/injection markers, which every language carries.
     *
     * @return list<Pattern>
     */
    public static function base(): array
    {
        return [
            new AdditionStartTokenPattern(),
            new AdditionEndTokenPattern(),
            new DeletionStartTokenPattern(),
            new DeletionEndTokenPattern(),
            new InjectionTokenPattern(),
        ];
    }

    /**
     * Keys, strings, literals, numbers and structural punctuation.
     *
     * @return list<Pattern>
     */
    public static function core(): array
    {
        return [
            // Before the value rule: identical span on a quoted key, first wins.
            new JsonPattern('(?<match>'.self::STRING.')(?=\s*:)', TokenTypeEnum::KEYWORD),
            new JsonPattern('(?<match>'.self::STRING.')', TokenTypeEnum::VALUE),
            new JsonPattern('(?<match>\b(?:true|false|null)\b)', TokenTypeEnum::LITERAL),
            new JsonPattern('(?<match>-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?)', TokenTypeEnum::NUMBER),
            new JsonPattern('(?<match>[{}\[\]])', TokenTypeEnum::PROPERTY),
        ];
    }

    /**
     * Line and block comments, for the JSON dialects that allow them.
     *
     * @return list<Pattern>
     */
    public static function comments(): array
    {
        return [
            new JsonPattern('(?<match>\/\/.*)', TokenTypeEnum::COMMENT),
            new JsonPattern('(?<match>\/\*[\s\S]*?\*\/)', TokenTypeEnum::COMMENT),
        ];
    }
}
