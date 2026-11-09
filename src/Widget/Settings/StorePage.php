<?php

declare(strict_types=1);

namespace Porta\Widget\Settings;

use Porta\Help\Layout\ImageLayout;
use Porta\Help\Layout\PageLine;
use Porta\Help\MarkdownRenderer;
use Porta\I18n\BaseDirection;
use Porta\I18n\Translator;
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
     *
     * @return array{lines: list<string>, button: array{int, int, int, int}} the button as row, column, width, height
     */
    public function render(StoreRow $row, int $width, int $rows, int $shot, array $assets, bool $buttonHovered): array
    {
        $entry = $row->entry;
        $card = new StoreCard(
            $entry->title,
            $entry->verified ? $this->styles->of(Role::TabAccent)->apply(Glyphs::MD_CHECK.' '.$this->translator->trans('Verified')) : '',
            $entry->description,
            $this->translator->trans('by %author%', ['%author%' => $entry->author]),
            $this->translator->trans($row->action->caption()),
            $row->action->pressable(),
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
        $lines = [
            ...$lines,
            ...$this->strip($row, $width),
            ...$this->preview($entry, $width, $shot, $assets),
            ...$this->whatsNew($row, $width, $assets),
            ...$this->about($entry, $width, $assets),
            ...$this->information($row, $width),
        ];

        return [
            'lines' => \array_map(static fn (string $line): string => AnsiText::truncate($line, $width, pad: true), $lines),
            'button' => [$button[0], $button[1] + 1, $button[2], $button[3]],
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
     * @param array<string, string|null> $assets
     *
     * @return list<string>
     */
    private function preview(StoreEntry $entry, int $width, int $shot, array $assets): array
    {
        $url = $entry->screenshots[$shot] ?? null;
        if (null === $url) {
            return [];
        }
        $count = \count($entry->screenshots);
        $legend = $this->translator->trans('Preview').($count > 1 ? '  '.Glyphs::SCROLL_PREV.' '.($shot + 1).'/'.$count.' '.Glyphs::SCROLL_NEXT : '');
        $lines = ['', $this->heading($legend, $width)];
        $bytes = $assets[$url] ?? null;
        $image = null === $bytes ? null : $this->images->png($bytes, \max(1, $width - 2));
        $missing = \array_key_exists($url, $assets) ? $this->translator->trans('The screenshot could not be shown') : $this->translator->trans('Loading…');
        $shown = null === $image ? [' '.$this->styles->of(Role::Dim)->apply($missing)] : \array_map(static fn (PageLine $line): string => ' '.$line->text, $image);

        return [...$lines, ...$shown];
    }

    /**
     * @param array<string, string|null> $assets
     *
     * @return list<string>
     */
    private function whatsNew(StoreRow $row, int $width, array $assets): array
    {
        $entry = $row->entry;
        $when = $this->styles->of(Role::Dim)->apply($entry->version.($entry->time > 0 ? ' · '.\gmdate('Y-m-d', $entry->time) : ''));
        $lines = ['', $this->heading($this->translator->trans("What's New"), $width, $when)];
        $changelog = null === $entry->changelog ? null : $assets[$entry->changelog] ?? null;
        $section = null === $changelog ? '' : self::newestSection($changelog);
        $body = match (true) {
            '' !== $section => $this->markdown->render($section, $width)->lines,
            null !== $entry->changelog && !\array_key_exists($entry->changelog, $assets) => [' '.$this->styles->of(Role::Dim)->apply($this->translator->trans('Loading…'))],
            // No changelog to read: the release history says what it can.
            default => $this->history($entry),
        };

        return [...$lines, ...$body];
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
     * @return list<string>
     */
    private function about(StoreEntry $entry, int $width, array $assets): array
    {
        $readme = null === $entry->readme ? null : $assets[$entry->readme] ?? null;
        if (null === $readme) {
            return [];
        }
        $source = \array_slice(\explode("\n", $readme), offset: 0, length: self::README_LINES);
        // The page already says what it is called.
        if (\str_starts_with($source[0] ?? '', '# ')) {
            \array_shift($source);
        }

        return ['', $this->heading($this->translator->trans('About'), $width), ...$this->markdown->render(\implode("\n", $source), $width)->lines];
    }

    /** @return list<string> */
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
        foreach ($labels as $label => $value) {
            $lines[] = ' '.StoreTile::text($this->styles->of(Role::Dim)->apply($label), $column, $this->direction()).StoreTile::pinned($value, $this->direction());
        }

        return $lines;
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
