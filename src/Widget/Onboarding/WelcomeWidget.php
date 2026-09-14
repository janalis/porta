<?php

declare(strict_types=1);

namespace Porta\Widget\Onboarding;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Onboarding\WelcomeStep;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\ListNavigation;
use Porta\Ui\ListSelection;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\HoverableList;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Support\ArrowScrollTrait;
use Porta\Widget\Support\FillsPaneTrait;
use Porta\Widget\Support\HoverTrait;
use Porta\Widget\Support\InputHook;
use Porta\Widget\Support\StylesDefault;
use Porta\Widget\Support\WheelScrollable;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The Welcome screen's content: a hero greeting, then a card per setup step in
 * a centred, width-capped content column. Never mounted in the widget tree —
 * {@see \Porta\Overlay\Presenter\WelcomeModalController} renders it off-tree and wraps
 * it in the shared modal frame, whose title bar carries {@see title()}.
 *
 * A step is a marker-and-title line — `[x] title  [ action ]` — with its detail
 * word-wrapped beneath at the marker's indent, so cards vary in height and no
 * text is silently lost to a narrow pane. The action label is protected: the
 * *title* truncates first, because `[ action ]` is the affordance and a card
 * whose button vanished reads as done. The selection is an accent bar
 * ({@see Glyphs::CARD_ACCENT}) down the selected card's left edge with the
 * title emphasised and a subtle `.card::selected(:focus)` tint behind — the
 * bar and the space after it replace the last two margin columns, so
 * selecting a card never changes any line's width or the card's height. The
 * hero row is decoration and is dropped below {@see self::HERO_MIN_ROWS}
 * pane rows, where steps need the space.
 *
 * Done, pending and link steps are marked with bracketed glyphs
 * (`[x]`/`[ ]`/`[→]`) rather than colour. That is the house accessibility
 * rule: under `--no-ansi` and the high-contrast theme no meaning may rest on
 * colour alone, and the brackets are precisely what survives.
 *
 * Completion is asked of each {@see WelcomeStep} at render time rather than
 * tracked here, so a step satisfied elsewhere (Settings, a plugin page) reads
 * as done the moment the user comes back. Link steps (no completion state)
 * never count toward the title-bar progress.
 */
