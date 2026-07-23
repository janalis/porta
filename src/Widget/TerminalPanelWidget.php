<?php

declare(strict_types=1);

namespace Porta\Widget;

use Porta\Container\AsWidget;
use Porta\I18n\Translator;
use Porta\Support\AnsiText;
use Porta\Support\IntMath;
use Porta\Terminal\TerminalBuffer;
use Porta\Ui\CursorStyle;
use Porta\Ui\LtrPane;
use Porta\Ui\Sgr;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * Bottom terminal panel: a {@see TerminalBuffer}'s scrollback, forwarding
 * keystrokes to the active session's process. Hidden (zero rows) when closed.
 * Sessions are model objects owned by the {@see \Porta\Run\RunController} and
 * surface as first-class tabs on the dock strip ({@see \Porta\Ui\Dock\BottomDock}),
 * so the panel body is pure terminal output; the widget stays dumb, firing
 * close/cycle callbacks for the session keys.
 */
#[AsWidget('terminal')]
final class TerminalPanelWidget extends OverlayWidget implements FocusableInterface, LtrPane, MenuOverlayHost
{
    use FocusableTrait;
    use MenuOverlayHostTrait;
    use InputHook;
    use KeybindingsTrait;
    use PanelRows;

    private string $title = 'Terminal';
    private TerminalBuffer $buffer;
    private int $scrollBack = 0; // lines scrolled up from the bottom

    /** @var (callable(string): void)|null forwards input bytes to the process */
    private $onInputForward = null;

    /** @var (callable(int): void)|null */
    private $onSessionClosed = null;
    /** @var (callable(int): void)|null +1 next / -1 prev */
    private $onSessionCycled = null;

    private readonly CursorStyle $cursorStyle;
    private readonly Translator $translator;

    public function __construct(?CursorStyle $cursorStyle = null, ?Translator $translator = null)
    {
        $this->cursorStyle = $cursorStyle ?? new CursorStyle();
        $this->translator = $translator ?? new Translator('en');
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

    /** The dock strip's right-aligned status for the active session tab. */
    public function statusText(): string
    {
        return $this->scrollBack > 0 ? $this->translator->trans('[scrolled +%count%]', ['%count%' => $this->scrollBack]) : '';
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
            $this->dismiss();

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

        $lines = [];
        $all = $this->buffer->getLines();
        $end = \count($all) - $this->scrollBack;
        $start = max(0, $end - $rows);
        $window = \array_slice($all, $start, $rows);

        foreach ($window as $line) {
            // '' ellipsis: PTY output is raw content, not chrome — a mark would corrupt it.
            $lines[] = AnsiText::truncate($line, $columns, pad: true, ellipsis: '').Sgr::RESET;
        }
        // Pad to a stable height.
        for ($i = \count($window); $i < $rows; ++$i) {
            $lines[] = str_repeat(' ', max(0, $columns));
        }

        // Place the hardware cursor at the child's prompt position when the
        // panel owns the focus and shows the live tail — screen readers follow
        // it. The buffer is line oriented, so the cursor is on its last line.
        if ($this->focused && 0 === $this->scrollBack && [] !== $window) {
            $row = \count($window) - 1; // the last body line's index
            $col = min($this->buffer->cursorColumn(), max(0, $columns - 1));
            $byte = \strlen(AnsiUtils::sliceByColumn($lines[$row], 0, $col));
            $lines[$row] = substr($lines[$row], offset: 0, length: $byte).$this->cursorStyle->marker().substr($lines[$row], $byte);
        }

        return $this->withMenuOverlay($lines, $columns);
    }

    private function cycle(int $delta): void
    {
        if (null !== $this->onSessionCycled) {
            ($this->onSessionCycled)($delta);
        }
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
