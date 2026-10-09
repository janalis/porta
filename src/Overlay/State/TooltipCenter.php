<?php

declare(strict_types=1);

namespace Porta\Overlay\State;

use Porta\Container\AsIdeService;
use Porta\Support\Scheduler;
use Porta\Ui\Tooltip;
use Symfony\Component\Tui\Tui;

/**
 * The one tooltip: which element's card is pending, which is shown, and the
 * dwell between the two. The pointer router reports the element under the
 * pointer on every move ({@see point()}); a key or a press dismisses it
 * ({@see dismiss()}). Rendering belongs to {@see \Porta\Widget\Render\TooltipCard},
 * delivery to {@see \Porta\Overlay\Presenter\TooltipPresenter}.
 *
 * The dwell is keyed on the TARGET, never the pointer: a move that stays on
 * the same element changes nothing, so the jitter of a resting hand cannot
 * restart the timer. Once a card has shown, the next element's card follows
 * at once — while one is up, or within a short grace after the pointer left
 * it — the way a browser lets you read a toolbar by sweeping along it.
 */
#[AsIdeService]
final class TooltipCenter
{
    /** How long after a card leaves the next one still shows at once. */
    private const float GRACE_SECONDS = 0.6;

    private bool $enabled = true;

    private float $delay = 0.5;

    private ?Tooltip $pending = null;

    private ?string $timerId = null;

    private ?Tooltip $shown = null;

    /** Until when a new target skips the dwell — a card just left. */
    private float $warmUntil = 0.0;

    /** @var \Closure(): float seconds, monotonic — the clock the grace measures against */
    private readonly \Closure $now;

    /**
     * @param (\Closure(): float)|null $now seconds, monotonic; the system's by default, a test's own otherwise
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly Tui $tui,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): float => \hrtime(true) / 1e9;
    }

    /** The `tooltips` setting: off hides the card and keeps every later one away. */
    // @mago-expect lint:no-boolean-flag-parameter -- the bool IS the setting's value, not a second responsibility.
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
        if (!$enabled) {
            $this->dismiss();
        }
    }

    /** Whether cards show at all — what asks a server for a card's words checks it first. */
    public function enabled(): bool
    {
        return $this->enabled;
    }

    /** The `tooltipDelay` setting, in milliseconds; negative reads as none. */
    public function setDelayMs(int $milliseconds): void
    {
        $this->delay = \max(0, $milliseconds) / 1000;
    }

    /**
     * The element under the pointer now, or null for none. The same element
     * keeps its card (or its running dwell), refreshed in place when its words
     * or its span moved; another element starts its own dwell, or shows at
     * once when a card was just up.
     */
    public function point(?Tooltip $tip): void
    {
        if (!$this->enabled || null === $tip) {
            $this->leave();

            return;
        }
        $shown = $this->shown;
        if (null === $shown || $tip->key !== $shown->key) {
            $this->pointElsewhere($tip);

            return;
        }
        if (!self::same($tip, $shown)) {
            $this->shown = $tip;
            $this->tui->requestRender();
        }
    }

    /**
     * Not the element whose card is up: the dwelling one takes the new words,
     * another shows at once while warm, else after its own dwell.
     */
    private function pointElsewhere(Tooltip $tip): void
    {
        if ($tip->key === $this->pending?->key) {
            $this->pending = $tip;

            return;
        }
        $warm = null !== $this->shown || ($this->now)() < $this->warmUntil;
        $this->cancelDwell();
        if ($warm || $this->delay <= 0.0) {
            $this->shown = $tip;
            $this->tui->requestRender();

            return;
        }
        $this->shown = null;
        $this->pending = $tip;
        $this->timerId = $this->scheduler->delay($this->delay, function (): void {
            $this->timerId = null;
            $this->shown = $this->pending;
            $this->pending = null;
            $this->tui->requestRender();
        });
    }

    /**
     * A card's element has more to say than when it was asked — an answer it
     * was waiting on arrived. The card on screen, or the one still dwelling,
     * takes the new words in place; any other element's is ignored, and so is
     * one already dismissed, which a late answer must never bring back.
     */
    public function refresh(Tooltip $tip): void
    {
        if ($tip->key === $this->pending?->key) {
            $this->pending = $tip;

            return;
        }
        if ($tip->key === $this->shown?->key && !self::same($tip, $this->shown)) {
            $this->shown = $tip;
            $this->tui->requestRender();
        }
    }

    /**
     * A key, a press or a wheel: the card goes and the next one waits its full
     * dwell again — the user is doing something, not reading.
     */
    public function dismiss(): void
    {
        $this->cancelDwell();
        $this->warmUntil = 0.0;
        $this->hideShown();
    }

    /** The card on screen, or null. */
    public function shown(): ?Tooltip
    {
        return $this->shown;
    }

    /** The pointer left every element: the card goes, and a quick return skips the dwell. */
    private function leave(): void
    {
        $this->cancelDwell();
        if (null !== $this->shown) {
            $this->warmUntil = ($this->now)() + self::GRACE_SECONDS;
        }
        $this->hideShown();
    }

    private function hideShown(): void
    {
        if (null === $this->shown) {
            return;
        }
        $this->shown = null;
        $this->tui->requestRender();
    }

    private function cancelDwell(): void
    {
        if (null !== $this->timerId) {
            $this->scheduler->cancel($this->timerId);
            $this->timerId = null;
        }
        $this->pending = null;
    }

    private static function same(Tooltip $a, Tooltip $b): bool
    {
        return $a->text === $b->text && $a->row === $b->row && $a->colStart === $b->colStart && $a->colEnd === $b->colEnd;
    }
}
