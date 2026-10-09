<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

/**
 * The CommonMark spec's HTML grammar: what a raw inline tag is, and the seven
 * ways an HTML block opens and closes.
 */
final class HtmlSyntax
{
    private const string TAG_NAME = '[A-Za-z][A-Za-z0-9-]*';
    private const string ATTRIBUTE = '(?:[ \t\n\r\f\v]+[a-zA-Z_:][a-zA-Z0-9:._-]*(?:[ \t\n\r\f\v]*=[ \t\n\r\f\v]*(?:[^"\'=<>`\x00-\x20]+|\'[^\']*\'|"[^"]*"))?)';
    public const string OPEN_TAG = '<'.self::TAG_NAME.self::ATTRIBUTE.'*[ \t\n\r\f\v]*\/?>';
    public const string CLOSE_TAG = '<\/'.self::TAG_NAME.'[ \t\n\r\f\v]*>';
    public const string INLINE = '/^(?:'.self::OPEN_TAG.'|'.self::CLOSE_TAG.'|<!-->|<!--->|<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<![A-Za-z]+[^>]*>|<!\[CDATA\[[\s\S]*?\]\]>)/';

    private const array BLOCK_OPEN = [
        1 => '/^<(?:script|pre|textarea|style)(?:[ \t\n\r\f\v]|>|$)/i',
        2 => '/^<!--/',
        3 => '/^<\?/',
        4 => '/^<![A-Za-z]/',
        5 => '/^<!\[CDATA\[/',
        6 => '/^<\/?(?:address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul)(?:[ \t\n\r\f\v]|\/?>|$)/i',
        7 => '/^(?:'.self::OPEN_TAG.'|'.self::CLOSE_TAG.')[ \t]*$/i',
    ];

    /**
     * Which HTML block kind (1–7) a line starting with $text opens, or 0.
     * Kind 7 cannot interrupt a paragraph; that is the caller's to check.
     */
    public static function blockStart(string $text): int
    {
        foreach (self::BLOCK_OPEN as $type => $pattern) {
            if (7 === $type && 1 === \preg_match('/^<\/?(?:script|style|pre|textarea)\b/i', $text)) {
                break;
            }
            if (1 === \preg_match($pattern, $text)) {
                return $type;
            }
        }

        return 0;
    }

    /** Whether $text holds the end condition of an HTML block of kind 1–5. */
    public static function blockEnds(int $type, string $text): bool
    {
        $close = match ($type) {
            1 => '/<\/(?:script|pre|textarea|style)>/i',
            2 => '/-->/',
            3 => '/\?>/',
            4 => '/>/',
            5 => '/\]\]>/',
            default => null,
        };

        return null !== $close && 1 === \preg_match($close, $text);
    }

    /** The length of the raw inline HTML at the start of $text, or 0. */
    public static function inlineAt(string $text): int
    {
        $m = [];

        return 1 === \preg_match(self::INLINE, $text, $m) ? \strlen($m[0]) : 0;
    }
}
