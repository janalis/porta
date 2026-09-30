<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Editor\PreviewMode;
use Porta\Event\PreviewModeRequested;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Ui\ClickZones;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Support\ColumnHover;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * One-line breadcrumbs bar under the tabs showing the caret's structural path
 * (`Namespace › Class › method`). Content comes from a provider closure so it
 * tracks the caret for free on every frame. Renders zero rows (no bar) when the
 * caret is not inside any scope, so a structureless file keeps its full editor.
 *
 * A file with a live preview also gets the arrangement toggles at the bar's
 * right end — ` Editor [Split] Preview ` — and so always has the bar: those
 * files (markdown, SVG, a graph) rarely have a scope to show, and the toggles
 * are how the preview is reached with the pointer. They sit on this row
 * because it is the one that stays put while the slot under it changes from
 * the editor, to the split, to the preview alone.
 */
#[AsWidget('breadcrumbs')]
final class BreadcrumbsWidget extends AbstractWidget implements MenuOverlayHost, TooltipProvider
{
    use ColumnHover;
    use MenuOverlayHostTrait;

    /** @use ProviderFingerprint<list<array{label: string, line: int}>> */
    use ProviderFingerprint;
    use StylesDefault;

    /** The "Interface > Breadcrumbs" toggle; when off the bar renders no rows. */
    private bool $visible = true;

    /** The active buffer's arrangement, or null when it has no preview (and so no toggles). */
    private ?\Closure $previewProvider = null;

    /** The toggles' columns in the last render, by the mode each one picks. */
    private readonly ClickZones $toggleZones;

    public function __construct(
        ?Styles $styles = null,
        private readonly Translator $translator = new Translator('en'),
        private readonly ?EventDispatcherInterface $events = null,
    ) {
        $this->initStyles($styles);
        $this->toggleZones = new ClickZones();
    }

    /** @param \Closure(): ?PreviewMode $provider */
    public function setPreviewProvider(\Closure $provider): void
    {
        $this->previewProvider = $provider;
    }

    public function show(): void
    {
        if (!$this->visible) {
            $this->visible = true;
            $this->invalidate();
        }
    }

