<?php

declare(strict_types=1);

namespace Porta\Search;

/**
 * The language-aware filter applied to project-search matches, cycled with
 * Alt+T: match anywhere, only inside comments/strings, or everywhere except
 * comments/strings. The classification is done per matched file by
 * {@see LanguageFilter} using {@see TokenClassifier}.
 */
enum TokenScope: string
{
    case Any = 'any';
    case InCommentsStrings = 'in';
    case ExceptCommentsStrings = 'except';

    public function next(): self
    {
        return match ($this) {
            self::Any => self::InCommentsStrings,
            self::InCommentsStrings => self::ExceptCommentsStrings,
            self::ExceptCommentsStrings => self::Any,
        };
    }

    /** The two-column header glyph shown in the panel's option row. */
    public function glyph(): string
    {
        return match ($this) {
            self::Any => '·"',
            self::InCommentsStrings => 'i"',
            self::ExceptCommentsStrings => 'x"',
        };
    }
}
