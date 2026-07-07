<?php

declare(strict_types=1);

namespace Porta\Config;

/**
 * One language's resolved indent preference: a style ('auto' | 'spaces' |
 * 'tabs') and a size ('auto' or a positive int). 'auto' defers to the file's
 * `.editorconfig`, falling back to unix-style 2 spaces.
 */
final readonly class LanguageIndent
{
    public function __construct(
        public string $indentStyle = 'auto',
        public int|string $indentSize = 'auto',
    ) {}
}