    public function hide(): void
    {
        if ($this->visible) {
            $this->visible = false;
            $this->invalidate();
        }
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** The ` › ` scope separator, dimmed (visible width 3). */
    private function separator(): string
    {
        return $this->styles->of(Role::Gutter)->apply(' '.Glyphs::BREADCRUMB_SEPARATOR.' ');
    }

    /** @var list<array{start: int, width: int, line: int}> segment layout from the last render, for hit-testing */
    private array $lastLayout = [];

    /** @var list<array{start: int, width: int, key: string, text: string}> what the last render hid or cut, where it would have been */
    private array $tips = [];

    /**
     * The `…` says which ancestors it stands for; a current scope cut in the
     * middle says its whole name. A crumb drawn whole needs no card.
     */
    #[\Override]
    public function tooltipAt(int $row, int $col): ?Tooltip
    {
        $tip = 0 === $row ? \array_find($this->tips, static fn (array $tip): bool => $col >= $tip['start'] && $col < ($tip['start'] + $tip['width'])) : null;

        return null === $tip ? null : new Tooltip('crumbs:'.$tip['key'], $tip['text'], 0, $tip['start'], $tip['start'] + $tip['width']);
    }

    #[\Override]
    public function beforeRender(): void
    {
        // The visible gate is load-bearing: the provider tokenizes the whole
        // file, and a hidden bar must cost nothing — render() drawing no rows
        // does not stop beforeRender from running every frame.
        if (!$this->visible) {
            return;
        }
        // The hover column re-resolves per frame, so a relabel needs no
        // clearHover() — a still-correct tint stays, a stale one falls off.
        // The arrangement is part of what the bar shows, so it is part of the
        // fingerprint: a toggle flipped by its key must repaint the tags.
        /** @var ?PreviewMode $mode */
        $mode = null !== $this->previewProvider ? ($this->previewProvider)() : null;
        $this->refreshFingerprint([null !== $this->provider ? ($this->provider)() : [], $mode?->name]);
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->lastLayout = [];
        $this->tips = [];
        $this->toggleZones->clear();
        if (!$this->visible) {
            return []; // the Interface setting hides the bar entirely
        }
        $crumbs = null !== $this->provider ? ($this->provider)() : [];
        /** @var ?PreviewMode $mode */
        $mode = null !== $this->previewProvider ? ($this->previewProvider)() : null;
        if ([] === $crumbs && null === $mode) {
            return []; // no scope, no preview → no bar (the editor keeps the row)
        }

        $columns = $context->getColumns();
        $toggles = null === $mode ? '' : $this->toggles($mode, $columns);
        $trail = $columns - AnsiUtils::visibleWidth($toggles);
        $content = [] === $crumbs ? ' ' : $this->trail($crumbs, $trail);

        return [$this->styles->of(Role::Toolbar)->apply(AnsiText::truncate($content, $trail, pad: true).$toggles)];
    }

    /**
     * The crumbs laid out in $columns, from the right, recording each one's
     * columns for the click and the hover.
     *
     * @param non-empty-list<array{label: string, line: int}> $crumbs
     */
    private function trail(array $crumbs, int $columns): string
    {
        [$first, $room] = self::window(\array_column($crumbs, 'label'), $columns - 1);
        $content = ' '; // a leading space; the bar background wraps the padded row
        $col = 1;
        if ($first > 0) {
            // The ancestors that did not fit, said once: the trail is cut at
            // its root end, never at the scope the caret is in.
            $content .= $this->styles->of(Role::Muted)->apply(Glyphs::ELLIPSIS).$this->separator();
            $this->tips[] = [
                'start' => 1,
                'width' => 1,
                'key' => 'lead',
                'text' => \implode(' '.Glyphs::BREADCRUMB_SEPARATOR.' ', \array_column(\array_slice($crumbs, offset: 0, length: $first), 'label')),
            ];
            $col += self::LEAD_WIDTH;
        }
        $last = \count($crumbs) - 1;
        foreach (\array_slice($crumbs, $first, preserve_keys: true) as $i => $crumb) {
            if ($i > $first) {
                $content .= $this->separator();
                $col += 3;
            }
            // Alone and still too wide, the current crumb keeps both ends.
            $label = $i === $last ? AnsiText::elideMiddle($crumb['label'], $room) : $crumb['label'];
            $width = AnsiUtils::visibleWidth($label);
            $this->lastLayout[] = ['start' => $col, 'width' => $width, 'line' => $crumb['line']];
            if ($label !== $crumb['label']) {
                $this->tips[] = ['start' => $col, 'width' => $width, 'key' => 'current', 'text' => $crumb['label']];
            }
            // Bar chrome, so the pointer tints with BarHoverBg (not a list
            // row's RowHighlightBg) under whichever foreground the crumb has,
            // and underlines it: a crumb is a link.
            $hovered = null !== $this->hoverCol && $this->hoverCol >= $col && $this->hoverCol < ($col + $width);
            $content .= $this->crumbStyle($i === $last, $hovered)->apply($label);
            $col += $width;
        }

        return $content;
    }

    /**
     * The three arrangement toggles as one-row tags, right-aligned with a gap
     * after each, their columns recorded — or nothing when the bar is too
     * narrow to seat them whole, since a tag drawn past the edge is neither
     * readable nor clickable.
     */
    private function toggles(PreviewMode $shown, int $columns): string
    {
        $tags = [];
        $width = 0;
        foreach (PreviewMode::cases() as $mode) {
            $caption = $this->caption($mode);
            $tags[] = [$mode, $caption];
            $width += FieldBox::toggleWidth($caption, rows: 1) + 1;
        }
        $col = $columns - $width;
        if ($col < 1) {
            return '';
        }
        $line = '';
        foreach ($tags as $index => [$mode, $caption]) {
            $tagWidth = FieldBox::toggleWidth($caption, rows: 1);
            $hovered = null !== $this->hoverCol && $this->hoverCol >= $col && $this->hoverCol < ($col + $tagWidth);
            $this->toggleZones->add((string) $index, $col, $col + $tagWidth);
            $line .= FieldBox::toggle($caption, OptionTagState::of($mode === $shown, $hovered), 1, $this->styles)[0].' ';
            $col += $tagWidth + 1;
        }

        return $line;
    }

    private function caption(PreviewMode $mode): string
    {
        return $this->translator->trans(match ($mode) {
            PreviewMode::Editor => 'Editor',
            PreviewMode::Split => 'Split',
            PreviewMode::Preview => 'Preview',
        });
    }

    /** The arrangement the toggle at column $col of the last render picks, or null between them. */
    public function previewModeAt(int $col): ?PreviewMode
    {
        $index = $this->toggleZones->at($col);

        return null === $index ? null : PreviewMode::cases()[(int) $index];
    }

    /**
     * A press at column $col: a toggle asks for its arrangement, which the
     * buffer service performs. Returns whether a toggle was under it.
     */
    public function pressToggle(int $col): bool
    {
        $mode = $this->previewModeAt($col);
        if (null === $mode) {
            return false;
        }
        $this->events?->dispatch(new PreviewModeRequested($mode));

        return true;
    }

    /** `… › `: what stands for the ancestors the bar had no room for. */
    private const int LEAD_WIDTH = 4;

    /**
     * Which crumbs fit in $room columns, laid out from the right: the first
     * one drawn, and the room left for the last. The current scope is always
     * drawn; each ancestor joins only while it, its separator and — when
     * ancestors remain beyond it — the `… › ` lead still fit.
     *
     * @param list<string> $labels
     *
     * @return array{int, int}
     */
    private static function window(array $labels, int $room): array
    {
        $first = \count($labels) - 1;
        $used = AnsiUtils::visibleWidth($labels[$first]);
        while ($first > 0) {
            $next = $used + 3 + AnsiUtils::visibleWidth($labels[$first - 1]);
            if (($next + ($first > 1 ? self::LEAD_WIDTH : 0)) > $room) {
                break;
            }
            --$first;
            $used = $next;
        }

        return [$first, \max(1, $room - ($first > 0 ? self::LEAD_WIDTH : 0))];
    }

    /**
     * A crumb's shading: the current scope is bright and bold, the ancestors
     * muted, and the one under the pointer takes the bar-chrome hover shade
     * under whichever of those foregrounds it already had, underlined.
     */
    private function crumbStyle(bool $current, bool $hovered): Style
    {
        return match (true) {
            $current && $hovered => $this->styles->merge(Role::BarHoverBg, Role::Emphasis)->withBold()->withUnderline(),
            $current => $this->styles->of(Role::Emphasis)->withBold(),
            $hovered => $this->styles->merge(Role::BarHoverBg, Role::Muted)->withUnderline(),
            default => $this->styles->of(Role::Muted),
        };
    }

    /**
     * Tint the crumb or toggle under the pointer (visual only). Stores the
     * column; change at item granularity — a toggle resolves below zero, so it
     * never shares an index with a crumb.
     */
    public function hoverAt(int $col): bool
    {
        $toggle = $this->toggleZones->at($col);

        return $this->storeHover($col, null === $toggle ? $this->crumbIndexAt($col) : -1 - (int) $toggle);
    }

    /** The jump line of the crumb at column $col of the last render, or null. */
    public function lineAtColumn(int $col): ?int
    {
        $index = $this->crumbIndexAt($col);

        return null !== $index ? $this->lastLayout[$index]['line'] : null;
    }

    /** Index into the last render's segment layout at column $col, or null between/past the crumbs. */
    private function crumbIndexAt(int $col): ?int
    {
        foreach ($this->lastLayout as $index => $entry) {
            if ($col >= $entry['start'] && $col < ($entry['start'] + $entry['width'])) {
                return $index;
            }
        }

        return null;
    }
}
