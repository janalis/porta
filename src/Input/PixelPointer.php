<?php

declare(strict_types=1);

namespace Porta\Input;

use Porta\Container\AsIdeService;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * The pointer to half a cell, where the terminal can say: on a roomy screen a
 * list's blank gap holds the lower half of the row above it and the upper
 * half of the row below, so which of the two the pointer is over depends on
 * which half of the gap it is in ({@see HalfRows}). A cell report cannot
 * tell; a pixel report (SGR-Pixels, mode 1016) can.
 *
 * {@see probe()} asks whether the terminal knows mode 1016 (DECRQM) and how
 * tall a cell is (`CSI 16 t`); only once both have answered is the mode
 * turned on, because a terminal that ignored it would go on reporting cells
 * that would then be read as pixels. Until then, and for good on a terminal
 * that does not know it, {@see cells()} hands a report on as it came, with
 * no half. `PORTA_PIXEL_MOUSE=off` keeps it off.
 *
 * The answers arrive as input: {@see onInput()} sits in front of the
 * dispatcher with {@see \Porta\Ui\Graphics\TerminalGraphics}, swallows the
 * mode report, and reads the cell size without swallowing it — the graphics
 * probe asks for it too, and records it from the same reply.
 */
#[AsIdeService]
final class PixelPointer
{
    /** Turns pixel reports on; {@see \Porta\Ui\TerminalRestorer} turns them off. */
    public const string ON = "\x1b[?1016h";

    private ?bool $known = null;

    private ?int $cellWidth = null;

    private ?int $cellHeight = null;

    private bool $on = false;

    /** @var array{int, int}|null the terminal's size in cells at the last frame */
    private ?array $size = null;

    public function __construct(
        private readonly Tui $tui,
    ) {}

    /**
     * Ask the terminal — unless $env says not to.
     *
     * @param array<string, string|false> $env
     */
    public function probe(array $env): void
    {
        if ('off' === ($env['PORTA_PIXEL_MOUSE'] ?? false)) {
            return;
        }
        $this->tui->getTerminal()->write("\x1b[?1016\$p\x1b[16t");
    }

    /**
     * Before each frame: a font zoom changes the cell's size in pixels, and
     * the terminal's size in cells with it, so a new size asks again — a
     * stale cell size would land every report in the wrong cell.
     */
    public function beforeFrame(): void
    {
        $terminal = $this->tui->getTerminal();
        $size = [$terminal->getColumns(), $terminal->getRows()];
        if ($size === $this->size) {
            return;
        }
        $this->size = $size;
        if ($this->on) {
            $terminal->write("\x1b[16t");
        }
    }

    /**
     * What a re-assert of the mouse modes has to end with: pixel reports, once
     * on. Modes 1006 and 1016 are one setting in the terminal — the encoding —
     * so a blind `?1006h` turns pixel reports back into cell reports, which
     * would then be read as pixels: a click anywhere lands at the top-left.
     */
    public function reassert(): string
    {
        return $this->on ? self::ON : '';
    }

    /** The input listener: consumes the mode report, reads the cell size, passes everything else on. */
    public function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        $match = [];
        if (1 === \preg_match('/^\x1b\[\?1016;(\d)\$y$/', $data, $match)) {
            // 1 set, 2 reset, 3 permanently set: known. 0 unknown, 4 permanently reset: no.
            $this->known = \in_array((int) $match[1], [1, 2, 3], strict: true);
            $event->stopPropagation();
            $this->switchOn();

            return;
        }
        if (1 === \preg_match('/^\x1b\[6;(\d+);(\d+)t$/', $data, $match)) {
            $this->cellHeight = (int) $match[1]; // a cell of no size counts as one pixel, in MouseEvent::inCells()
            $this->cellWidth = (int) $match[2];
            $this->switchOn();
        }
    }

    /** $mouse in cells, with the half of its cell it is in when the report was in pixels. */
    public function cells(MouseEvent $mouse): MouseEvent
    {
        return $this->on && null !== $this->cellWidth && null !== $this->cellHeight ? $mouse->inCells($this->cellWidth, $this->cellHeight) : $mouse;
    }

    private function switchOn(): void
    {
        if ($this->on || true !== $this->known || null === $this->cellHeight) {
            return;
        }
        $this->on = true;
        $this->tui->getTerminal()->write(self::ON);
    }
}
