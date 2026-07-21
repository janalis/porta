<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Highlight\Json\JsoncLanguage;
use Porta\Highlight\Json\JsonLanguage;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Theme;

/**
 * The one place a Tempest {@see Highlighter} is built with Porta's house
 * language overrides.
 *
 * Three grammars are registered over the built-ins by name:
 * {@see PlainDocPhpLanguage} (docblocks stay a single comment token — the
 * sub-tokenized default renders white mid-comment in a terminal theme),
 * {@see JsonLanguage} (the built-in string rule loses quote parity on `\"` and
 * leaves numbers and literals uncolored) and {@see JsoncLanguage} (Tempest has
 * no JSON-with-comments grammar at all).
 *
 * This exists as a shared factory because the editor and project search each
 * used to build their own Highlighter, and only the editor's registered these
 * overrides — so a PHP docblock or a `tsconfig.json` classified differently in
 * the two subsystems. Both now come through here, with the theme as the only
 * difference: {@see TerminalTheme} for coloring, {@see \Porta\Search\CommentStringTheme}
 * for the sentinel-marked pass search walks for comment/string spans.
 */
final class TempestBackend
{
    public static function newHighlighter(Theme $theme): Highlighter
    {
        return new Highlighter($theme)
            ->addLanguage(new PlainDocPhpLanguage())
            ->addLanguage(new JsonLanguage())
            ->addLanguage(new JsoncLanguage());
    }
}
