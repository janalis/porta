<?php

declare(strict_types=1);

namespace Porta\Widget\Git;

use Porta\I18n\Translator;
use Porta\Support\IntMath;
use Porta\Ui\ColorPalette;
use Porta\Ui\MultilineInput;
use Porta\Ui\Palette;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Widget\InputHook;
use Porta\Widget\PaneNavigationCallbacks;
use Porta\Widget\Render\MenuOverlayHost;
use Porta\Widget\Render\MenuOverlayHostTrait;
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
final class CommitBoxWidget extends AbstractWidget implements FocusableInterface, MenuOverlayHost
{
    use FocusableTrait;
    use InputHook;
    use MenuOverlayHostTrait;
    use KeybindingsTrait;
    use PaneNavigationCallbacks;

    private const int DESCRIPTION_ROWS = 3;

    private readonly TextField $summary;
    private readonly MultilineInput $description;
    private int $activeField = 0; // 0 = summary, 1 = description
    private bool $amend = false;

    /** @var (callable(): bool)|null anything staged to commit? */
    private $stagedChangesProvider = null;
    /** @var (callable(string, bool): void)|null commit with the assembled message (+ amend flag) */
    private $onCommit = null;
    /** @var (callable(bool): void)|null amend toggled: the controller prefills/clears the message */
    private $onAmendToggle = null;

    private readonly Palette $palette;

    public function __construct(
        private readonly Translator $translator,
        private int $width = 44,
        ?Palette $palette = null,
    ) {
        $this->palette = $palette ?? ColorPalette::dark();
        $this->summary = new TextField();
        $this->description = new MultilineInput();
    }

    /** Resize the git sidebar column to $width columns (clamped sane). */
    public function setWidth(int $width): void
    {
        $width = IntMath::clamp($width, 24, 200);
        if ($width !== $this->width) {
            $this->width = $width;
            $this->invalidate();
        }
    }

    public function width(): int
    {
        return $this->width;
    }

    /** @param callable(): bool $predicate */
    public function setStagedChangesProvider(callable $predicate): self
    {
        $this->stagedChangesProvider = $predicate;

        return $this;
    }

    /** @param callable(string, bool): void $callback */
    public function onCommit(callable $callback): self
    {
        $this->onCommit = $callback;

        return $this;
    }

    /** @param callable(bool): void $callback */
    public function onAmendToggle(callable $callback): self
    {
        $this->onAmendToggle = $callback;

        return $this;
    }

    public function isAmend(): bool
    {
        return $this->amend;
    }

    /** Prefill the fields from an existing message (amend), caret at the end. */
    public function setMessage(string $message): void
    {
        $parts = explode("\n\n", $message, 2);
        $description = $parts[1] ?? '';
        $this->summary->setValue(str_replace("\n", ' ', $parts[0]));
        '' === $description ? $this->description->clear() : $this->description->setValue($description);
        $this->invalidate();
    }

    public function clear(): void
    {
        $this->summary->clear();
        $this->description->clear();
        $this->activeField = 0;
        $this->amend = false;
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
        if ('' === $this->summary->value()) {
            return false;
        }

        // Amending only the message needs no staged changes.
        return $this->amend || null !== $this->stagedChangesProvider && ($this->stagedChangesProvider)();
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
            ($this->onCommit)($this->message(), $this->amend);
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
            $this->fire($this->onExit);

            return;
        }
        if ($kb->matches($data, 'commit')) {
            $this->submit();

            return;
        }
        if ($kb->matches($data, 'toggle_amend')) {
            $this->amend = !$this->amend;
            if (null !== $this->onAmendToggle) {
                ($this->onAmendToggle)($this->amend);
            }
            $this->invalidate();

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
        $this->activeField = $relRow >= 2 && $relRow < (2 + self::DESCRIPTION_ROWS) ? 1 : 0;
        $this->invalidate();
    }

    public function render(RenderContext $context): array
    {
        $width = min($this->width, $context->getColumns());
        $focused = $this->focused;

        $lines = [];
        $header = $this->amend ? $this->translator->trans(' Commit  [amend · alt+m]') : $this->translator->trans(' Commit  ·  alt+m: amend');
        $lines[] = $this->palette->of(Role::HeaderBar)."\x1b[1m".AnsiUtils::truncateToWidth($header, $width, '…', pad: true)."\x1b[0m";

        $summary = ' '.$this->summary->render(cursor: $focused && 0 === $this->activeField, width: $width - 1);
        $summaryHint = '' === $this->summary->value() && !($focused && 0 === $this->activeField)
            ? $this->palette->of(Role::Dim).' '.$this->translator->trans('Summary (required)')."\x1b[0m"
            : $summary;
        $lines[] = $this->palette->of(Role::RowHighlightBg).AnsiUtils::truncateToWidth($summaryHint, $width, '…', pad: true)."\x1b[0m";

        $descriptionLines = $this->description->render($width - 1, self::DESCRIPTION_ROWS, cursor: $focused && 1 === $this->activeField);
        if ($this->description->isEmpty() && !($focused && 1 === $this->activeField)) {
            $descriptionLines = [$this->palette->of(Role::Dim).$this->translator->trans('Description')."\x1b[0m"];
        }
        for ($i = 0; $i < self::DESCRIPTION_ROWS; ++$i) {
            $lines[] = $this->palette->of(Role::Toolbar).AnsiUtils::truncateToWidth(' '.($descriptionLines[$i] ?? ''), $width, '…', pad: true)."\x1b[0m";
        }

        $lines[] = str_repeat(' ', $width);
        $label = $this->amend ? $this->translator->trans('  Amend last commit  ') : $this->translator->trans('  Commit to branch  ');
        $button = $this->commitEnabled()
            ? $this->palette->of(Role::SelectedRow).AnsiUtils::truncateToWidth($label, $width, '…', pad: true)."\x1b[0m"
            : $this->palette->of(Role::RowHighlightBg).$this->palette->of(Role::Dim).AnsiUtils::truncateToWidth($label, $width, '…', pad: true)."\x1b[0m";
        $lines[] = $button;

        return $this->withMenuOverlay($lines, $width);
    }

    /** @return array<string, string[]> */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'commit' => ['ctrl+enter'],
            'toggle_amend' => ['alt+m'],
            'next_field' => [Key::TAB],
            'delete_char_backward' => [Key::BACKSPACE],
            'exit_view' => [Key::ESCAPE],
        ];
    }
}
