<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Editor\PreviewMode;
use Porta\Event\PreviewModeRequested;
use Porta\I18n\Translator;
use Porta\Ui\ClickZones;
use Porta\Ui\Density;
use Porta\Ui\ScreenDensity;
use Porta\Ui\Theme\Styles;
use Porta\Ui\Tooltip;
use Porta\Ui\TooltipProvider;
use Porta\Widget\Render\CrumbBar;
use Porta\Widget\Render\FieldBox;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\Render\Rule;
use Porta\Widget\Support\ColumnHover;
use Porta\Widget\Support\OptionTagState;
use Porta\Widget\Support\ProviderFingerprint;
use Porta\Widget\Support\StylesDefault;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The navigation bar under the tabs: where the caret is, from the project
 * root down — `src › Http › UserController.php › UserController › store` —
 * drawn by {@see CrumbBar} as pills, the current scope lit like the active
 * tab. Content comes from a provider closure so it tracks the caret for free
 * on every frame. A folder or the file reveals itself in the tree when
 * clicked; a scope moves the caret to it. Renders zero rows (no bar) when
 * there is nothing to show, so a viewer tab keeps its full pane.
 *
 * On a roomy screen a rule under the bar closes it off from the editor; on a
 * compact one the rule gives its row back, as the workspace edges do.
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

    /** @use ProviderFingerprint<list<array{label: string, line: ?int, path?: ?string}>> */
    use ProviderFingerprint;
    use StylesDefault;

    /**
     * The "Interface > Breadcrumbs" toggle; when off the bar draws no trail.
     * A previewed file keeps its arrangement toggles even then: they are the
     * one pointer route to the preview, and not what the setting is about.
     */
    private bool $visible = true;

    /** The active buffer's arrangement, or null when it has no preview (and so no toggles). */
    private ?\Closure $previewProvider = null;

    /** The toggles' columns in the last render, by the mode each one picks. */
    private readonly ClickZones $toggleZones;

    public function __construct(
        ?Styles $styles = null,
        private readonly Translator $translator = new Translator('en'),
        private readonly ?EventDispatcherInterface $events = null,
        // Trailing and optional, as the widget rule has it: the rule under
        // the bar is drawn only on a roomy screen, and a bar built bare has
        // no screen to ask, so it draws none.
        private readonly ?ScreenDensity $density = null,
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

    /** @var array<int, array{start: int, width: int}> each drawn crumb's columns in the last render, by its index in the trail */
    private array $zones = [];

    /** @var list<array{label: string, line: ?int, path?: ?string}> the trail the last render drew, for the click */
    private array $drawn = [];

    /** The density the last render drew for, so a flip repaints. */
    private ?Density $drawnDensity = null;

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
        // Runs every frame, so the trail goes through trailCrumbs(), whose
        // visible gate keeps a hidden trail from tokenizing the file.
        // The hover column re-resolves per frame, so a relabel needs no
        // clearHover() — a still-correct tint stays, a stale one falls off.
        // The arrangement is part of what the bar shows, so it is part of the
        // fingerprint: a toggle flipped by its key must repaint the tags.
        /** @var ?PreviewMode $mode */
        $mode = null !== $this->previewProvider ? ($this->previewProvider)() : null;
        $this->refreshFingerprint([$this->trailCrumbs(), $mode?->name]);
        if (null !== $this->drawnDensity && $this->drawnDensity !== $this->density?->current()) {
            $this->invalidate();
        }
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $this->zones = [];
        $this->tips = [];
        $this->toggleZones->clear();
        $this->drawnDensity = $this->density?->current();
        $this->drawn = $this->trailCrumbs();
        /** @var ?PreviewMode $mode */
        $mode = null !== $this->previewProvider ? ($this->previewProvider)() : null;
        if ([] === $this->drawn && null === $mode) {
            return []; // nothing to show → no bar (the pane keeps the row)
        }

        $columns = $context->getColumns();
        $toggles = null === $mode ? '' : $this->toggles($mode, $columns);
        $bar = CrumbBar::render($this->crumbs($this->drawn), $columns, $this->styles, $this->hoverCol, $toggles);
        $this->zones = $bar['zones'];
        $this->tips = $bar['tips'];
        $rows = [$bar['line']];
        if (Density::Roomy === $this->drawnDensity) {
            $rows[] = Rule::horizontal($columns, $this->styles);
        }

        return $rows;
    }

    /**
     * The trail as {@see CrumbBar} draws it: the file — the last crumb with a
     * path — keeps a bright foreground, the seam between where the file is
     * and where in it the caret is.
     *
     * @param list<array{label: string, line: ?int, path?: ?string}> $trail
     *
     * @return list<array{label: string, emphasis: bool}>
     */
    private function crumbs(array $trail): array
    {
        $file = \array_key_last(\array_filter($trail, static fn (array $crumb): bool => null !== ($crumb['path'] ?? null)));
        $crumbs = [];
        foreach ($trail as $i => $crumb) {
            $crumbs[] = ['label' => $crumb['label'], 'emphasis' => $i === $file];
        }

        return $crumbs;
    }

    /**
     * The trail to draw — none while the Interface setting hides it. The
     * visible gate is load-bearing: the provider tokenizes the whole file, and
     * a hidden trail must cost nothing, per frame included.
     *
     * @return list<array{label: string, line: ?int, path?: ?string}>
     */
    private function trailCrumbs(): array
    {
        return $this->visible && null !== $this->provider ? ($this->provider)() : [];
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

    /** The jump line of the scope crumb at column $col of the last render, or null. */
    /**
     * The trail as the last render drew it, crumb by crumb — the keyboard's
     * way along it ({@see \Porta\Workbench\Menu\ContextMenuOpener::openNavigationBar()}).
     *
     * @return list<array{label: string, line: ?int, path?: ?string}>
     */
    public function trail(): array
    {
        return $this->drawn;
    }

    public function lineAtColumn(int $col): ?int
    {
        $index = $this->crumbIndexAt($col);

        return null !== $index ? $this->drawn[$index]['line'] : null;
    }

    /** The folder or file of the path crumb at column $col of the last render, or null. */
    public function pathAtColumn(int $col): ?string
    {
        $index = $this->crumbIndexAt($col);

        return null !== $index ? $this->drawn[$index]['path'] ?? null : null;
    }

    /** Index into the last render's trail of the crumb drawn at column $col, or null between/past the crumbs. */
    private function crumbIndexAt(int $col): ?int
    {
        return CrumbBar::at($this->zones, $col);
    }
}
