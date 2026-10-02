<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\Editor\BufferManager;
use Porta\Editor\Document;
use Porta\I18n\Translator;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Ui\BidiTui;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Porta\Workbench\Notifier;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * kitty's drag-and-drop protocol (OSC 72, kitty 0.47+): a drop from outside
 * the IDE that knows WHERE it is.
 *
 * Every other terminal pastes a drop's paths into whatever has focus
 * ({@see PasteDrop}). This one reports the pointer while the drag passes over
 * the window, so the tree can spring a folder open under it and the drop
 * lands where it was let go rather than where the focus happened to be. The
 * exchange, from https://sw.kovidgoyal.net/kitty/dnd-protocol/:
 *
 * - `t=m:x=…:y=…` while the drag moves — answered `t=m:o=1` (copy) where a
 *   drop would do something and `o=0` where it would not; `x=-1` when it
 *   leaves the window.
 * - `t=M:x=…:y=…;<mime list>` on the drop — answered `t=r:x=<index>`, the
 *   1-based place of the type wanted in that list.
 * - `t=r:x=<index>:m=1;<base64>` chunks, the last `m=0` — then `t=r:o=1`
 *   says the drop was taken, or `o=0` that it was not; `t=R` is the
 *   terminal's error instead of the data.
 *
 * Over an open file the caret follows the drag, so the drop inserts where it
 * was let go; a drag that moves on, leaves or lands elsewhere puts the caret
 * back where it was.
 *
 * The opt-in rides the boot sequence ({@see OPT_IN}) and the opt-out the
 * teardown's; a terminal that does not speak OSC 72 ignores both, as every
 * terminal ignores an OSC it does not know.
 */
#[AsIdeService]
final class DragDropProtocol
{
    /** Ask to be told about drags, preferring a URI list to plain text. */
    public const string OPT_IN = "\x1b]72;t=a;text/uri-list text/plain\x1b\\";

    public const string OPT_OUT = "\x1b]72;t=A\x1b\\";

    private const string PREFIX = "\x1b]72;";

    /** The types asked for, in the order they are preferred. */
    private const array TYPES = ['text/uri-list', 'text/plain'];

    /** A text field's order: it takes any text, and a URL is text. */
    private const array TEXT_TYPES = ['text/plain', 'text/uri-list'];

    /** @var array{zone: DropZone, folder: ?string, type: string, data: string}|null the drop whose data is arriving */
    private ?array $pending = null;

    /** The tree folder the drag is over, from its last motion; null off the tree. */
    private ?string $folder = null;

    /** @var array{doc: Document, line: int, col: int, range: array{startLine: int, startCol: int, endLine: int, endCol: int}|null}|null the caret a drag over the text moved, to put back */
    private ?array $caret = null;

    public function __construct(
        private readonly Tui $tui,
        private readonly HitTester $hit,
        private readonly EditorLayoutPanes $panes,
        private readonly ViewRegistry $views,
        private readonly WelcomeModalController $welcome,
        private readonly BufferManager $buffers,
        private readonly BidiTui $bidi,
        private readonly TreeDragRouter $treeDrag,
        private readonly ExternalDrop $drop,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly string $rootDir,
    ) {}

