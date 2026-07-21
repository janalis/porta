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
use Porta\Widget\ArrowScrollTrait;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\HoverableList;
use Porta\Widget\HoverTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\HeaderRenderer;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Porta\Widget\WheelScrollable;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * The Welcome screen: a title bar, then a card per setup step.
 *
 * A step is two lines — `[x] title  [ action ]`, then its detail indented
 * beneath — so the detail, which is the part explaining why the step matters,
 * is not the first thing a narrow pane truncates. The selection highlight
 * covers both lines so a step reads as one block.
 *
 * Done and pending are marked with the checkbox glyphs rather than colour.
 * That is the house accessibility rule: under `--no-ansi` and the
 * high-contrast theme no meaning may rest on colour alone, and `[x]`/`[ ]` are
 * bracketed precisely so the state survives.
 *
 * Completion is asked of each {@see WelcomeStep} at render time rather than
 * tracked here, so a step satisfied elsewhere (Settings, a plugin page) reads
 * as done the moment the user comes back.
 */
#[AsWidget('welcome', flex: 1)]
final class WelcomeWidget extends AbstractWidget implements FocusableInterface, HoverableList, MenuOverlayHost, VerticallyExpandableInterface, WheelScrollable
{
    use ArrowScrollTrait;
    use FillsPaneTrait;
    use FocusableTrait;
    use HoverTrait;
    use InputHook;
    use KeybindingsTrait;
    use MenuOverlayHostTrait;
    use PaneNavigationCallbacks;

    /** Title, detail, spacer — the vertical footprint of one step. */
    private const int ROWS_PER_STEP = 3;

    /** Where a step's text starts, leaving room for the `[x] ` marker. */
    private const string DETAIL_INDENT = '       ';

    private readonly Styles $styles;
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
    private int $lastRows = 12;

    /** @var (callable(): void)|null a step ran, so the view can refresh */
    private $onRan = null;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->list = new ListSelection();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @param list<WelcomeStep> $steps */
    public function setSteps(array $steps): void
    {
        $this->steps = $steps;
        $this->list->clamp(\count($steps));
        $this->invalidate();
    }

    /** @param callable(): void $callback */
    public function onRan(callable $callback): self
    {
        $this->onRan = $callback;

        return $this;
    }

    public function render(RenderContext $context): array
    {
        $width = $context->getColumns();
        $rows = max(1, $context->getRows() > 0 ? $context->getRows() : 12);
        $this->lastRows = $rows;

        $lines = [HeaderRenderer::render($this->title(), $width, $this->styles->of(Role::HeaderBar)->withBold(), suffix: 'Esc')];
        $this->rowOwner = [null];

        foreach ($this->body($width, $rows) as [$line, $owner]) {
            $lines[] = $line;
            $this->rowOwner[] = $owner;
        }

        while (\count($lines) < $rows) {
            $lines[] = str_repeat(' ', max(0, $width));
            $this->rowOwner[] = null;
        }

        return $this->withMenuOverlay(\array_slice($lines, offset: 0, length: $rows), $width);
    }

    private function title(): string
    {
        $total = \count($this->steps);
        if (0 === $total) {
            return ' '.$this->translator->trans('Welcome');
        }

        return ' '
        .$this->translator->trans('Welcome — %done% of %total% done · ⏎ run · ↑↓ move', [
            '%done%' => \count(array_filter($this->steps, static fn (WelcomeStep $step): bool => $step->isDone())),
            '%total%' => $total,
        ]);
    }

    /**
     * The step cards, or the message that stands in for them.
     *
     * Each entry pairs a line with the step it belongs to (null for spacers and
     * messages), so {@see render()} records the row map without a second pass.
     * A list of pairs rather than a line-keyed map: spacer lines are identical
     * strings, and array keys would silently collapse them into one.
     *
     * @return list<array{0: string, 1: int|null}>
     */
    private function body(int $width, int $rows): array
    {
        if ([] === $this->steps) {
            return $this->message($this->translator->trans('Nothing to set up.'), $width);
        }
        if ([] === array_filter($this->steps, static fn (WelcomeStep $step): bool => !$step->isDone())) {
            return $this->message($this->translator->trans('All set. Reopen this any time from Help.'), $width);
        }

        // One row goes to the title bar; each step needs a card's worth.
        $visible = max(1, intdiv($rows - 1, self::ROWS_PER_STEP));
        $scroll = $this->list->follow($visible);

        $body = [];
        for ($slot = 0; $slot < $visible; ++$slot) {
            $index = $scroll + $slot;
            $step = $this->steps[$index] ?? null;
            if (null === $step) {
                break;
            }

            $body[] = [$this->titleLine($step, $index, $width), $index];
            $body[] = [$this->detailLine($step, $index, $width), $index];
            $body[] = [str_repeat(' ', max(0, $width)), null];
        }

        return $body;
    }

    /** @return list<array{0: string, 1: int|null}> */
    private function message(string $text, int $width): array
    {
        return [[AnsiText::truncate(' '.$this->styles->of(Role::Dim)->apply($text), $width, pad: true), null]];
    }

    private function titleLine(WelcomeStep $step, int $index, int $width): string
    {
        $marker = $step->isDone() ? Glyphs::CHECKBOX_ON : Glyphs::CHECKBOX_OFF;
        $text = '  '.$marker.'  '.$step->title;
        if (!$step->isDone()) {
            $text .= '  ['.$step->actionLabel.']';
        }

        return $this->paint(AnsiText::truncate($text, $width, pad: true), $index);
    }

    private function detailLine(WelcomeStep $step, int $index, int $width): string
    {
        $text = self::DETAIL_INDENT.$this->styles->of(Role::Dim)->apply($step->detail);

        return $this->paint(AnsiText::truncate($text, $width, pad: true), $index);
    }

    /** Both of a step's lines carry the selection, so the card reads as one block. */
    private function paint(string $text, int $index): string
    {
        return match (true) {
            $this->list->isSelected($index) => $this->applyElement('selected', $text),
            $index === $this->hoveredIndex => $this->styles->of(Role::RowHighlightBg)->apply($text),
            default => $text,
        };
    }

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
        if (ListNavigation::handleKey($data, $kb, $this->list, \count($this->steps), page: $this->lastRows)) {
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
