<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * One language's resolved indent preference: an {@see IndentStyle} and a size
 * ('auto' or a positive int). 'auto' defers to the file's `.editorconfig`, then
 * to what the file itself does.
 */
final readonly class LanguageIndent
{
    public function __construct(
        public IndentStyle $indentStyle = IndentStyle::Auto,
        public int|string $indentSize = 'auto',
    ) {}
}
