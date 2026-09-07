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

    /**
     * Total bytes the history may retain.
     *
     * Depth alone is not a bound: the clipboard used to be one string, and
     * twenty of them is twenty times whatever you last copied. `Ctrl+A`,
     * `Ctrl+C` on a large file twenty times retained 38 MB in a process that
     * runs all day — a change in resource behaviour no gate can see, and one
     * this class introduced.
     *
     * The NEWEST entry is exempt: it is what `Ctrl+V` pastes, and that worked
     * for any size before there was a history. The budget only decides how far
     * back the list reaches.
     */
    public const int BUDGET = 1_048_576;

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
        $this->entries = self::withinBudget(\array_slice($kept, offset: 0, length: self::DEPTH));
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

    /**
     * $entries with the oldest dropped until they fit {@see BUDGET}.
     *
     * Never below one: the newest is what `Ctrl+V` pastes and is kept whatever
     * its size, so a single huge copy still pastes and simply leaves no room
     * for a history behind it.
     *
     * @param list<string> $entries newest first
     *
     * @return list<string>
     */
    private static function withinBudget(array $entries): array
    {
        $total = array_sum(array_map(\strlen(...), $entries));
        while (\count($entries) > 1 && $total > self::BUDGET) {
            $total -= \strlen(array_pop($entries));
        }

        return $entries;
    }
}
