<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

/**
 * The inline HTML a README writes, read well enough to draw: `<b>`, `<i>`,
 * `<s>`, `<code>`, `<kbd>` style what they wrap, `<a href>` makes it a link,
 * `<br>` breaks the line, a comment disappears, and tags that only shape a
 * browser's layout (`<sub>`, `<span>`…) are dropped and their text kept.
 * Anything else is not understood, and its caller shows it as written.
 */
final class HtmlInlineTags
{
    private const array STYLES = [
        'b' => InlineStyle::BOLD,
        'strong' => InlineStyle::BOLD,
        'i' => InlineStyle::ITALIC,
        'em' => InlineStyle::ITALIC,
        's' => InlineStyle::STRIKE,
        'del' => InlineStyle::STRIKE,
        'strike' => InlineStyle::STRIKE,
        'code' => InlineStyle::CODE,
        'tt' => InlineStyle::CODE,
        'kbd' => InlineStyle::KBD,
        'sub' => 0,
        'sup' => 0,
        'span' => 0,
        'ins' => 0,
        'u' => 0,
        'small' => 0,
        'big' => 0,
        'mark' => 0,
        'abbr' => 0,
        'samp' => 0,
        'var' => 0,
        'font' => 0,
        'a' => 0,
    ];

    /** @var list<array{string, InlineStyle, ?string}> the open tags: name, and the style and link they replaced */
    private array $open = [];

    public function reset(): void
    {
        $this->open = [];
    }

    /**
     * What $literal does to the run it sits in: the style and link that hold
     * after it, and the fragments it draws itself; null when it is a tag this
     * page does not understand.
     *
     * @return array{InlineStyle, ?string, list<Fragment>}|null
     */
    public function apply(string $literal, InlineStyle $style, ?string $target): ?array
    {
        if (\str_starts_with($literal, '<!--')) {
            return [$style, $target, []];
        }
        $tag = self::parse($literal);
        if (null === $tag) {
            return null;
        }
        [$name, $closing, $attributes] = $tag;
        if ('br' === $name) {
            return [$style, $target, [Fragment::lineBreak()]];
        }
        if (!\array_key_exists($name, self::STYLES)) {
            return null;
        }

        return $closing ? $this->close($name, $style, $target) : $this->openTag($name, $attributes, $style, $target);
    }

    /**
     * @param array<string, string> $attributes
     *
     * @return array{InlineStyle, ?string, list<Fragment>}
     */
    private function openTag(string $name, array $attributes, InlineStyle $style, ?string $target): array
    {
        $this->open[] = [$name, $style, $target];
        $flag = self::STYLES[$name];
        $href = 'a' === $name ? $attributes['href'] ?? null : null;
        $style = 0 === $flag ? $style : $style->with($flag);

        return [null === $href ? $style : $style->with(InlineStyle::LINK), $href ?? $target, []];
    }

    /** @return array{InlineStyle, ?string, list<Fragment>} */
    private function close(string $name, InlineStyle $style, ?string $target): array
    {
        for ($i = \count($this->open) - 1; $i >= 0; --$i) {
            if ($this->open[$i][0] !== $name) {
                continue;
            }
            [, $style, $target] = $this->open[$i];
            $this->open = \array_slice($this->open, offset: 0, length: $i);
            break;
        }

        return [$style, $target, []];
    }

    /**
     * A tag's name (lower-cased), whether it closes, and its attributes.
     *
     * @return array{string, bool, array<string, string>}|null
     */
    public static function parse(string $literal): ?array
    {
        $m = [];
        if (1 !== \preg_match('/^<(\/?)([A-Za-z][A-Za-z0-9-]*)((?:\s[^>]*)?)\/?>$/', \trim($literal), $m)) {
            return null;
        }
        $attributes = [];
        $pairs = [];
        \preg_match_all('/([a-zA-Z_:][a-zA-Z0-9:._-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $m[3], $pairs, \PREG_SET_ORDER);
        foreach ($pairs as $pair) {
            $attributes[\strtolower($pair[1])] = \html_entity_decode(($pair[2] ?? '').($pair[3] ?? '').($pair[4] ?? ''), \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8');
        }

        return [\strtolower($m[2]), '/' === $m[1], $attributes];
    }
}
