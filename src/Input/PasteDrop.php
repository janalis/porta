<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Support\Scheduler;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Symfony\Component\Tui\Tui;

/**
 * A drop from outside the IDE, as every terminal but kitty delivers it: a
 * bracketed paste of the dropped paths.
 *
 * The paste carries no position, but the pointer's does follow it: Ghostty
 * reports nothing while a file is dragged over the window and, a few
 * milliseconds after the paste, the motion of the pointer that let it go —
 * measured, 10ms. So a paste of paths is held for that report, up to
 * {@see WAIT}, and lands under the pointer exactly as a kitty drop does
 * ({@see DropTarget}): a tree folder copies, an open file places the caret
 * there and asks, the tab strip opens.
 *
 * With no report in time — a terminal that sends none, a pointer resting
 * outside the window, a path pasted from the keyboard — the FOCUS is the zone:
 * the tree copies into its selected folder, an open file asks, an empty editor
 * or the Welcome card opens, a field types the paths as plain text. The
 * terminal panel gets the paste exactly as it came, shell quoting and all.
 */
#[AsIdeService]
final class PasteDrop
{
    /** How long a paste of paths waits for the pointer that let it go. */
    public const float WAIT = 0.1;

    private const string START = "\x1b[200~";

    private const string END = "\x1b[201~";

    /** @var array{paths: non-empty-list<string>, raw: string, timer: string}|null the paste of paths waiting for the pointer */
    private ?array $held = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly CodeEditorWidget $editor,
        private readonly FileTreeWidget $tree,
        private readonly WelcomeModalController $welcome,
        private readonly BufferManager $buffers,
        private readonly ExternalDrop $drop,
        private readonly DropTarget $target,
        private readonly Scheduler $clock,
    ) {}

    /** Whether $data was a paste of paths, now held; false leaves it to the paste paths. */
    public function take(string $data): bool
    {
        if (!\str_starts_with($data, self::START) || !\str_ends_with($data, self::END) || $this->drop->isTyping()) {
            return false;
        }
        $paths = DroppedPaths::parse((string) TextInput::pasted($data));
        if (null === $paths) {
            return false;
        }
        $raw = \substr($data, \strlen(self::START), -\strlen(self::END));
        $this->held = ['paths' => $paths, 'raw' => $raw, 'timer' => $this->clock->delay(self::WAIT, $this->settle(...))];

        return true;
    }

    /**
     * The pointer moved, pressed or scrolled: a held paste lands under it. A
     * cell where a drop does nothing — the dock, the status bar — leaves the
     * paste to the focus, so dropping on the terminal panel pastes there.
     */
    public function landAt(MouseEvent $mouse): void
    {
        $held = $this->held;
        if (null === $held) {
            return;
        }
        $zone = $this->target->aim($mouse->row, $mouse->col);
        $folder = $this->target->land($zone);
        if (null === $zone) {
            $this->settle();

            return;
        }
        $this->drop->drop($this->release($held), $zone, $folder);
    }

    /**
     * Spend a held paste by the focus — its wait ran out, or a key arrived
     * before the pointer did and must not overtake it.
     */
    public function settle(): void
    {
        $held = $this->held;
        if (null === $held) {
            return;
        }
        $paths = $this->release($held);
        $zone = $this->zone();
        if (null === $zone) {
            $this->drop->typeText($held['raw']);

            return;
        }
        $this->drop->drop($paths, $zone, DropZone::Tree === $zone ? $this->tree->selectedNode()?->folder() : null);
    }

    /**
     * @param array{paths: non-empty-list<string>, raw: string, timer: string} $held
     *
     * @return non-empty-list<string>
     */
    private function release(array $held): array
    {
        $this->held = null;
        $this->clock->cancel($held['timer']);

        return $held['paths'];
    }

    private function zone(): ?DropZone
    {
        if ($this->welcome->isOpen()) {
            return DropZone::Empty;
        }
        $focus = $this->tui->getFocus();
        // A picker or the find modal holds the keys over whatever has focus.
        $typing = $this->editor->isAnyPickerOpen() || $this->editor->isFindModalOpen();

        return match (true) {
            !$typing && $focus instanceof TerminalPanelWidget => null,
            $typing || $focus !== $this->tree && $focus !== $this->editor => DropZone::Text,
            $focus === $this->tree => DropZone::Tree,
            null === $this->buffers->active() => DropZone::Empty,
            default => DropZone::Editor,
        };
    }
}
