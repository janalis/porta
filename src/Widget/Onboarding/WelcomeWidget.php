<?php

declare(strict_types=1);

namespace Porta\Widget\Onboarding;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Onboarding\WelcomeStep;
use Porta\Support\AnsiText;
use Porta\Ui\Glyphs;
use Porta\Ui\Role;
use Porta\Ui\Theme\Styles;
use Porta\Widget\ArrowScrollTrait;
use Porta\Widget\FillsPaneTrait;
use Porta\Widget\HoverableList;
use Porta\Widget\HoverTrait;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
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
 * The Welcome screen: a heading, then one row per setup step.
 *
 * A step renders as pending — with its action in brackets — or as done, with a
 * check and no action. Enter runs the selected step; a step that is already done
 * does nothing, so a stray keypress cannot undo anything.
 *
 * Completion is asked of each {@see WelcomeStep} at render time rather than
 * tracked here, so a step satisfied elsewhere (Settings, a plugin page) shows as
 * done the moment the user comes back, and the screen can never disagree with
 * the thing it describes.
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

    /** Blank line, heading, blank line — the rows start below these. */
    private const int HEADER_ROWS = 3;

    private readonly Styles $styles;

    /** @var list<WelcomeStep> */
    private array $steps = [];
    private int $selected = 0;

    /** @var (callable(): void)|null a step ran, so the view can refresh */
    private $onRan = null;

    public function __construct(
        private readonly Translator $translator,
        ?Styles $styles = null,
    ) {
        $this->styles = $styles ?? Styles::dark();
        $this->addStyleClass('list'); // the theme's .list::selected(:focus) rules style the selection
    }

    /** @param list<WelcomeStep> $steps */
    public function setSteps(array $steps): void
    {
        $this->steps = $steps;
        $this->selected = min($this->selected, max(0, \count($steps) - 1));
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
        $height = $context->getRows();
        $rows = max(\count($this->steps) + self::HEADER_ROWS, $height > 0 ? $height : 0);

        $lines = [
            str_repeat(' ', max(0, $width)),
            AnsiText::truncate('  '.$this->styles->of(Role::Bright)->apply($this->translator->trans('Welcome to Porta')), $width, pad: true),
            str_repeat(' ', max(0, $width)),
        ];

        for ($i = self::HEADER_ROWS; $i < $rows; ++$i) {
            $step = $this->steps[$i - self::HEADER_ROWS] ?? null;
            if (null === $step) {
                $lines[] = str_repeat(' ', max(0, $width));
                continue;
            }

            $lines[] = $this->stepLine($step, $i - self::HEADER_ROWS, $width);
        }

        return $this->withMenuOverlay($lines, $width);
    }

    private function stepLine(WelcomeStep $step, int $index, int $width): string
    {
        $done = $step->isDone();
        $marker = $done ? $this->styles->of(Role::Added)->apply(Glyphs::SEVERITY_INFO) : Glyphs::NAV_CURRENT;
        $text = ' '.$marker.' '.$step->title;
        $text .= $done
            ? $this->styles->of(Role::Dim)->apply('  · '.$this->translator->trans('done'))
            : '  ['.$step->actionLabel.']'.$this->styles->of(Role::Dim)->apply('  '.$step->detail);

        $text = AnsiText::truncate($text, $width, pad: true);

        return match (true) {
            $index === $this->selected => $this->applyElement('selected', $text),
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
        if ($kb->matches($data, 'focus_next_pane')) {
            $this->fire($this->onFocusNext);

            return;
        }
        if ($kb->matches($data, 'move_up')) {
            $this->moveTo($this->selected - 1);

            return;
        }
        if ($kb->matches($data, 'move_down')) {
            $this->moveTo($this->selected + 1);

            return;
        }
        if ($kb->matches($data, 'confirm')) {
            $this->runSelected();
        }
    }

    /** The heading rows are not steps, so hovering them clears the tint. */
    public function hoverRow(int $relRow): bool
    {
        $index = $relRow - self::HEADER_ROWS;

        return array_key_exists($index, $this->steps) ? $this->setHovered($index) : $this->clearHover();
    }

    /** A click lands on a step row; the header rows are inert. */
    public function handleClick(int $row): void
    {
        $index = $row - self::HEADER_ROWS;
        if (!array_key_exists($index, $this->steps)) {
            return;
        }

        $this->selected = $index;
        $this->invalidate();
        $this->runSelected();
    }

    private function moveTo(int $index): void
    {
        $clamped = max(0, min($index, \count($this->steps) - 1));
        if ($clamped === $this->selected) {
            return;
        }

        $this->selected = $clamped;
        $this->invalidate();
    }

    /** Running a finished step is a no-op, so a stray Enter cannot undo anything. */
    private function runSelected(): void
    {
        $step = $this->steps[$this->selected] ?? null;
        if (null === $step || $step->isDone()) {
            return;
        }

        $step->run();
        $this->invalidate();
        $this->fire($this->onRan);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'move_up' => [Key::UP],
            'move_down' => [Key::DOWN],
            'confirm' => [Key::ENTER, Key::SPACE],
            'focus_next_pane' => [Key::TAB],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
