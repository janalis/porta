<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Symfony\Component\Tui\Tui;

/**
 * A drop from outside the IDE, as every terminal but kitty delivers it: a
 * bracketed paste of the dropped paths, into whatever has focus.
 *
 * There is no pointer position in a paste, so the FOCUS is the drop zone —
 * the tree copies into its selected folder, an open file asks, an empty
 * editor or the Welcome card opens. A field that takes typing — a picker, a
 * prompt, the find bar, a search box — gets the paths as plain text, without
 * the shell's quoting. The terminal panel is the one place the quoting is
 * right, so there the paste goes through untouched.
 */
#[AsIdeService]
final readonly class PasteDrop
{
    private const string START = "\x1b[200~";

    private const string END = "\x1b[201~";

    public function __construct(
        private Tui $tui,
        private CodeEditorWidget $editor,
        private FileTreeWidget $tree,
        private WelcomeModalController $welcome,
        private BufferManager $buffers,
        private ExternalDrop $drop,
    ) {}

    /** Whether $data was a drop, handed on; false leaves it to the paste paths. */
    public function take(string $data): bool
    {
        if (!\str_starts_with($data, self::START) || !\str_ends_with($data, self::END) || $this->drop->isTyping()) {
            return false;
        }
        $zone = $this->zone();
        $paths = null === $zone ? null : DroppedPaths::parse((string) TextInput::pasted($data));
        if (null === $zone || null === $paths) {
            return false;
        }
        $this->drop->drop($paths, $zone, DropZone::Tree === $zone ? $this->tree->selectedNode()?->folder() : null);

        return true;
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
