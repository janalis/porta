<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

/**
 * What one tile says: a store plugin and an installed one are drawn alike,
 * each first turned into this.
 */
final readonly class StoreCard
{
    /**
     * @param string $badges already styled: ✓ Verified, Featured
     * @param string $caption the button's caption, translated
     */
    public function __construct(
        public string $title,
        public string $badges,
        public string $subtitle,
        public string $facts,
        public string $caption,
        public bool $pressable,
    ) {}

    /**
     * The icon's two letters: the first two words' initials, or a single
     * word's first two letters — `Php Cs Fixer` is `PC`, `Rector` is `Re`.
     */
    public function initials(): string
    {
        $words = \preg_split('/[^\p{L}\p{N}]+/u', $this->title, flags: \PREG_SPLIT_NO_EMPTY);
        $words = false === $words ? [] : $words;
        $first = $words[0] ?? '?';
        $letters = \count($words) > 1 ? self::first($first).self::first($words[1]) : \mb_substr($first, start: 0, length: 2);

        return \mb_strtoupper(self::first($letters)).\mb_substr($letters, start: 1);
    }

    private static function first(string $word): string
    {
        return \mb_substr($word, start: 0, length: 1);
    }
}
