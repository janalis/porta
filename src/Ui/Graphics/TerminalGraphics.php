<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

use Porta\Container\AsIdeService;
use Porta\Support\Scheduler;
use Symfony\Component\Tui\Event\InputEvent;
use Symfony\Component\Tui\Tui;

/**
 * Whether the terminal can draw real pixels, and the channel that sends them:
 * the kitty graphics protocol, shown through {@see KittyPlaceholders} so an
 * image is text to everything between a pane and the screen.
 *
 * ## Finding out
 *
 * {@see probe()} asks once, at startup: a kitty graphics query, the cell size in
 * pixels (`CSI 16 t`), then Primary Device Attributes. Every terminal answers
 * DA1, and answers in order, so a DA1 that arrives with no graphics `OK` before
 * it is a terminal without the protocol — no timeout guessing for the common
 * case. The timeout covers a terminal that answers nothing at all.
 *
 * The replies arrive as input. {@see onInput()} sits in front of every other
 * listener and swallows them, because each one otherwise reaches the input
 * dispatcher as a keypress nobody made — which dismisses the tooltip, the doc
 * popup and the completion note.
 *
 * Some terminals answer the query and then cannot draw a placeholder (WezTerm,
 * Konsole), and tmux needs every command wrapped for passthrough. Those resolve
 * to "no" without asking; `PORTA_GRAPHICS=kitty` forces the protocol on and
 * `PORTA_GRAPHICS=off` turns it off, the {@see \Porta\Ui\TerminalBidi} override
 * shape.
 *
 * ## Sending
 *
 * Every command here is cursor-neutral — a transmit stores pixels, a VIRTUAL
 * placement (`U=1`) draws nothing by itself, a delete draws nothing — so each
 * is written straight to the terminal between frames. The frame writer's
 * relative cursor bookkeeping never sees them. All of them carry `q=2`: the
 * terminal stays silent, success or failure, so nothing comes back as input.
 */
#[AsIdeService]
final class TerminalGraphics
{
    /** The image id the capability query uses; allocated ids start well above it. */
    private const int PROBE_ID = 31;

    /** How long a silent terminal gets before it counts as "no". */
    private const float PROBE_TIMEOUT = 1.5;

    /** The largest base64 payload one command may carry — the spec's chunk size. */
    private const int CHUNK = 4096;

    /** Kitty graphics were confirmed (or forced); null while the probe is out. */
    private ?bool $kitty = null;

    private bool $answeredKitty = false;

    private int $cellWidth = 8;

    private int $cellHeight = 16;

    /** Device pixels per logical pixel ({@see PixelRatio}); 1 until known. */
    private float $pixelRatio = 1.0;

    private int $nextId = 1000;

    private ?string $timer = null;

    /** @var array<int, true> ids the terminal holds pixels for */
    private array $transmitted = [];

    /** @var array<int, string> id => the placement last sent, so an unchanged view sends nothing */
    private array $placed = [];

    /** @var list<\Closure(): void> told when the answer lands */
    private array $listeners = [];

    public function __construct(
        private readonly Tui $tui,
        private readonly Scheduler $scheduler,
    ) {}

    /**
     * Ask the terminal — or decide without asking, when $env already says.
     *
     * @param array<string, string|false> $env
     */
    public function probe(array $env): void
    {
        $override = $env['PORTA_GRAPHICS'] ?? false;
        if ('off' === $override || ('kitty' !== $override && self::incapable($env))) {
            $this->resolve(false);

            return;
        }
        if ('kitty' === $override) {
            $this->kitty = true; // still ask for the cell size below
        }
        $this->tui->getTerminal()->write("\x1b_Gi=".self::PROBE_ID.",s=1,v=1,a=q,t=d,f=24;AAAA\x1b\\\x1b[16t\x1b[c");
        $this->timer = $this->scheduler->delay(self::PROBE_TIMEOUT, function (): void {
            $this->timer = null;
            $this->resolve($this->confirmed());
        });
    }

    /** The input listener: consumes the probe's replies, passes everything else on. */
    public function onInput(InputEvent $event): void
    {
        $data = $event->getData();
        if (\str_starts_with($data, "\x1b_G")) {
            // Only the probe's own reply says anything; the rest are answers to
            // commands whose q=2 a terminal ignored, and are swallowed alike.
            if (\str_starts_with($data, "\x1b_Gi=".self::PROBE_ID.';OK')) {
                $this->answeredKitty = true;
            }
            $event->stopPropagation();

            return;
        }
        $size = [];
        if (1 === \preg_match('/^\x1b\[6;(\d+);(\d+)t$/', $data, $size)) {
            $this->cellHeight = \max(1, (int) $size[1]);
            $this->cellWidth = \max(1, (int) $size[2]);
            $event->stopPropagation();

            return;
        }
        if (1 === \preg_match('/^\x1b\[\?[\d;]*c$/', $data)) {
            $event->stopPropagation();
            if (null !== $this->timer) {
                $this->scheduler->cancel($this->timer);
                $this->timer = null;
                $this->resolve($this->confirmed());
            }
        }
    }

