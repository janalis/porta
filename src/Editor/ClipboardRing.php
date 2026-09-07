<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * The last few things you copied, newest first — PhpStorm's clipboard history.
 *
 * It replaces a bare `string` field, and the reason it is an OBJECT is the
 * handover: {@see EditorActions} is rebuilt on every buffer switch and the old
 * instance handed the new one its clipboard, which a string can only do by
 * value. A ring passed by handle keeps one history across every buffer in the
 * session, which is the only way the feature means anything — the copy you want
 * back is usually the one you made in the file you just left.
 *
 * Re-copying something already in the ring PROMOTES it rather than adding a
 * second entry. Otherwise the list fills with the same snippet from the four
 * times you pasted it around, and the thing you are looking for is pushed off
 * the end by its own duplicates.
 */
final class ClipboardRing
{
    /**
     * How far back it remembers. Deep enough that the entry you want is
     * usually still there, short enough that the picker is a list you read
     * rather than one you search — and the filter is there for the rest.
     */
    public const int DEPTH = 20;

    /** @var list<string> newest first */
    private array $entries = [];

    /** Record a copy. Empty text is not a copy and does not displace anything. */
    public function push(string $text): void
    {
        if ('' === $text) {
            return;
        }

        // array_values() is not decoration: array_filter preserves keys, and
        // `analyze` refuses the gapped result for a list<string> property. The
        // runtime would survive without it (array_unshift renumbers), the gate
        // would not.
        $kept = array_values(array_filter($this->entries, static fn (string $entry): bool => $entry !== $text));
        array_unshift($kept, $text);
        $this->entries = \array_slice($kept, offset: 0, length: self::DEPTH);
    }

    /** What `Ctrl+V` pastes: the most recent copy, or '' when nothing has been copied. */
    public function latest(): string
    {
        return $this->entries[0] ?? '';
    }

    /** @return list<string> newest first */
    public function entries(): array
    {
        return $this->entries;
    }
}
