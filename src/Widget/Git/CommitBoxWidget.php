<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\Ui\MultilineInput;
use Porta\Ui\Style;
use Porta\Ui\TextField;
use Porta\Widget\InputHook;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;

/**
 * The commit box under the changes list (GitHub Desktop's bottom-left):
 * a single-line summary, a multi-line description and a [Commit] button.
 * Commit is enabled when the summary is non-empty and anything is staged
 * (via the injected predicate); Ctrl+Enter or a button click commits.
 */
final class CommitBoxWidget extends AbstractWidget implements FocusableInterface
{
    use FocusableTrait;
    use InputHook;
    use KeybindingsTrait;

    private const int DESCRIPTION_ROWS = 3;

    private readonly TextField $summary;
    private readonly MultilineInput $description;
    private int $activeField = 0; // 0 = summary, 1 = description

    /** @var (callable(): bool)|null anything staged to commit? */
    private $hasStagedChanges = null;
    /** @var (callable(string): void)|null commit with the assembled message */
    private $onCommit = null;
    /** @var (callable(): void)|null Tab past the last field: next git pane */
    private $onFocusNext = null;
    /** @var (callable(): void)|null Esc: leave the git view */
    private $onExit = null;

    public function __construct(
        private readonly int $width = 44,
    ) {
        $this->summary = new TextField();
        $this->description = new MultilineInput();
    }

    /** @param callable(): bool $predicate */
    public function hasStagedChanges(callable $predicate): self
    {
        $this->hasStagedChanges = $predicate;

        return $this;
    }

    public function onCommit(callable $callback): self
    {
        $this->onCommit = $callback;

        return $this;
    }

    public function onFocusNext(callable $callback): self
    {
        $this->onFocusNext = $callback;

        return $this;
    }

    public function onExit(callable $callback): self
    {
        $this->onExit = $callback;

        return $this;
    }

    public function clear(): void
    {
        $this->summary->clear();
        $this->description->clear();
        $this->activeField = 0;
        $this->invalidate();
    }

    /** Focus lands on the summary field when the pane is entered. */
    public function resetField(): void
    {
        $this->activeField = 0;
        $this->invalidate();
    }

    public function commitEnabled(): bool
    {
        return '' !== $this->summary->value() && (null !== $this->hasStagedChanges && ($this->hasStagedChanges)());
    }

    /** The assembled commit message: summary, blank line, description. */
    public function message(): string
    {
        $description = $this->description->value();

        return '' === $description ? $this->summary->value() : $this->summary->value()."\n\n".$description;
    }

    /** Fire the commit (keyboard or button click); no-op while disabled. */
    public function submit(): void
    {
        if ($this->commitEnabled() && null !== $this->onCommit) {
            ($this->onCommit)($this->message());
        }
    }

    /** The widget-relative row of the [Commit] button, for mouse hit-testing. */
    public function buttonRow(): int
    {
        return 2 + self::DESCRIPTION_ROWS + 1;
    }

    public function handleInput(string $data): void
    {
        if ($this->inputHookHandled($data)) {
            return;
        }
        $kb = $this->getKeybindings();

        if ($kb->matches($data, 'exit_view')) {
            if (null !== $this->onExit) {
                ($this->onExit)();
            }

            return;
        }
        if ($kb->matches($data, 'commit')) {
            $this->submit();

            return;
        }
        if ($kb->matches($data, 'next_field')) {
            if (0 === $this->activeField) {
                $this->activeField = 1;
                $this->invalidate();
            } elseif (null !== $this->onFocusNext) {
                $this->activeField = 0;
                ($this->onFocusNext)();
            }

            return;
        }

        if (0 === $this->activeField) {
            if ($kb->matches($data, 'delete_char_backward')) {
                if ($this->summary->backspace()) {
                    $this->invalidate();
                }
            } elseif ($this->summary->insert($data)) {
                $this->invalidate();
            }

            return;
        }

        if ($this->description->handleKey($data)) {
            $this->invalidate();
        }
    }

    /** A click on the button row commits; the field rows move the caret. */
    public function handleClick(int $relRow): void
    {
        if ($relRow === $this->buttonRow()) {
            $this->submit();

            return;
        }
        $this->activeField = $relRow >= 2 && $relRow < 2 + self::DESCRIPTION_ROWS ? 1 : 0;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $focused = $this->focused;

        $lines = [];
        $lines[] = "\x1b[48;5;237m\x1b[1m".AnsiUtils::truncateToWidth(' Commit', $width, '…', pad: true)."\x1b[0m";

        $summary = ' '.$this->summary->render(cursor: $focused && 0 === $this->activeField);
        $summaryHint = '' === $this->summary->value() && !($focused && 0 === $this->activeField) ? "\x1b[38;5;245m Summary (required)\x1b[0m" : $summary;
        $lines[] = "\x1b[48;5;236m".AnsiUtils::truncateToWidth($summaryHint, $width, '…', pad: true)."\x1b[0m";

        $descriptionLines = $this->description->render($width - 1, cursor: $focused && 1 === $this->activeField);
        if ($this->description->isEmpty() && !($focused && 1 === $this->activeField)) {
            $descriptionLines = ["\x1b[38;5;245mDescription\x1b[0m"];
        }
        for ($i = 0; $i < self::DESCRIPTION_ROWS; ++$i) {
            $lines[] = "\x1b[48;5;235m".AnsiUtils::truncateToWidth(' '.($descriptionLines[$i] ?? ''), $width, '…', pad: true)."\x1b[0m";
        }

        $lines[] = str_repeat(' ', $width);
        $button = $this->commitEnabled()
            ? Style::SELECTED_ROW.AnsiUtils::truncateToWidth('  Commit to branch  ', $width, '…', pad: true)."\x1b[0m"
            : "\x1b[48;5;236m\x1b[38;5;245m".AnsiUtils::truncateToWidth('  Commit to branch  ', $width, '…', pad: true)."\x1b[0m";
        $lines[] = $button;

        return $lines;
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'commit' => ['ctrl+enter'],
            'next_field' => [Key::TAB],
            'delete_char_backward' => [Key::BACKSPACE],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
