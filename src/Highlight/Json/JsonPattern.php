<?php

declare(strict_types=1);

namespace Porta\Highlight\Json;

use Tempest\Highlight\IsPattern;
use Tempest\Highlight\Pattern;
use Tempest\Highlight\Tokens\TokenType;

/**
 * A regex/token-type pair for {@see JsonLanguage}.
 *
 * JSON's grammar is small enough that every rule is one flat pattern with no
 * per-rule behaviour, so the language declares them inline rather than as a
 * class apiece.
 */
final readonly class JsonPattern implements Pattern
{
    use IsPattern;

    public function __construct(
        private string $pattern,
        private TokenType $tokenType,
    ) {}

    public function getPattern(): string
    {
        return $this->pattern;
    }

    #[\Override]
    public function getTokenType(): TokenType
    {
        return $this->tokenType;
    }
}