    /** The input listener: takes every OSC 72 and passes everything else on. */
    public function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (!\str_starts_with($data, self::PREFIX)) {
            return;
        }
        $event->stopPropagation();
        $body = \substr($data, \strlen(self::PREFIX));
        $body = \str_ends_with($body, "\x1b\\") ? \substr($body, offset: 0, length: -2) : \rtrim($body, characters: "\x07");
        $split = \strpos($body, needle: ';');
        $meta = self::metadata(false === $split ? $body : \substr($body, offset: 0, length: $split));
        $payload = false === $split ? '' : \substr($body, $split + 1);
        match ($meta['t'] ?? '') {
            'm' => $this->moved((int) ($meta['x'] ?? -1), (int) ($meta['y'] ?? -1)),
            'M' => $this->dropped((int) ($meta['x'] ?? -1), (int) ($meta['y'] ?? -1), $payload),
            'r' => $this->received($payload, '1' === ($meta['m'] ?? '0')),
            'R' => $this->failed($payload),
            default => null,
        };
    }

    private function moved(int $col, int $row): void
    {
        if ($col < 0 || $row < 0) {
            $this->treeDrag->endExternal();
            $this->restoreCaret();

            return;
        }
        $zone = $this->target($row, $col);
        $this->write('t=m:o='.(null === $zone ? '0' : '1').(null === $zone ? '' : ';'.\implode(' ', self::TYPES)));
        $this->tui->requestRender();
    }

    /** @param string $types the space-separated MIME list the drag offers */
    private function dropped(int $col, int $row, string $types): void
    {
        $zone = $col < 0 || $row < 0 ? null : $this->target($row, $col);
        // Below the last row is still the tree: the project root is the
        // folder that holds everything shown there.
        $folder = DropZone::Tree === $zone ? $this->folder ?? $this->rootDir : null;
        $this->treeDrag->endExternal($folder);
        // Landed in the text: the caret is where the insert goes now.
        $this->caret = null;
        $offered = \preg_split('/\s+/', \trim($types), flags: \PREG_SPLIT_NO_EMPTY);
        $offered = false === $offered ? [] : $offered;
        $type = null;
        $index = false;
        foreach (DropZone::Text === $zone ? self::TEXT_TYPES : self::TYPES as $wanted) {
            $index = \array_search($wanted, $offered, strict: true);
            if (false !== $index) {
                $type = $wanted;
                break;
            }
        }
        if (null === $zone || null === $type || false === $index) {
            $this->pending = null;
            $this->write('t=r:o=0');

            return;
        }
        $this->pending = ['zone' => $zone, 'folder' => $folder, 'type' => $type, 'data' => ''];
        $this->write('t=r:x='.($index + 1));
    }

    // @mago-expect lint:no-boolean-flag-parameter -- `m=1` is the protocol's own "more to come"; it decides whether to wait, nothing else.
    private function received(string $chunk, bool $more): void
    {
        if (null === $this->pending) {
            return;
        }
        $this->pending['data'] .= $chunk;
        if ($more) {
            return;
        }
        ['zone' => $zone, 'folder' => $folder, 'type' => $type, 'data' => $data] = $this->pending;
        $this->pending = null;
        $text = (string) \base64_decode($data, strict: true);
        $paths = 'text/uri-list' === $type ? DroppedPaths::fromUriList($text) : DroppedPaths::parse($text);
        if (null === $paths && DropZone::Text === $zone) {
            $this->write('t=r:o=1');
            $this->drop->typeText(\trim($text));

            return;
        }
        if (null === $paths) {
            $this->write('t=r:o=0');
            $this->notifier->flash($this->translator->trans('Only files and folders can be dropped here'));

            return;
        }
        $this->write('t=r:o=1');
        $this->drop->drop($paths, $zone, $folder);
    }

    private function failed(string $reason): void
    {
        $this->pending = null;
        $this->write('t=r:o=0');
        $this->notifier->warn($this->translator->trans('The drop failed: %reason%', ['%reason%' => $reason]));
    }

    /**
     * The zone under a screen cell, or null where a drop does nothing: the
     * dock, the status bar, the menu. A modal takes it whole wherever it lands
     * — a picker or a view such as Settings types the paths into the field
     * that has the keys, and the Welcome card opens them, for the empty editor
     * it stands in for.
     *
     * Over the tree this is also the hover: the highlight and the spring follow
     * the pointer from here. Anywhere else the outside drag is not over the
     * tree, which is what ends its highlight.
     */
    private function target(int $row, int $screenCol): ?DropZone
    {
        $zone = $this->zoneAt($row, $screenCol);
        $rel = DropZone::Editor === $zone ? $this->hit->hit($this->panes->editor, $row, $this->bidi->logicalColumn($row, $screenCol)) : null;
        if (null === $rel) {
            $this->restoreCaret();

            return $zone;
        }
        $editor = $this->panes->editor;
        $doc = $editor->getDocument();
        $this->caret ??= ['doc' => $doc, 'line' => $doc->cursorLine, 'col' => $doc->cursorCol, 'range' => $doc->getSelectionRange()];
        // A mouse press's placement, its empty selection dropped — not a
        // click, which on the error stripe jumps and on a lens row runs it.
        if ($rel['col'] < $editor->viewport()->stripeCol) {
            $editor->beginSelectionAt($rel['row'], $rel['col']);
            $doc->clearSelection();
        }

        return $zone;
    }

    /**
     * Put back the caret — and the selection — a drag over the text moved.
     * Into the document it was taken from, so a file switched away from
     * meanwhile gets its own caret back for when it is shown again.
     */
    private function restoreCaret(): void
    {
        if (null === $this->caret) {
            return;
        }
        ['doc' => $doc, 'line' => $line, 'col' => $col, 'range' => $range] = $this->caret;
        $this->caret = null;
        null === $range
            ? $doc->setCursor($line, $col)
            : $doc->setSelectionRange($range['startLine'], $range['startCol'], $range['endLine'], $range['endCol']);
        $this->panes->editor->invalidate();
    }

    private function zoneAt(int $row, int $screenCol): ?DropZone
    {
        $this->folder = null;
        if ($this->welcome->isOpen()) {
            $this->treeDrag->endExternal();

            return DropZone::Empty;
        }
        $col = $this->bidi->logicalColumn($row, $screenCol);
        if ($this->views->isAnyOpen() || $this->panes->editor->isAnyPickerOpen()) {
            $this->treeDrag->endExternal();

            return DropZone::Text;
        }
        $treeRect = $this->hit->rect($this->panes->tree);
        if (null !== $treeRect && $treeRect->contains($row, $col)) {
            $this->folder = $this->treeDrag->hoverExternal($row, $col, $treeRect);

            return DropZone::Tree;
        }
        $this->treeDrag->endExternal();
        if (null !== $this->hit->hit($this->panes->tabBar, $row, $col)) {
            return DropZone::Empty;
        }
        $pane = $this->panes->pane();
        if (null === $this->hit->hit($this->panes->editor, $row, $col) && (null === $pane || null === $this->hit->hit($pane, $row, $col))) {
            return null;
        }

        return null === $pane && null !== $this->buffers->active() ? DropZone::Editor : DropZone::Empty;
    }

    private function write(string $body): void
    {
        $this->tui->getTerminal()->write(self::PREFIX.$body."\x1b\\");
    }

    /** @return array<string, string> `t=m:x=3:y=4` as its keys and values */
    private static function metadata(string $head): array
    {
        $meta = [];
        foreach (\explode(':', $head) as $pair) {
            $equals = \strpos($pair, needle: '=');
            if (false !== $equals) {
                $meta[\substr($pair, offset: 0, length: $equals)] = \substr($pair, $equals + 1);
            }
        }

        return $meta;
    }
}
