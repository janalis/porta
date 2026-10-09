<?php

declare(strict_types=1);

namespace Porta\Markdown\Inline;

use Porta\Markdown\MappedText;
use Porta\Markdown\Node\Inline;

/**
 * The second phase of CommonMark parsing: a paragraph's (or heading's, or
 * table cell's) text into inline nodes — code spans, emphasis, links and
 * images, autolinks, raw HTML, entities, escapes and line breaks — plus GFM's
 * strikethrough, extended autolinks and footnote references, and GitHub's
 * emoji shortcodes.
 *
 * One pass builds a list of items; `*`/`_`/`~` runs go on a delimiter stack
 * and `[`/`![` on a bracket stack, resolved by {@see LinkCloser} at each `]`
 * and by {@see EmphasisResolver} at the end; {@see AutolinkPass} then finds
 * GFM's bare links in the text left over. Every item keeps the byte range
 * it came from, which the {@see MappedText} turns into source ranges.
 */
final class InlineParser
{
    private string $subject = '';
    private int $pos = 0;
    private InlineItem $root;
    private EmphasisResolver $emphasis;
    private ?Bracket $brackets = null;
    private InlineContext $context;
    private readonly LinkCloser $links;

    public function __construct()
    {
        $this->root = new InlineItem(ItemKind::Text, '', 0, 0);
        $this->emphasis = new EmphasisResolver();
        $this->context = new InlineContext();
        $this->links = new LinkCloser();
    }

    /** @return list<Inline> */
    public function parse(MappedText $text, InlineContext $context): array
    {
        $lead = \strspn($text->text, characters: " \t\n");
        $trimmed = \rtrim(\substr($text->text, $lead), characters: " \t\n");
        $mapped = $text->from($lead)->head(\strlen($trimmed));
        $this->subject = $mapped->text;
        $this->pos = 0;
        $this->root = new InlineItem(ItemKind::Text, '', 0, \strlen($this->subject));
        $this->emphasis = new EmphasisResolver();
        $this->brackets = null;
        $this->context = $context;
        $length = \strlen($this->subject);
        while ($this->pos < $length) {
            $this->parseOne();
        }
        $this->emphasis->process(null);

        new AutolinkPass($this->subject)->apply($this->root);

        return new InlineFreezer($mapped)->freeze($this->root);
    }

    private function parseOne(): void
    {
        $c = $this->subject[$this->pos];
        $handled = match ($c) {
            "\n" => $this->newline(),
            '\\' => $this->backslash(),
            '`' => $this->backticks(),
            '*', '_', '~' => $this->delimiterRun($c),
            '[' => $this->openBracket(),
            '!' => $this->bang(),
            ']' => $this->closeBracket(),
            '<' => $this->angle(),
            '&' => $this->entity(),
            ':' => $this->emoji(),
            default => false,
        };
        if (!$handled) {
            $this->text();
        }
    }

    private function add(ItemKind $kind, string $literal, int $start, int $end): InlineItem
    {
        $item = new InlineItem($kind, $literal, $start, $end);
        $this->root->appendChild($item);

        return $item;
    }

    private function text(): void
    {
        $start = $this->pos;
        // A run of characters that open nothing, or one character that opened nothing after all.
        $run = \strcspn($this->subject, characters: "\n`[]\\!<&*_~:", offset: $start);
        $this->pos += \max(1, $run);
        $this->add(ItemKind::Text, \substr($this->subject, $start, $this->pos - $start), $start, $this->pos);
    }

    private function newline(): bool
    {
        $start = $this->pos++;
        $last = $this->root->last;
        $hard = false;
        if (null !== $last && ItemKind::Text === $last->kind && \str_ends_with($last->literal, ' ')) {
            $hard = \str_ends_with($last->literal, '  ');
            $trimmed = \rtrim($last->literal, characters: ' ');
            $start -= \strlen($last->literal) - \strlen($trimmed);
            $last->end -= \strlen($last->literal) - \strlen($trimmed);
            $last->literal = $trimmed;
        }
        $this->add($hard ? ItemKind::HardBreak : ItemKind::SoftBreak, '', $start, $this->pos);
        $this->pos += \strspn($this->subject, characters: ' ', offset: $this->pos);

        return true;
    }

    private function backslash(): bool
    {
        $start = $this->pos++;
        $next = $this->subject[$this->pos] ?? '';
        if ("\n" === $next) {
            ++$this->pos;
            $this->add(ItemKind::HardBreak, '', $start, $this->pos);
            $this->pos += \strspn($this->subject, characters: ' ', offset: $this->pos);

            return true;
        }
        $escaped = '' !== $next && \str_contains(Entities::ESCAPABLE, $next);
        if ($escaped) {
            ++$this->pos;
        }
        $this->add(ItemKind::Text, $escaped ? $next : '\\', $start, $this->pos);

        return true;
    }

    private function backticks(): bool
    {
        $start = $this->pos;
        $ticks = \strspn($this->subject, characters: '`', offset: $start);
        $after = $start + $ticks;
        $search = $after;
        while (false !== ($at = \strpos($this->subject, needle: '`', offset: $search))) {
            $run = \strspn($this->subject, characters: '`', offset: $at);
            $search = $at + $run;
            if ($run !== $ticks) {
                continue;
            }
            $contents = \str_replace("\n", replace: ' ', subject: \substr($this->subject, $after, $at - $after));
            if (\strlen($contents) >= 2 && ' ' === $contents[0] && ' ' === $contents[\strlen($contents) - 1] && '' !== \trim($contents, characters: ' ')) {
                $contents = \substr($contents, offset: 1, length: -1);
            }
            $this->pos = $search;
            $this->add(ItemKind::Code, $contents, $start, $this->pos);

            return true;
        }
        $this->pos = $after;
        $this->add(ItemKind::Text, \substr($this->subject, $start, $ticks), $start, $after);

        return true;
    }

