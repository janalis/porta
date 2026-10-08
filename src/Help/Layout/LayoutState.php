<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Inline\LinkSyntax;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\SourceText;

/**
 * What laying out one page shares across its blocks: the next link group, the
 * footnotes in the order they are first referenced, the heading anchors given
 * out so far, the `<details>` the reader has opened, and the source, for
 * the line each block starts on.
 */
final class LayoutState
{
    public int $nextGroup = 0;
    /** @var array<string, int> normalised footnote label => its number */
    public array $footnoteNumbers = [];
    /** @var array<string, int> slug => how many headings took it */
    public array $slugs = [];

    /**
     * @param array<string, FootnoteDefinition> $footnotes normalised label => definition
     * @param array<int, true>                  $openDetails the source offsets of the `<details>` shown open
     */
    public function __construct(
        public readonly SourceText $source,
        public readonly array $footnotes = [],
        public readonly array $openDetails = [],
    ) {}

    public function group(): int
    {
        return $this->nextGroup++;
    }

    public function lineOf(int $offset): int
    {
        return $this->source->lineOf($offset);
    }

    /** A footnote's number, given on its first reference. */
    public function footnote(string $label): int
    {
        $key = LinkSyntax::normalize($label);

        return $this->footnoteNumbers[$key] ??= \count($this->footnoteNumbers) + 1;
    }

    /** GitHub's anchor for a heading: lower-cased, punctuation dropped, spaces as dashes, `-1`, `-2` on repeats. */
    public function slug(string $heading): string
    {
        $slug = \preg_replace('/[^\p{L}\p{M}\p{N}\p{Pc} -]/u', replacement: '', subject: \mb_strtolower(\trim($heading), encoding: 'UTF-8')) ?? '';
        $slug = \str_replace(' ', replace: '-', subject: $slug);
        $taken = $this->slugs[$slug] ?? 0;
        $this->slugs[$slug] = $taken + 1;

        return 0 === $taken ? $slug : $slug.'-'.$taken;
    }
}
