<?php

declare(strict_types=1);

namespace Porta\Terminal;

use Porta\Support\IndexMove;
use Porta\Support\IntMath;

/**
 * The open terminal sessions and the active-tab pointer. A plain collection
 * (no Tui/widget deps) so the controller choreographs open/focus/render while
 * this owns membership and the active index — the same split the panel
 * controllers use elsewhere.
 */
final class TerminalSessions
{
    /** @var list<TerminalSession> */
    public private(set) array $sessions = [];

    public private(set) int $activeIndex = 0;

    public function create(string $id, string $title): TerminalSession
    {
        $session = new TerminalSession($id, $title, new TerminalBuffer());
        $this->sessions[] = $session;
        $this->activeIndex = \count($this->sessions) - 1;

        return $session;
    }

    public function find(string $id): ?TerminalSession
    {
        foreach ($this->sessions as $session) {
            if ($session->id === $id) {
                return $session;
            }
        }

        return null;
    }

    /** Make an existing session active (by identity); a no-op if it isn't a member. */
    public function makeActive(TerminalSession $session): void
    {
        $index = \array_search($session, $this->sessions, strict: true);
        if (false !== $index) {
            $this->activeIndex = $index;
        }
    }

    /**
     * Move the session at $from to position $to (drag-reorder), keeping the
     * active pointer on the same session.
     */
    public function move(int $from, int $to): void
    {
        $target = IndexMove::target($from, $to, \count($this->sessions));
        if (null === $target) {
            return;
        }
        $this->activeIndex = IndexMove::reindexActive($this->activeIndex, $from, $target);
        $this->sessions = IndexMove::apply($this->sessions, $from, $target);
    }

    public function isEmpty(): bool
    {
        return [] === $this->sessions;
    }

    public function active(): ?TerminalSession
    {
        return $this->sessions[$this->activeIndex] ?? null;
    }

    /** Activate the session at $index (clamped); returns the now-active session. */
    public function activate(int $index): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $this->activeIndex = IntMath::clamp($index, 0, \count($this->sessions) - 1);

        return $this->sessions[$this->activeIndex];
    }

    public function next(): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $this->activeIndex = ($this->activeIndex + 1) % \count($this->sessions);

        return $this->sessions[$this->activeIndex];
    }

    public function prev(): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $count = \count($this->sessions);
        $this->activeIndex = ($this->activeIndex - 1 + $count) % $count;

        return $this->sessions[$this->activeIndex];
    }

    /**
     * Close the session at $index (stopping its process), moving the active
     * pointer to the nearest surviving neighbour. Returns the now-active
     * session, or null when the list is emptied.
     */
    public function close(int $index): ?TerminalSession
    {
        if (!\array_key_exists($index, $this->sessions)) {
            return $this->active();
        }
        $this->sessions[$index]->stop();
        \array_splice($this->sessions, $index, length: 1);
        if ([] === $this->sessions) {
            $this->activeIndex = 0;

            return null;
        }
        $this->activeIndex = \min($this->activeIndex, \count($this->sessions) - 1);

        return $this->sessions[$this->activeIndex];
    }

    public function stopAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->stop();
        }
    }
}