    private function delimiterRun(string $char): bool
    {
        $start = $this->pos;
        $count = \strspn($this->subject, $char, $start);
        if ('~' === $char && $count > 2) {
            $this->pos += $count;
            $this->add(ItemKind::Text, \substr($this->subject, $start, $count), $start, $this->pos);

            return true;
        }
        $this->pos += $count;
        [$canOpen, $canClose] = Flanking::of($this->subject, $start, $this->pos, $char);
        $item = $this->add(ItemKind::Text, \substr($this->subject, $start, $count), $start, $this->pos);
        if ($canOpen || $canClose) {
            $this->emphasis->push(new Delimiter($item, $char, $count, $canOpen, $canClose));
        }

        return true;
    }

    private function openBracket(): bool
    {
        $m = [];
        if (
            1 === \preg_match('/\G\[\^([^\]\x00\r\n \t]+)\]/', $this->subject, $m, flags: 0, offset: $this->pos)
            && \array_key_exists(LinkSyntax::normalize($m[1]), $this->context->footnotes)
            && !\in_array($this->subject[$this->pos + \strlen($m[0])] ?? '', ['(', '[', ':'], strict: true)
        ) {
            $start = $this->pos;
            $this->pos += \strlen($m[0]);
            $this->add(ItemKind::FootnoteReference, $m[1], $start, $this->pos);

            return true;
        }
        $item = $this->add(ItemKind::Text, '[', $this->pos, $this->pos + 1);
        $this->pushBracket($item, false, $this->pos);
        ++$this->pos;

        return true;
    }

    private function bang(): bool
    {
        if ('[' !== ($this->subject[$this->pos + 1] ?? '')) {
            return false;
        }
        $item = $this->add(ItemKind::Text, '![', $this->pos, $this->pos + 2);
        $this->pushBracket($item, true, $this->pos + 1);
        $this->pos += 2;

        return true;
    }

    private function pushBracket(InlineItem $item, bool $image, int $index): void
    {
        if (null !== $this->brackets) {
            $this->brackets->bracketAfter = true;
        }
        $this->brackets = new Bracket($item, $image, $index, $this->brackets, $this->emphasis->top);
    }

    private function closeBracket(): bool
    {
        $opener = $this->brackets;
        $start = $this->pos++;
        $link = null === $opener || !$opener->active ? null : $this->links->close($this->subject, $this->pos, $opener, $this->context);
        $this->brackets = $opener?->previous;
        if (null === $opener || null === $link) {
            $this->add(ItemKind::Text, ']', $start, $this->pos);

            return true;
        }
        [$destination, $title, $end] = $link;
        $item = new InlineItem($opener->image ? ItemKind::Image : ItemKind::Link, '', $opener->item->start, $end);
        $item->destination = $destination;
        $item->title = $title;
        $opener->item->moveFollowingInto($item, null);
        $this->root->appendChild($item);
        $this->emphasis->process($opener->previousDelimiter);
        $opener->item->unlink();
        $this->pos = $end;
        if (!$opener->image) {
            $earlier = $this->brackets;
            while (null !== $earlier) {
                $earlier->active = $earlier->image && $earlier->active;
                $earlier = $earlier->previous;
            }
        }

        return true;
    }

    private function angle(): bool
    {
        $m = [];
        $start = $this->pos;
        $autolink = match (true) {
            1 === \preg_match(
                '/\G<([a-zA-Z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*)>/',
                $this->subject,
                $m,
                flags: 0,
                offset: $start,
            )
                => [$m[1], 'mailto:'.$m[1], \strlen($m[0])],
            1 === \preg_match('/\G<([A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*)>/', $this->subject, $m, flags: 0, offset: $start) => [$m[1], $m[1], \strlen($m[0])],
            default => null,
        };
        if (null !== $autolink) {
            return $this->addAngleAutolink($autolink[0], $autolink[1], $autolink[2]);
        }
        $length = HtmlSyntax::inlineAt(\substr($this->subject, $start));
        if (0 !== $length) {
            $this->pos += $length;
            $this->add(ItemKind::Html, \substr($this->subject, $start, $length), $start, $this->pos);
        }

        return 0 !== $length;
    }

    private function addAngleAutolink(string $text, string $destination, int $length): bool
    {
        $start = $this->pos;
        $this->pos += $length;
        $link = $this->add(ItemKind::Autolink, '', $start, $this->pos);
        $link->destination = $destination;
        $link->appendChild(new InlineItem(ItemKind::Text, $text, $start + 1, $this->pos - 1));

        return true;
    }

    private function entity(): bool
    {
        $found = Entities::at(\substr($this->subject, offset: $this->pos, length: 40));
        if (null === $found) {
            return false;
        }
        $start = $this->pos;
        $this->pos += $found[1];
        $this->add(ItemKind::Text, $found[0], $start, $this->pos);

        return true;
    }

    private function emoji(): bool
    {
        $m = [];
        if (1 !== \preg_match('/\G:([a-z0-9_+-]+):/', $this->subject, $m, flags: 0, offset: $this->pos) || !\array_key_exists($m[1], EmojiShortcodes::GLYPHS)) {
            return false;
        }
        $start = $this->pos;
        $this->pos += \strlen($m[0]);
        $item = $this->add(ItemKind::Emoji, EmojiShortcodes::GLYPHS[$m[1]], $start, $this->pos);
        $item->title = $m[1];

        return true;
    }
}
