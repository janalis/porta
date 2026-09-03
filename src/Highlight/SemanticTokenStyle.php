<?php

declare(strict_types=1);

namespace Porta\Highlight;

use Porta\Ui\Sgr;
use Tempest\Highlight\TerminalTheme;
use Tempest\Highlight\Tokens\TokenTypeEnum;

/**
 * Turns one LSP semantic token into the SGR pair that recolours it.
 *
 * The colours are NOT new: they are the same {@see TokenTypeEnum} palette the
 * four terminal themes already define, so a server-driven `property` lands on
 * exactly the colour the lexer would have used for a property — the point being
 * that the server knows which identifiers really ARE properties and the lexer
 * only guesses from shape.
 *
 * The close is `Sgr::DEFAULT_FG` because that is what every colour theme's
 * `after()` already returns, so a semantic span closes exactly the way the
 * highlighter's own token brackets close and cannot leak into the rest of the
 * line. `PlainTerminalTheme` colours nothing, and is handled by emitting no
 * colour and no reset at all rather than a pair of no-ops.
 *
 * Read at RENDER time rather than baked in when the tokens arrive: the theme is
 * a {@see \Porta\Highlight\SwitchableTerminalTheme} that is swapped in place, so
 * resolving late is what makes a theme switch recolour semantics immediately.
 */
final class SemanticTokenStyle
{
    /** Strikethrough, and its off — a `deprecated` symbol reads as struck through. */
    private const string STRIKE = "\x1b[9m";
    private const string NO_STRIKE = "\x1b[29m";

    /** Italic, and its off — a `static` member leans, as it does in PhpStorm. */
    private const string ITALIC = "\x1b[3m";
    private const string NO_ITALIC = "\x1b[23m";

    /**
     * The opening SGR for a token, or '' when neither its type nor its
     * modifiers say anything — an unmapped type is left to the lexer rather
     * than forced to a colour we invented.
     *
     * @param list<string> $modifiers LSP modifier names carried by this token
     */
    public static function open(string $type, array $modifiers, TerminalTheme $theme): string
    {
        $tokenType = self::tokenTypeFor($type);
        $open = null === $tokenType ? '' : $theme->before($tokenType);
        if (\in_array('deprecated', $modifiers, strict: true)) {
            $open .= self::STRIKE;
        }
        if (\in_array('static', $modifiers, strict: true)) {
            $open .= self::ITALIC;
        }

        return $open;
    }

    /**
     * The closing SGR mirroring {@see open()}. Emitted in the reverse order the
     * opens were, so nesting reads naturally in the byte stream.
     *
     * @param list<string> $modifiers
     */
    public static function close(string $type, array $modifiers, TerminalTheme $theme): string
    {
        $close = '';
        if (\in_array('static', $modifiers, strict: true)) {
            $close .= self::NO_ITALIC;
        }
        if (\in_array('deprecated', $modifiers, strict: true)) {
            $close .= self::NO_STRIKE;
        }
        $tokenType = self::tokenTypeFor($type);
        // Only undo a colour we actually opened: the plain theme emits none.
        if (null !== $tokenType && '' !== $theme->before($tokenType)) {
            $close .= Sgr::DEFAULT_FG;
        }

        return $close;
    }

    /**
     * The palette entry an LSP token type maps onto, or null to leave the
     * lexer's colour alone.
     *
     * Types beyond phpantom's legend are mapped too — the legend is per server,
     * and a type we silently dropped would be a colourless identifier on the
     * next server rather than a visible bug.
     */
    private static function tokenTypeFor(string $type): ?TokenTypeEnum
    {
        return match ($type) {
            'namespace', 'class', 'interface', 'enum', 'struct', 'type', 'typeParameter' => TokenTypeEnum::TYPE,
            'parameter', 'variable' => TokenTypeEnum::VARIABLE,
            'property' => TokenTypeEnum::PROPERTY,
            'function', 'method', 'event' => TokenTypeEnum::GENERIC,
            'decorator', 'macro' => TokenTypeEnum::ATTRIBUTE,
            'enumMember' => TokenTypeEnum::LITERAL,
            'keyword', 'modifier' => TokenTypeEnum::KEYWORD,
            'comment' => TokenTypeEnum::COMMENT,
            'string', 'regexp' => TokenTypeEnum::VALUE,
            'number' => TokenTypeEnum::NUMBER,
            'operator' => TokenTypeEnum::OPERATOR,
            default => null,
        };
    }
}
