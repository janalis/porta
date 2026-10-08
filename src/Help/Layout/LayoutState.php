<?php

declare(strict_types=1);

namespace Porta\Help\Layout;

use Porta\Markdown\Inline\LinkSyntax;
use Porta\Markdown\Node\FootnoteDefinition;
use Porta\Markdown\SourceText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * What laying out one page shares across its blocks: the next link group, the
 * footnotes in the order they are first referenced, the heading anchors given
 * out so far, the `<details>` the reader has opened, and the source, for
 * the line each block starts on — and the directory its images are under.
 */
final class LayoutState
{
    public int $nextGroup = 0;
    /** @var array<string, int> normalised footnote label => its number */
    public array $footnoteNumbers = [];
    /** @var array<string, int> slug => how many headings took it */
    public array $slugs = [];
    /** @var array<int, true> the groups that are the page's own actions, never a link its author wrote */
    public array $actions = [];

    /**
     * @param array<string, FootnoteDefinition> $footnotes normalised label => definition
     * @param array<int, true>                  $openDetails the `<details>` shown open, by {@see detailsNumber()}
     */
    public function __construct(
        public readonly SourceText $source,
        public readonly array $footnotes = [],
        public readonly array $openDetails = [],
        public readonly ?string $baseDir = null,
    ) {}

    public function group(): int
    {
        return $this->nextGroup++;
    }

    /**
     * A group for one of the page's own actions — a task's box, a `<details>`
     * summary — kept apart from links, so a link written to look like one
     * (`[x](task:0-3)`) stays a link and never edits the text.
     */
    public function action(): int
    {
        $group = $this->group();
        $this->actions[$group] = true;

        return $group;
    }

    public function lineOf(int $offset): int
    {
        return $this->source->lineOf($offset);
    }

    /**
     * Which `<details>` of the page opens at $offset, counted from 0 in
     * source order: what the reader's open sections are kept by, since an
     * edit above a section moves its offset but not its number.
     */
    public function detailsNumber(int $offset): int
    {
        return (int) \preg_match_all('/<details\b/i', \substr($this->source->text, offset: 0, length: $offset));
    }

    /**
     * The cell of $raw drawn verbatim from column $col, where it ends source
     * line $line: after whatever quote markers or list indentation contain
     * it — never at a marker that happens to spell the same text. None when
     * the line does not end with it (a tab drawn as spaces, a line cut short).
     *
     * @return list<array{int, int, int, int, string}>
     */
    public function lineEndCells(int $line, string $raw, int $col): array
    {
        $lineStart = $this->source->offsetOf($line, 0);
        $text = $this->source->text;
        $lineEnd = $lineStart + \strcspn($text, characters: "\r\n", offset: $lineStart);
        $start = $lineEnd - \strlen($raw);
        if ('' === $raw || $start < $lineStart || \substr($text, $start, \strlen($raw)) !== $raw || \str_contains($raw, "\t")) {
            return [];
        }

        return [[$col, $col + AnsiUtils::visibleWidth($raw), $start, $start + \strlen($raw), $raw]];
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
