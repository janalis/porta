<?php

declare(strict_types=1);

namespace Porta\Search;

use Tempest\Highlight\Theme;
use Tempest\Highlight\Tokens\TokenType;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * A Tempest highlighting theme that emits nothing but sentinel markers around
 * comment and string (literal/value) tokens, leaving all other text verbatim.
 * Parsing a file with it yields the source with {@see OPEN}/{@see CLOSE} markers
 * bracketing exactly the comment/string spans, which {@see TokenClassifier}
 * walks to recover their character offsets.
 */
final class CommentStringTheme implements Theme
{
    public const string OPEN = "\x00\x01";
    public const string CLOSE = "\x00\x02";

    #[\Override]
    public function before(TokenType $tokenType): string
    {
        return $this->marks($tokenType) ? self::OPEN : '';
    }

    #[\Override]
    public function after(TokenType $tokenType): string
    {
        return $this->marks($tokenType) ? self::CLOSE : '';
    }

    #[\Override]
    public function escape(string $content): string
    {
        return $content; // verbatim, so offsets in the marked output match the source
    }

    private function marks(TokenType $tokenType): bool
    {
        return TokenTypeEnum::COMMENT === $tokenType || TokenTypeEnum::LITERAL === $tokenType || TokenTypeEnum::VALUE === $tokenType;
    }
}
