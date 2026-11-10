<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Help\Layout\ImageLayout;
use Porta\Help\LinkSpan;
use Porta\Help\MarkdownRenderer;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
use Porta\Plugin\Store\Art;
use Porta\Plugin\Store\ReadmeImages;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\Rule;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * A plugin's page, the App Store's: its icon, title and button; a strip of
 * facts — downloads, category, developer, version, licence; its screenshots;
 * **What's New** (the newest section of its changelog); **About** (its
 * README); and **Information**.
 *
 * What it shows beyond the catalog is handed in as each address's answer —
 * absent while it is on its way, null when there was none — so nothing here
 * waits on the network.
 */
final readonly class StorePage
{
    /** How long a README the page lays out may be, in source lines: a store page is not the manual. */
    private const int README_LINES = 400;

    /** The header's icon box, roomy and compact, in cells. */
    public const array ICON = [10, 5];

    public const array COMPACT_ICON = [6, 3];

    /** How tall the screenshots stand, roomy and compact. */
    public const int STRIP_ROWS = 10;

    public const int COMPACT_STRIP_ROWS = 6;

    /** The columns between two screenshots of the strip. */
    private const int STRIP_GAP = 2;

    public function __construct(
        private Translator $translator,
        private Styles $styles,
        private MarkdownRenderer $markdown,
        private ImageLayout $images,
    ) {}

    /**
     * The page's lines, and where its button was drawn among them.
     *
     * @param array<string, string|null> $assets each asked-for address and what it answered
     * @param int                        $rows   the screen's control height: {@see FieldBox::ROWS}, or 1
     * @param int                        $shot   the strip's first screenshot
     * @param Art|null                   $art    its pictures, once found
     *
     * @return array{lines: list<string>, button: array{int, int, int, int}, links: list<array{url: string, spans: list<array{int, int, int}>}>} the button as row, column, width, height; each link's address and where it is drawn, as row, first and past-last column
     */
    public function render(StoreRow $row, int $width, int $rows, int $shot, array $assets, bool $buttonHovered, ?Art $art = null): array
    {
        $entry = $row->entry;
        [$iconColumns, $iconRows] = FieldBox::ROWS === $rows ? self::ICON : self::COMPACT_ICON;
        $icon = null === $art?->icon ? null : $this->images->boxed($art->icon, $iconColumns, $iconRows);
        $card = new StoreCard(
            $entry->title,
            $entry->verified ? $this->styles->of(Role::TabAccent)->apply(Glyphs::MD_CHECK.' '.$this->translator->trans('Verified')) : '',
            $entry->description,
            $this->translator->trans('by %author%', ['%author%' => $entry->author]),
            $this->translator->trans($row->action->caption()),
            $row->action->pressable(),
            $icon,
        );
        // A column of air at the left, as every other line of the page has.
        $header = new StoreTile($this->styles, $this->direction())->render($card, \max(0, $width - 1), $rows, $buttonHovered);
        $lines = \array_map(static fn (string $line): string => ' '.$line, $header['lines']);
        $button = $header['button'];
        if (null !== $entry->replaces) {
            $lines[] = ' '.$this->styles->of(Role::TabAccent)->apply($this->translator->trans('Replaces the built-in %plugin% plugin', ['%plugin%' => $entry->replaces]));
        }
        if (!$entry->compatible) {
            $lines[] = ' '.$this->styles->of(Role::DiagWarn)->apply($this->translator->trans('No release of it runs on this Porta'));
        }
        $links = [];
        foreach ([
            [[...$this->strip($row, $width), ...$this->preview(self::shots($entry, $art), $width, FieldBox::ROWS === $rows ? self::STRIP_ROWS : self::COMPACT_STRIP_ROWS, $shot, $assets)], []],
            $this->whatsNew($row, $width, $assets),
            $this->about($entry, $width, $assets),
            $this->information($row, $width),
        ] as [$section, $sectionLinks]) {
            foreach ($sectionLinks as $link) {
                $link['spans'] = \array_map(static fn (array $span): array => [$span[0] + \count($lines), $span[1], $span[2]], $link['spans']);
                $links[] = $link;
            }
            $lines = [...$lines, ...$section];
        }

        return [
            'lines' => \array_map(static fn (string $line): string => AnsiText::truncate($line, $width, pad: true), $lines),
            'button' => [$button[0], $button[1] + 1, $button[2], $button[3]],
            'links' => $links,
        ];
    }

    /**
     * The App Store's row of facts under the header: each a small caption over
     * its value, between rules.
     *
     * @return list<string>
     */
    private function strip(StoreRow $row, int $width): array
    {
        $entry = $row->entry;
        $facts = \array_filter(
            [
                'Downloads' => $entry->downloads > 0 ? \number_format($entry->downloads) : '',
                'Category' => $this->translator->trans($entry->category->label()),
                'Developer' => $entry->author,
                'Version' => $entry->version,
                'License' => $entry->license,
            ],
            static fn (string $value): bool => '' !== $value,
        );
        $rule = Rule::horizontal($width, $this->styles);
        $cell = \intdiv(\max(0, $width - 1), \max(1, \count($facts)));
        $bar = $this->styles->of(Role::Faint)->apply(Glyphs::SEPARATOR);
        $captions = [];
        $values = [];
        foreach ($facts as $label => $value) {
            $captions[] = StoreTile::pinned(FieldBox::centred(\mb_strtoupper($this->translator->trans($label)), $cell - 1, $this->styles->of(Role::Dim)), $this->direction());
            $values[] = StoreTile::pinned(FieldBox::centred($value, $cell - 1, $this->styles->of(Role::Bright)), $this->direction());
        }

        return ['', $rule, ' '.\implode($bar, $captions), ' '.\implode($bar, $values), $rule];
    }

    /**
     * The screenshots the page shows: the ones the package lists, else the
     * ones its READMEs show.
     *
     * @return list<string>
     */
    public static function shots(StoreEntry $entry, ?Art $art): array
    {
        return [] !== $entry->screenshots ? $entry->screenshots : $art->shots ?? [];
    }

    /**
     * The screenshots side by side, all as tall, from the $shot-th on, the
     * last cut at the page's edge — the App Store's strip.
     *
     * @param list<string>               $shots
     * @param positive-int               $height
     * @param array<string, string|null> $assets
     *
     * @return list<string>
     */
    private function preview(array $shots, int $width, int $height, int $shot, array $assets): array
    {
        if ([] === $shots) {
            return [];
        }
        $count = \count($shots);
        $legend = $this->translator->trans('Preview').($count > 1 ? '  '.Glyphs::SCROLL_PREV.' '.($shot + 1).'/'.$count.' '.Glyphs::SCROLL_NEXT : '');
        $room = \max(1, $width - 2);
        $band = \array_fill(start_index: 0, count: $height, value: '');
        $used = 0;
        $dim = $this->styles->of(Role::Dim);
        foreach (\array_slice($shots, $shot) as $url) {
            if ($used >= $room) {
                break;
            }
            $bytes = $assets[$url] ?? null;
            $fits = null === $bytes ? null : $this->images->fits($bytes, $room, $height);
            $picture = null === $bytes || null === $fits ? null : $this->images->boxed($bytes, $fits[0], $height);
            if (null === $picture) {
                // Not here yet, or never: a word in its place, as wide as a 16:9 picture would stand.
                $columns = \min($room, \intdiv($height * 32, num2: 9));
                $word = \array_key_exists($url, $assets) ? $this->translator->trans('The screenshot could not be shown') : $this->translator->trans('Loading…');
                $picture = \array_fill(start_index: 0, count: $height, value: AnsiText::blank($columns));
                $picture[\intdiv($height, num2: 2)] = FieldBox::centred($word, $columns, $dim);
            }
            foreach ($picture as $i => $line) {
                $band[$i] = AnsiText::padRight($band[$i], $used).StoreTile::cell($line, $this->direction());
            }
            $used += AnsiUtils::visibleWidth($picture[0]) + self::STRIP_GAP;
        }

        return ['', $this->heading($legend, $width), ...\array_map(static fn (string $line): string => ' '.AnsiText::truncate($line, $room), $band)];
    }

    /**
     * @param array<string, string|null> $assets
     *
     * @return array{list<string>, list<array{url: string, spans: list<array{int, int, int}>}>} its lines, and its links
     */
    private function whatsNew(StoreRow $row, int $width, array $assets): array
    {
        $entry = $row->entry;
        $when = $this->styles->of(Role::Dim)->apply($entry->version.($entry->time > 0 ? ' · '.\gmdate('Y-m-d', $entry->time) : ''));
        $lines = ['', $this->heading($this->translator->trans("What's New"), $width, $when)];
        $changelog = null === $entry->changelog ? null : $assets[$entry->changelog] ?? null;
        $section = null === $changelog ? '' : self::newestSection($changelog);
        [$body, $links] = match (true) {
            '' !== $section => $this->markdown($section, $width, (string) $entry->changelog, \count($lines)),
            null !== $entry->changelog && !\array_key_exists($entry->changelog, $assets) => [[' '.$this->styles->of(Role::Dim)->apply($this->translator->trans('Loading…'))], []],
            // No changelog to read: the release history says what it can.
            default => [$this->history($entry), []],
        };

        return [[...$lines, ...$body], $links];
    }

    /**
     * Markdown laid out a column in, as every line of the page is, and its
     * links: each with the address it leads to — a relative one read against
     * $base, the file it is in — and where it is drawn, $above lines down.
     *
     * @return array{list<string>, list<array{url: string, spans: list<array{int, int, int}>}>}
     */
    private function markdown(string $source, int $width, string $base, int $above): array
    {
        $doc = $this->markdown->render($source, \max(1, $width - 1));
        $links = [];
        for ($group = 0; $group < $doc->groupCount(); ++$group) {
            // A task's box or a `<details>` summary is the page's own action: its target is no address.
            $url = self::address((string) $doc->target($group), $base);
            if (null === $url) {
                continue;
            }
            $links[] = [
                'url' => $url,
                'spans' => \array_map(static fn (LinkSpan $span): array => [$span->line + $above, $span->col + 1, $span->endCol + 1], $doc->spansOfGroup($group)),
            ];
        }

        return [\array_map(static fn (string $line): string => ' '.$line, $doc->lines), $links];
    }

    /**
     * Where a link leads, for the browser: a web address as it is; one
     * relative to $base — a README's `docs/usage.md` — read against it, and a
     * file on GitHub as its page there; null for an anchor, a mail address or
     * anything else a browser should not be handed.
     */
    public static function address(string $target, string $base): ?string
    {
        $web = 1 === \preg_match('#^https?://#i', $target);
        // An anchor or a mail address resolves to nothing: no browser is handed either.
        $resolved = $web || '' === $base ? null : ReadmeImages::resolve($target, $base);
        // A file on GitHub is shown on its page there, not as its raw bytes.
        $page = null === $resolved ? null : \preg_replace('#^https://raw\.githubusercontent\.com/([^/]+/[^/]+)/([^/]+)/#', replacement: 'https://github.com/$1/blob/$2/', subject: $resolved);

        return $web ? $target : $page;
    }

    /**
     * The newest releases, each with its date.
     *
     * @return list<string>
     */
    private function history(StoreEntry $entry): array
    {
        $dim = $this->styles->of(Role::Dim);

        return \array_map(
            static fn (array $release): string => ' '.$release[0].($release[1] > 0 ? $dim->apply('  '.\gmdate('Y-m-d', $release[1])) : ''),
            \array_slice($entry->history, offset: 0, length: 8),
        );
    }

    /**
     * @param array<string, string|null> $assets
     *
     * @return array{list<string>, list<array{url: string, spans: list<array{int, int, int}>}>} its lines, and its links
     */
    private function about(StoreEntry $entry, int $width, array $assets): array
    {
        $readme = null === $entry->readme ? null : $assets[$entry->readme] ?? null;
        if (null === $readme) {
            return [[], []];
        }
        $source = \array_slice(\explode("\n", $readme), offset: 0, length: self::README_LINES);
        // The page already says what it is called.
        if (\str_starts_with($source[0] ?? '', '# ')) {
            \array_shift($source);
        }
        [$body, $links] = $this->markdown(\implode("\n", $source), $width, (string) $entry->readme, 2);

        return [['', $this->heading($this->translator->trans('About'), $width), ...$body], $links];
    }

    /** @return array{list<string>, list<array{url: string, spans: list<array{int, int, int}>}>} */
    private function information(StoreRow $row, int $width): array
    {
        $entry = $row->entry;
        $facts = [
            'Provider' => $entry->author,
            'Package' => $entry->package,
            'Category' => $this->translator->trans($entry->category->label()),
            'Version' =>
                $entry->version.('' !== $row->installed && $row->installed !== $entry->version ? ' ('.$this->translator->trans('%version% installed', ['%version%' => $row->installed]).')' : ''),
            'Updated' => $entry->time > 0 ? \gmdate('Y-m-d', $entry->time) : '',
            'License' => $entry->license,
            'Downloads' => $entry->downloads > 0 ? \number_format($entry->downloads) : '',
            'Source' => $entry->source,
        ];
        $labels = [];
        foreach ($facts as $label => $value) {
            if ('' === $value) {
                continue;
            }
            $labels[$this->translator->trans($label)] = $value;
        }
        $column = \max(\array_map(AnsiUtils::visibleWidth(...), [...\array_keys($labels), ''])) + 2;
        $lines = ['', $this->heading($this->translator->trans('Information'), $width)];
        $links = [];
        foreach ($labels as $label => $value) {
            $line = ' '.StoreTile::text($this->styles->of(Role::Dim)->apply($label), $column, $this->direction()).StoreTile::pinned($value, $this->direction());
            // The source and the package each lead to their page on the web.
            $url = match ($value) {
                $entry->source => $entry->source,
                $entry->package => 'https://packagist.org/packages/'.$entry->package,
                default => null,
            };
            $at = null === $url ? false : \mb_strpos(AnsiUtils::stripAnsiCodes($line), $value);
            if (null !== $url && false !== $at) {
                $col = AnsiUtils::visibleWidth(\mb_substr(AnsiUtils::stripAnsiCodes($line), start: 0, length: $at));
                $links[] = ['url' => $url, 'spans' => [[\count($lines), $col, $col + AnsiUtils::visibleWidth($value)]]];
            }
            $lines[] = $line;
        }

        return [$lines, $links];
    }

    /** A section's heading, bold, with $aside at the right end. */
    private function heading(string $title, int $width, string $aside = ''): string
    {
        $title = ' '.$this->styles->of(Role::Bright)->withBold()->apply($title);
        $room = \max(0, $width - AnsiUtils::visibleWidth($aside) - 1);

        return StoreTile::text($title, $room, $this->direction()).StoreTile::pinned($aside, $this->direction());
    }

    private function direction(): BaseDirection
    {
        return $this->translator->isRtl() ? BaseDirection::Rtl : BaseDirection::Ltr;
    }

    /**
     * The newest release's section of a Keep-a-Changelog file: the first
     * `## ` heading that names a version (not Unreleased), up to the next.
     */
    public static function newestSection(string $changelog): string
    {
        $section = [];
        $inside = false;
        foreach (\explode("\n", \str_replace("\r\n", replace: "\n", subject: $changelog)) as $line) {
            $heading = \str_starts_with($line, '## ');
            if ($heading && $inside) {
                break;
            }
            if ($heading && 1 === \preg_match('/\d/', $line) && false === \stripos($line, needle: 'unreleased')) {
                $inside = true;

                continue;
            }
            if ($inside) {
                $section[] = $line;
            }
        }

        return \trim(\implode("\n", $section));
    }
}