    /**
     * Call $listener when the terminal's answer changes what a pane should
     * paint. A render request alone is not enough: the renderer keeps a
     * widget's lines until that widget is invalidated, so a pane that painted
     * half-blocks before the answer would go on painting them.
     *
     * @param \Closure(): void $listener
     */
    public function onChange(\Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** Whether a pane may paint pixels rather than half-blocks. */
    public function available(): bool
    {
        return true === $this->kitty;
    }

    /**
     * How many of the terminal's pixels make one of the display's logical
     * pixels: 2 on a Retina screen, where the terminal counts device pixels.
     * A zoom of 100% means one document pixel per LOGICAL pixel, as in a
     * browser; a sharp picture is still drawn in device pixels.
     */
    public function pixelRatio(): float
    {
        return $this->pixelRatio;
    }

    /** Adopt a new ratio, and tell the panes when it changed what 100% means. */
    public function setPixelRatio(float $ratio): void
    {
        $ratio = \max(1.0, $ratio);
        if (\abs($ratio - $this->pixelRatio) < 1e-9) {
            return;
        }
        $this->pixelRatio = $ratio;
        $this->notify();
    }

    /** @return array{0: int, 1: int} one cell's width and height in pixels */
    public function cellPixels(): array
    {
        return [$this->cellWidth, $this->cellHeight];
    }

    /** A fresh image id for one consumer to reuse for everything it shows. */
    public function allocateId(): int
    {
        return $this->nextId++;
    }

    /** Store $png as image $id, replacing whatever the id held (and its placement). */
    public function transmit(int $id, string $png): void
    {
        $chunks = \str_split(\base64_encode($png), self::CHUNK);
        $last = \count($chunks) - 1;
        $bytes = '';
        foreach ($chunks as $index => $chunk) {
            $keys = 0 === $index ? "a=t,f=100,t=d,i={$id},q=2," : '';
            $bytes .= "\x1b_G{$keys}m=".($index < $last ? 1 : 0).";{$chunk}\x1b\\";
        }
        $this->tui->getTerminal()->write($bytes);
        $this->transmitted[$id] = true;
        unset($this->placed[$id]);
    }

    /**
     * Stretch image $id over $columns × $rows placeholder cells. Sends nothing
     * when that is already what the terminal shows, so a repaint of an
     * unchanged view costs a string compare.
     *
     * The whole image, always: a virtual placement's source rectangle is part
     * of the spec, but Ghostty draws the whole image regardless, so a view is
     * cut out BEFORE it is sent ({@see \Porta\ImageViewer\PixelPlacement}).
     */
    public function place(int $id, int $columns, int $rows): void
    {
        $command = "a=p,U=1,i={$id},p=1,c={$columns},r={$rows},q=2";
        if (($this->placed[$id] ?? null) === $command) {
            return;
        }
        $this->placed[$id] = $command;
        $this->tui->getTerminal()->write("\x1b_G{$command}\x1b\\");
    }

    /** Free image $id's pixels in the terminal. */
    public function delete(int $id): void
    {
        if (!\array_key_exists($id, $this->transmitted)) {
            return;
        }
        unset($this->transmitted[$id], $this->placed[$id]);
        $this->tui->getTerminal()->write("\x1b_Ga=d,d=I,i={$id},q=2\x1b\\");
    }

    /** Teardown: free every image and stop waiting for an answer. */
    public function release(): void
    {
        if (null !== $this->timer) {
            $this->scheduler->cancel($this->timer);
            $this->timer = null;
        }
        foreach (\array_keys($this->transmitted) as $id) {
            $this->delete($id);
        }
    }

    /**
     * Terminals that answer the query but cannot draw a placeholder, and the
     * multiplexer whose passthrough every command would need wrapping for.
     *
     * @param array<string, string|false> $env
     */
    private static function incapable(array $env): bool
    {
        $program = $env['TERM_PROGRAM'] ?? false;

        return 'WezTerm' === $program || \is_string($env['KONSOLE_VERSION'] ?? false) || \is_string($env['TMUX'] ?? false);
    }

    /** What the probe has established so far: an `OK`, or a forced yes. */
    private function confirmed(): bool
    {
        return $this->answeredKitty || true === $this->kitty;
    }

    private function resolve(bool $kitty): void
    {
        $changed = $kitty !== $this->kitty;
        $this->kitty = $kitty;
        if ($changed) {
            $this->notify();
        }
    }

    /** What a pane should paint changed: tell every listener, and draw again. */
    private function notify(): void
    {
        foreach ($this->listeners as $listener) {
            $listener();
        }
        $this->tui->requestRender();
    }
}
