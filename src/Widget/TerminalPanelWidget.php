<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Terminal\TerminalBuffer;
use Porta\Ui\CursorStyle;
use Porta\Ui\LtrPane;
use Porta\Ui\Role;
use Porta\Ui\Sgr;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\TabCloseGlyph;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom terminal panel: a session tab strip over a {@see TerminalBuffer}'s
 * scrollback, forwarding keystrokes to the active session's process. Hidden
 * (zero rows) when closed. Sessions are model objects owned by the
 * {@see \Porta\Run\RunController}; the widget stays dumb, reading a tab
 * provider and firing select/close/cycle callbacks.
 */
#[AsWidget('terminal')]
final class TerminalPanelWidget extends OverlayWidget implements FocusableInterface, LtrPane
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;
    use PanelRows;

    private string $title = 'Terminal';
    private TerminalBuffer $buffer;
    private int $scrollBack = 0; // lines scrolled up from the bottom

    /** @var (callable(string): void)|null forwards input bytes to the process */
    private $onInputForward = null;

    /** @var (callable(): list<array{title: string, running: bool, active: bool}>)|null */
    private $tabsProvider = null;

    private string $lastFingerprint = '';

    /** @var list<array{index: int, start: int, width: int, closeStart: int, closeWidth: int}> the last tab strip's hit map */
    private array $lastTabLayout = [];

    /** @var (callable(int): void)|null */
    private $onSessionSelected = null;
    /** @var (callable(int): void)|null */
    private $onSessionClosed = null;
    /** @var (callable(int): void)|null +1 next / -1 prev */
    private $onSessionCycled = null;

    private readonly Styles $styles;
    private readonly CursorStyle $cursorStyle;

    public function __construct(?Styles $styles = null, ?CursorStyle $cursorStyle = null)
    {
        $this->styles = $styles ?? Styles::dark();
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
        $this->buffer = new TerminalBuffer();
        $this->visibleRows = 12;
    }

    public function open(string $title, TerminalBuffer $buffer): void
    {
        $this->title = $title;
        $this->buffer = $buffer;
        $this->scrollBack = 0;
        $this->markOpen();
    }

    public function setTitle(string $title): void
    {
        if ($this->title !== $title) {
            $this->title = $title;
            $this->invalidate();
        }
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /** Call when new output has been folded into the buffer. */
    public function refresh(): void
    {
        $this->scrollBack = 0; // stick to the bottom on new output
        $this->invalidate();
    }

    /**
     * Scroll the view $lines up into the scrollback (positive) or back down
     * toward the live bottom (negative), clamped to the available history.
     */
    public function scroll(int $lines): void
    {
        $max = max(0, $this->buffer->lineCount() - $this->visibleRows);
        $this->scrollBack = IntMath::clamp($this->scrollBack + $lines, 0, $max);
        $this->invalidate();
    }

    public function onInputForward(callable $callback): self
    {
        $this->onInputForward = $callback;

        return $this;
    }

    /** @param callable(): list<array{title: string, running: bool, active: bool}> $provider */
    public function setSessionTabsProvider(callable $provider): self
    {
        $this->tabsProvider = $provider;

        return $this;
    }

    public function onSessionSelected(callable $callback): self
    {
        $this->onSessionSelected = $callback;

        return $this;
    }

    public function onSessionClosed(callable $callback): self
    {
        $this->onSessionClosed = $callback;

        return $this;
    }

    public function onSessionCycled(callable $callback): self
    {
        $this->onSessionCycled = $callback;

        return $this;
    }

    /** Repaint when a session is renamed (e.g. '— exited') without an explicit open/refresh. */
    public function beforeRender(): void
    {
        if (null === $this->tabsProvider) {
            return;
        }
        $fingerprint = (string) json_encode(($this->tabsProvider)());
        if ($fingerprint !== $this->lastFingerprint) {
            $this->lastFingerprint = $fingerprint;
            $this->invalidate();
        }
    }

    /**
     * A click on the tab strip (widget-relative row 0): the close × zone
     * fires onSessionClosed, elsewhere onSessionSelected. Returns whether the
     * column landed on a tab — the caller lets a miss (the empty tail) fall
     * through to the panel-resize grab.
     */
    public function handleTabClick(int $col): bool
    {
        foreach ($this->lastTabLayout as $entry) {
            if (!($col >= $entry['closeStart'] && $col < ($entry['closeStart'] + $entry['closeWidth']))) {
                continue;
            }

            if (null !== $this->onSessionClosed) {
                ($this->onSessionClosed)($entry['index']);
            }

            return true;
        }
        foreach ($this->lastTabLayout as $entry) {
            if (!($col >= $entry['start'] && $col < ($entry['start'] + $entry['width']))) {
                continue;
            }

            if (null !== $this->onSessionSelected) {
                ($this->onSessionSelected)($entry['index']);
            }

            return true;
        }

        return false;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        if (!$this->open) {
            return;
        }

        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'hide_terminal')) {
            $this->close();

            return;
        }
        if ($kb->matches($data, 'scroll_up')) {
            $this->scroll(3);

            return;
        }
        if ($kb->matches($data, 'scroll_down')) {
            $this->scroll(-3);

            return;
        }
        if ($kb->matches($data, 'terminal_next_session')) {
            $this->cycle(1);

            return;
        }
        if ($kb->matches($data, 'terminal_prev_session')) {
            $this->cycle(-1);

            return;
        }
        if ($kb->matches($data, 'terminal_close_session')) {
            if (null !== $this->onSessionClosed) {
                ($this->onSessionClosed)(-1); // -1 = the active session
            }

            return;
        }

        // Everything else is forwarded verbatim to the process.
        if (null !== $this->onInputForward) {
            ($this->onInputForward)($data);
        }
    }

    public function render(RenderContext $context): array
    {
        if (!$this->isVisible()) {
            return [];
        }

        $columns = $context->getColumns();
        $rows = IntMath::clamp($this->visibleRows, 2, $context->getRows() > 0 ? $context->getRows() : $this->visibleRows);

        $lines = [$this->renderTabStrip($columns)];

        $bodyRows = $rows - 1;
        $all = $this->buffer->getLines();
        $end = \count($all) - $this->scrollBack;
        $start = max(0, $end - $bodyRows);
        $window = \array_slice($all, $start, $bodyRows);

        foreach ($window as $line) {
            $lines[] = AnsiUtils::truncateToWidth($line, $columns, '', pad: true).Sgr::RESET;
        }
        // Pad to a stable height.
        for ($i = \count($window); $i < $bodyRows; ++$i) {
            $lines[] = str_repeat(' ', max(0, $columns));
        }

        // Place the hardware cursor at the child's prompt position when the
        // panel owns the focus and shows the live tail — screen readers follow
        // it. The buffer is line oriented, so the cursor is on its last line.
        if ($this->focused && 0 === $this->scrollBack && [] !== $window) {
            $row = \count($window); // header + window: the last body line's index
            $col = min($this->buffer->cursorColumn(), max(0, $columns - 1));
            $byte = \strlen(AnsiUtils::sliceByColumn($lines[$row], 0, $col));
            $lines[$row] = substr($lines[$row], offset: 0, length: $byte).$this->cursorStyle->marker().substr($lines[$row], $byte);
        }

        return $lines;
    }

    private function cycle(int $delta): void
    {
        if (null !== $this->onSessionCycled) {
            ($this->onSessionCycled)($delta);
        }
    }

    /** The tab strip (widget row 0): one tab per session, or the plain title when no provider is wired. */
    private function renderTabStrip(int $columns): string
    {
        $tabs = null !== $this->tabsProvider ? ($this->tabsProvider)() : [];
        if ([] === $tabs) {
            // No provider (unit tests) or no sessions: a single synthetic tab.
            $tabs = [['title' => $this->title, 'running' => true, 'active' => true]];
        }

        /** @var list<array{text: string, active: bool, width: int, closeOffset: int}> $parts */
        $parts = [];
        foreach ($tabs as $tab) {
            $prefix = ' '.$tab['title'].($tab['running'] ? '' : ' ○').' ';
            $closeOffset = mb_strwidth($prefix);
            $label = $prefix.'×'.' ';
            $parts[] = ['text' => $label, 'active' => $tab['active'], 'width' => mb_strwidth($label) + 1, 'closeOffset' => $closeOffset];
        }

        $activeIndex = 0;
        foreach ($parts as $i => $candidate) {
            if (!$candidate['active']) {
                continue;
            }

            $activeIndex = $i;
            break;
        }
        // Drop leading tabs until the active one fits.
        $start = 0;
        while ($start < $activeIndex && array_sum(array_column(\array_slice($parts, $start, $activeIndex - $start + 1), 'width')) > $columns) {
            ++$start;
        }

        $line = '';
        $used = 0;
        $this->lastTabLayout = [];
        foreach (\array_slice($parts, $start, length: null, preserve_keys: true) as $index => $part) {
            if (($used + $part['width']) > $columns) {
                break;
            }
            $style = $part['active'] ? $this->styles->merge(Role::Toolbar, Role::Bright)->withBold() : $this->styles->merge(Role::Toolbar, Role::Subtle);
            $text = str_replace('×', $part['active'] ? TabCloseGlyph::active($this->styles) : TabCloseGlyph::inactive($this->styles), $part['text']);
            $line .= $style->apply($text).$this->styles->of(Role::Placeholder)->apply('│');
            $this->lastTabLayout[] = ['index' => $index, 'start' => $used, 'width' => $part['width'], 'closeStart' => $used + $part['closeOffset'], 'closeWidth' => 2];
            $used += $part['width'];
        }
        if ($this->scrollBack > 0) {
            $line .= $this->styles->merge(Role::Toolbar, Role::Dim)->apply(\sprintf('  [scrolled +%d]', $this->scrollBack));
        }

        // A Toolbar-background base under the whole row, then the styled tabs.
        return $this->styles->of(Role::Toolbar)->apply(AnsiText::padRight($line, $columns));
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'hide_terminal' => ['ctrl+`', Key::ESCAPE],
            'scroll_up' => [Key::PAGE_UP],
            'scroll_down' => [Key::PAGE_DOWN],
            'terminal_next_session' => ['ctrl+page_down'],
            'terminal_prev_session' => ['ctrl+page_up'],
            'terminal_close_session' => ['ctrl+shift+w'],
        ];
    }
}