#[AsWidget('welcome', flex: 1)]
final class WelcomeWidget extends AbstractWidget implements FocusableInterface, HoverableList, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;
    use StylesDefault;

    /** The content column never grows past this many columns — long lines are hard to scan. */
    private const int CONTENT_MAX = 72;

    /** Minimum margin on either side of the content column. */
    private const int H_MARGIN = 2;

    /** Below this many pane rows the hero greeting is dropped: steps need every row. */
    private const int HERO_MIN_ROWS = 10;

    private readonly ListSelection $list;

    /** @var list<WelcomeStep> */
    private array $steps = [];

    /**
     * Which step owns each rendered row, built during render.
     *
     * The layout is not a row per step, so a pointer row cannot be turned into
     * a step by arithmetic. Recording the mapping as the lines are built keeps
     * the two in step by construction — otherwise a layout tweak silently
     * misroutes clicks while still looking right.
     *
     * @var list<int|null>
     */
    private array $rowOwner = [];

    /** Pane height of the last render, for paging. */
    /** Cards that fitted in the last render — the page size; see {@see body()}. */
    private int $lastPage = 1;

    /** @var (callable(): void)|null a step ran, so the view can refresh */
    private $onRan;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->initStyles($styles);
        $this->list = new ListSelection();
        $this->addStyleClass('card'); // the theme's .card::selected(:focus) rules tint the selection behind the accent bar
    }

    /** @param list<WelcomeStep> $steps */
    public function setSteps(array $steps): void
    {
        $this->steps = $steps;
        $this->list->clamp(\count($steps));
        $this->invalidate();
    }

    /**
     * Fresh-open state: window at the top, hover cleared, selection on the
     * first step still needing the user (all done → the first step).
     *
     * Only {@see \Porta\Overlay\Presenter\WelcomeModalController::open()} calls this —
     * the widget outlives the modal (it is a shared service), so without it a
     * reopen would resume wherever the last visit left off. {@see setSteps()}
     * deliberately stays clamp-only so the refresh after running a step never
     * jumps the selection mid-session.
     */
    public function resetView(): void
    {
        $this->list->reset();
        foreach ($this->steps as $index => $step) {
            if (!$step->isCheckable() || $step->isDone()) {
                continue;
            }
            $this->list->select($index);
            break;
        }
        $this->clearHover();
        $this->invalidate();
    }

    /** @param callable(): void $callback */
    public function onRan(callable $callback): self
    {
        $this->onRan = $callback;

        return $this;
    }

    #[\Override]
    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = \max(1, $context->getRows() > 0 ? $context->getRows() : 12);

        $lines = [];
        $this->rowOwner = [];

        foreach ($this->body($width, $rows) as [$line, $owner]) {
            $lines[] = $line;
            $this->rowOwner[] = $owner;
        }

        while (\count($lines) < $rows) {
            $lines[] = AnsiText::blank($width);
            $this->rowOwner[] = null;
        }

        return \array_slice($lines, offset: 0, length: $rows);
    }

    /** The modal frame's title-bar text: the progress count and the key hints. */
    public function title(): string
    {
        $checkable = \array_filter($this->steps, static fn (WelcomeStep $step): bool => $step->isCheckable());
        $total = \count($checkable);
        if (0 === $total) {
            return $this->translator->trans('Welcome');
        }

        return $this->translator->trans('Welcome — %done% of %total% done · ⏎ run · ↑↓ move', [
            '%done%' => \count(\array_filter($checkable, static fn (WelcomeStep $step): bool => $step->isDone())),
            '%total%' => $total,
        ]);
    }

    /**
     * The hero, the step cards, and the messages that frame them.
     *
     * Each entry pairs a line with the step it belongs to (null for the hero,
     * spacers and messages), so {@see render()} records the row map without a
     * second pass. A list of pairs rather than a line-keyed map: spacer lines
     * are identical strings, and array keys would silently collapse them into
     * one.
     *
     * @return list<array{0: string, 1: int|null}>
     */
    private function body(int $width, int $rows): array
    {
        $content = \max(1, \min($width - (2 * self::H_MARGIN), self::CONTENT_MAX));
        $margin = \str_repeat(' ', \max(self::H_MARGIN, \intdiv($width - $content, num2: 2)));

        $body = $rows >= self::HERO_MIN_ROWS ? $this->hero($width) : [];
        $chrome = \count($body); // the hero block (the title bar belongs to the modal frame)

        if ([] === $this->steps) {
            $body[] = $this->message($this->translator->trans('Nothing to set up.'), $width, $margin);

            return $body;
        }
        $checkable = \array_filter($this->steps, static fn (WelcomeStep $step): bool => $step->isCheckable());
        if ([] !== $checkable && [] === \array_filter($checkable, static fn (WelcomeStep $step): bool => !$step->isDone())) {
            // A banner, not a replacement: the cards — their [x]s, and any link
            // step — stay visible and navigable beneath it.
            $body[] = $this->message($this->translator->trans('All set. Reopen this any time from Help.'), $width, $margin);
            $body[] = [AnsiText::blank($width), null];
            $chrome += 2;
        }

        $cards = [];
        foreach ($this->steps as $index => $step) {
            $cards[] = $this->card($step, $index, $width, $content, $margin);
        }
        $heights = \array_map(\count(...), $cards);
        $budget = \max(1, $rows - $chrome);
        $scroll = $this->list->followHeights($heights, $budget);
        // How many cards FIT from here — which is what a page IS. This list is
        // measured in cards and drawn in rows, so the pane height is not a page
        // in any unit: with a three-row card in a six-row pane, Page Down moved
        // six cards while the screen showed two, and the four in between were
        // never drawn.
        $this->lastPage = $this->list->countFitting($heights, $scroll, $budget);

        foreach (\array_slice($cards, $scroll) as $card) {
            foreach ($card as $pair) {
                $body[] = $pair;
            }
        }

        return $body;
    }

    /**
     * The greeting block above the cards: blank, centred title, blank.
     *
     * @return list<array{0: string, 1: null}>
     */
    private function hero(int $width): array
    {
        $title = $this->translator->trans('Welcome to Porta');
        $pad = \str_repeat(' ', \max(0, \intdiv($width - AnsiUtils::visibleWidth($title), num2: 2)));

        return [
            [AnsiText::blank($width), null],
            [AnsiText::truncate($pad.$this->styles->of(Role::Bright)->withBold()->apply($title), $width, pad: true), null],
            [AnsiText::blank($width), null],
        ];
    }

    /** @return array{0: string, 1: null} */
    private function message(string $text, int $width, string $margin): array
    {
        return [AnsiText::truncate($margin.$this->styles->of(Role::Dim)->apply($text), $width, pad: true), null];
    }

    /**
     * One step's rows: the marker-and-title line, the wrapped detail lines,
     * then a spacer. Every text row carries the step's index so a click lands
     * anywhere on the card.
     *
     * @return non-empty-list<array{0: string, 1: int|null}>
     */
    private function card(WelcomeStep $step, int $index, int $width, int $content, string $margin): array
    {
        $marker = match (true) {
            !$step->isCheckable() => Glyphs::LINK_MARKER,
            $step->isDone() => Glyphs::CHECKBOX_ON,
            default => Glyphs::CHECKBOX_OFF,
        };
        $prefix = $marker.'  ';
        $suffix = $step->isDone() ? '' : '  ['.$step->actionLabel.']';

        // The action label is protected: give it its room first and truncate
        // the title into what remains, falling back to whole-line truncation
        // only when the pane cannot even hold the marker and the action.
        $room = $content - AnsiUtils::visibleWidth($prefix) - AnsiUtils::visibleWidth($suffix);
        $title = $room > 0
            ? $prefix.AnsiText::truncate($step->title, $room).$suffix
            : AnsiText::truncate($prefix.$step->title.$suffix, $content);

        $rows = [[$this->paint($margin, $title, $index, $width, isTitle: true), $index]];

        $indent = \str_repeat(' ', \max(0, AnsiUtils::visibleWidth($prefix)));
        $dim = $this->styles->of(Role::Dim);
        foreach (AnsiText::wrap($step->detail, \max(1, $content - AnsiUtils::visibleWidth($prefix))) as $chunk) {
            $rows[] = [$this->paint($margin, $indent.$dim->apply($chunk), $index, $width), $index];
        }
        $rows[] = [AnsiText::blank($width), null];

        return $rows;
    }

    /**
     * Assemble and style one card row. Every row of the selected card gets the
     * accent bar — the bar and its trailing space replace the last two margin
     * columns ({@see self::H_MARGIN} guarantees the room), so the visible
     * width, the wrap widths and the card heights never depend on which card
     * is selected. Selection outranks hover, as everywhere.
     */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the domain here, not a hidden second responsibility.
    private function paint(string $margin, string $body, int $index, int $width, bool $isTitle = false): string
    {
        if (!$this->list->isSelected($index)) {
            $text = AnsiText::truncate($margin.$body, $width, pad: true);

            return $index === $this->hoveredIndex ? $this->styles->of(Role::RowHighlightBg)->apply($text) : $text;
        }
        if ($isTitle) {
            $body = $this->styles->of(Role::Bright)->withBold()->apply($body);
        }
        $bar = $this->styles->of(Role::TabAccent)->withBold()->apply(Glyphs::CARD_ACCENT);
        $text = AnsiText::truncate(\substr($margin, offset: 0, length: -2).$bar.' '.$body, $width, pad: true);

        return $this->applyElement('selected', $text);
    }

    #[\Override]
    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            $this->fire($this->onExit);

            return;
        }
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->steps), page: $this->lastPage)) {
            $this->invalidate();

            return;
        }
        if ($kb->matches($data, 'confirm')) {
            $this->runSelected();
        }
    }

    /** Selecting is all a click does — running a step installs software. */
    public function handleClick(int $row): void
    {
        $index = $this->rowOwner[$row] ?? null;
        if (null === $index) {
            return;
        }

        $this->list->select($index);
        $this->invalidate();
    }

    /** Spacer and message rows own no step, so hovering them clears the tint. */
    #[\Override]
    public function hoverRow(int $relRow): bool
    {
        return $this->setHovered($this->rowOwner[$relRow] ?? null);
    }

    /** Running a finished step is a no-op, so a stray Enter cannot undo anything. */
    private function runSelected(): void
    {
        $step = $this->steps[$this->list->selected()] ?? null;
        if (null === $step || $step->isDone()) {
            return;
        }

        $step->run();
        $this->invalidate();
        $this->fire($this->onRan);
    }

    /**
     * No `focus_next_pane`: this view is a single pane, so Tab would have
     * nowhere to go, and a key that silently does nothing is worse than one
     * that is not bound.
     *
     * @return array<string, string[]>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'page_up' => [Key::PAGE_UP],
            'page_down' => [Key::PAGE_DOWN],
            'confirm' => [Key::ENTER, Key::SPACE],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
