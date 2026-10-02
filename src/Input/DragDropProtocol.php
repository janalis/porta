<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Porta\I18n\Translator;
use Porta\Workbench\DropZone;
use Porta\Workbench\ExternalDrop;
use Porta\Workbench\Notifier;
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
 * Where each motion lands — the zone, the tree folder, the caret following
 * the drag over an open file — is {@see DropTarget}'s.
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

    public function __construct(
        private readonly Tui $tui,
        private readonly DropTarget $target,
        private readonly ExternalDrop $drop,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
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
            $this->target->leave();

            return;
        }
        $zone = $this->target->aim($row, $col);
        $this->write('t=m:o='.(null === $zone ? '0' : '1').(null === $zone ? '' : ';'.\implode(' ', self::TYPES)));
        $this->tui->requestRender();
    }

    /** @param string $types the space-separated MIME list the drag offers */
    private function dropped(int $col, int $row, string $types): void
    {
        $zone = $col < 0 || $row < 0 ? null : $this->target->aim($row, $col);
        $folder = $this->target->land($zone);
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
