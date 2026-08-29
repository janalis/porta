<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsIdeService;
use Porta\Event\SettingsRowsStale;
use Porta\I18n\Translator;
use Porta\Support\FuzzyMatcher;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Every setting on every page, searchable by name.
 *
 * Settings live across five sections plus a page per installed plugin and per
 * catalogued language — thirty-odd pages — navigable only by arrow key. Finding
 * one meant already knowing which page held it.
 *
 * Built once, lazily, and kept for the session. Building per keystroke is not
 * an option: the Highlighting page derives its rows by probing every grammar
 * against every backend, and the Plugins page touches the filesystem. Scoring
 * the built index, by contrast, is free — a few hundred candidates against a
 * short query.
 *
 * The haystack is `<page> <label>` with the boundary at the label, which is
 * {@see \Porta\Search\FileIndex}'s trick: page behaves like a directory and
 * label like a basename, so a match inside the label earns the primary-segment
 * bonus while `php ind` still narrows to one page.
 *
 * There is deliberately no extra thumb on the scale for label matches. Ranking
 * every label match above every page match sounds right and is not: `php` is a
 * subsequence of "Path helper", so one scattered accident on an unrelated page
 * would bury every row of the page the user meant.
 *
 * VALUES are deliberately not indexed, so nothing here goes stale when a
 * setting changes — only the row SET moves, which is what the listener below is
 * for. Hints and keys are not indexed either: subsequence matching over prose
 * matches nearly any short query, and a noisy search is worse than none.
 */
#[AsIdeService]
final class SettingsSearchIndex
{
    /** @var list<array{entry: SettingsSearchEntry, lower: string, boundary: int}>|null */
    private ?array $entries = null;

    /** The locale $entries was built under; a switch since then makes it stale. */
    private string $builtFor = '';

    public function __construct(
        private readonly SettingsPages $pages,
        private readonly Translator $translator,
    ) {}

    /**
     * The best matches for $query, or the first $limit settings when it is empty.
     *
     * @return list<SettingsSearchEntry>
     */
    public function search(string $query, int $limit): array
    {
        $entries = $this->entries();
        if ('' === trim($query)) {
            return array_map(static fn (array $row): SettingsSearchEntry => $row['entry'], \array_slice($entries, offset: 0, length: $limit));
        }

        /** @var list<array{0: int, 1: SettingsSearchEntry}> $scored */
        $scored = [];
        $lower = strtolower(trim($query));
        foreach ($entries as $row) {
            $score = FuzzyMatcher::scoreLowered($row['lower'], $lower, $row['boundary']);
            if (null !== $score) {
                $scored[] = [$score, $row['entry']];
            }
        }
        // Score first, then the shorter label — a tie between "Theme" and
        // "Theme overrides" should put the plain one on top.
        usort($scored, static function (array $a, array $b): int {
            $byScore = $b[0] <=> $a[0];

            return 0 !== $byScore ? $byScore : \strlen($a[1]->label) <=> \strlen($b[1]->label);
        });

        return array_map(static fn (array $row): SettingsSearchEntry => $row[1], \array_slice($scored, offset: 0, length: $limit));
    }

    /**
     * A page was added, removed, enabled or disabled — the row SET moved, so
     * the index is rebuilt on the next search rather than now: a plugin toggle
     * should not pay for a walk nobody has asked to search yet.
     */
    #[AsEventListener]
    public function onSettingsRowsStale(SettingsRowsStale $event): void
    {
        $this->entries = null;
    }

    /**
     * @return list<array{entry: SettingsSearchEntry, lower: string, boundary: int}>
     */
    private function entries(): array
    {
        // Labels are translated, so a locale switch invalidates every one of
        // them — but this compares the stamp rather than listening for
        // LocaleChanged, which would have to name a rung it is safe to run on.
        // It is not CORE (every listener there is order-insensitive by
        // contract, and the menu refresh happens to be registered first), and
        // it is not CACHE_DROP (below the rung plugins get, so a plugin
        // listener would read the stale index). Reading the locale at the point
        // of use cannot be ordered wrongly, because there is no order.
        $locale = $this->translator->locale();
        if (null !== $this->entries && $locale === $this->builtFor) {
            return $this->entries;
        }
        $this->builtFor = $locale;
        $entries = [];
        foreach ($this->pages->all() as $page) {
            foreach ($this->pages->sectionFor($page->id)->rows($page->id) as $row) {
                // The scope chooser leads EVERY page that writes a file, so
                // indexing it puts one identical hit per page in front of
                // whatever the user was looking for — "save" returned nineteen
                // copies of it and buried "Format on save". It is also the one
                // row search cannot usefully take you to: it is already on the
                // page you are on, whichever that is.
                if (SettingsScopeRow::KEY === $row->key) {
                    continue;
                }
                $haystack = $page->label.' '.$row->label;
                $entries[] = [
                    'entry' => new SettingsSearchEntry($page->id, $row->key, $row->label, $page->label),
                    'lower' => strtolower($haystack),
                    'boundary' => \strlen($page->label) + 1,
                ];
            }
        }

        return $this->entries = $entries;
    }
}
