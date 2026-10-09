<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\SourceRange;
use Porta\Ui\Role;

/**
 * An HTML block's text cut into paragraphs at its block tags (`<p>`, `<div>`,
 * `<h1>`…, `<br>`), each a list of styled fragments and whether it is
 * centred. Inline tags style the text they wrap ({@see HtmlInlineTags}), an
 * `<img>` becomes its alt text leading to the file, and entities are decoded.
 */
final class HtmlParagraphs
{
    private const string BLOCK = '/^(?:p|div|center|h[1-6]|details|summary|picture|hr)$/';

    /** @var list<array{list<Fragment>, bool}> */
    private array $paragraphs = [];
    /** @var list<Fragment> */
    private array $current = [];
    /** @var list<bool> whether each open block tag centres its content */
    private array $centred = [];
    private InlineStyle $style;
    private ?string $target = null;
    private int $group = -1;
    private readonly HtmlInlineTags $tags;

    public function __construct(
        private readonly LayoutState $state,
        private readonly SourceRange $source,
    ) {
        $this->style = new InlineStyle();
        $this->tags = new HtmlInlineTags();
    }

    /** @return list<array{list<Fragment>, bool}> */
    public function of(string $literal): array
    {
        $tokens = [];
        \preg_match_all('/<[^>]*>|[^<]+/', $literal, $tokens);
        foreach ($tokens[0] as $piece) {
            '<' === $piece[0] ? $this->tag($piece) : $this->text($piece);
        }
        $this->flush();

        return $this->paragraphs;
    }

    private function text(string $text): void
    {
        $text = \preg_replace('/\s+/', replacement: ' ', subject: \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, encoding: 'UTF-8')) ?? $text;
        if ([] === $this->current && '' === \trim($text)) {
            return;
        }
        $this->current[] = new Fragment($text, $this->style, $this->target, $this->group, $this->source);
    }

    private function tag(string $markup): void
    {
        $tag = HtmlInlineTags::parse($markup);
        if (null === $tag) {
            $this->text($markup); // a `<` that opens no tag is text

            return;
        }
        [$name, $closing, $attributes] = $tag;
        match (true) {
            1 === \preg_match(self::BLOCK, $name) => $closing ? $this->closeBlock() : $this->openBlock($name, $attributes),
            'img' === $name => $this->image($attributes),
            default => $this->inline($markup),
        };
    }

    private function closeBlock(): void
    {
        $this->flush();
        \array_pop($this->centred);
        $this->style = new InlineStyle();
    }

    /** @param array<string, string> $attributes */
    private function openBlock(string $name, array $attributes): void
    {
        $this->flush();
        $this->centred[] = 'center' === $name || 'center' === \strtolower($attributes['align'] ?? '') || \in_array(true, $this->centred, strict: true);
        $level = 1 === \preg_match('/^h[1-6]$/', $name) ? (int) $name[1] : 0;
        $this->style = match (true) {
            1 === $level => new InlineStyle(InlineStyle::BOLD, Role::Emphasis),
            2 === $level => new InlineStyle(InlineStyle::BOLD, Role::Bright),
            $level > 2 => new InlineStyle(InlineStyle::BOLD),
            default => new InlineStyle(),
        };
    }

    /** @param array<string, string> $attributes */
    private function image(array $attributes): void
    {
        $source = $attributes['src'] ?? '';
        $alt = \trim($attributes['alt'] ?? '');
        $label = '' === $alt ? \basename(\explode('?', $source)[0]) : $alt;
        $inLink = null !== $this->target;
        $this->current[] = new Fragment(
            '['.$label.']',
            $this->style->with(InlineStyle::LINK)->with(InlineStyle::MUTED),
            $inLink ? $this->target : $source,
            $inLink ? $this->group : $this->state->group(),
            $this->source,
            image: new ImageRef($source, $attributes['width'] ?? ''),
        );
    }

    private function inline(string $markup): void
    {
        $effect = $this->tags->apply($markup, $this->style, $this->target);
        if (null === $effect) {
            return;
        }
        [$this->style, $target, $fragments] = $effect;
        if ($target !== $this->target) {
            $this->target = $target;
            $this->group = null === $target ? -1 : $this->state->group();
        }
        \array_push($this->current, ...$fragments);
    }

    private function flush(): void
    {
        $fragments = $this->current;
        while ([] !== $fragments && !$fragments[\count($fragments) - 1]->isBreak && '' === \trim($fragments[\count($fragments) - 1]->text)) {
            \array_pop($fragments);
        }
        if ([] !== $fragments) {
            $this->paragraphs[] = [$fragments, \in_array(true, $this->centred, strict: true)];
        }
        $this->current = [];
    }
}
