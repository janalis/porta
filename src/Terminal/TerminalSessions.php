<?php

declare(strict_types=1);

namespace Porta\Terminal;

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
    private array $sessions = [];

    private int $active = 0;

    public function create(string $id, string $title): TerminalSession
    {
        $session = new TerminalSession($id, $title, new TerminalBuffer());
        $this->sessions[] = $session;
        $this->active = \count($this->sessions) - 1;

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
        $index = array_search($session, $this->sessions, strict: true);
        if (false !== $index) {
            $this->active = $index;
        }
    }

    /**
     * Move the session at $from to position $to (drag-reorder), keeping the
     * active pointer on the same session.
     */
    public function move(int $from, int $to): void
    {
        $count = \count($this->sessions);
        if ($from < 0 || $from >= $count) {
            return;
        }
        $to = IntMath::clamp($to, 0, $count - 1);
        if ($from === $to) {
            return;
        }
        $moved = $this->sessions[$from];
        array_splice($this->sessions, $from, length: 1);
        array_splice($this->sessions, $to, length: 0, replacement: [$moved]);
        $this->active = match (true) {
            $this->active === $from => $to,
            $from < $this->active && $this->active <= $to => $this->active - 1,
            $to <= $this->active && $this->active < $from => $this->active + 1,
            default => $this->active,
        };
    }

    /** @return list<TerminalSession> */
    public function all(): array
    {
        return $this->sessions;
    }

    public function count(): int
    {
        return \count($this->sessions);
    }

    public function isEmpty(): bool
    {
        return [] === $this->sessions;
    }

    public function active(): ?TerminalSession
    {
        return $this->sessions[$this->active] ?? null;
    }

    public function activeIndex(): int
    {
        return $this->active;
    }

    /** Activate the session at $index (clamped); returns the now-active session. */
    public function activate(int $index): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $this->active = IntMath::clamp($index, 0, \count($this->sessions) - 1);

        return $this->sessions[$this->active];
    }

    public function next(): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $this->active = ($this->active + 1) % \count($this->sessions);

        return $this->sessions[$this->active];
    }

    public function prev(): ?TerminalSession
    {
        if ([] === $this->sessions) {
            return null;
        }
        $count = \count($this->sessions);
        $this->active = ($this->active - 1 + $count) % $count;

        return $this->sessions[$this->active];
    }

    /**
     * Close the session at $index (stopping its process), moving the active
     * pointer to the nearest surviving neighbour. Returns the now-active
     * session, or null when the list is emptied.
     */
    public function close(int $index): ?TerminalSession
    {
        if (!array_key_exists($index, $this->sessions)) {
            return $this->active();
        }
        $this->sessions[$index]->stop();
        array_splice($this->sessions, $index, length: 1);
        if ([] === $this->sessions) {
            $this->active = 0;

            return null;
        }
        $this->active = min($this->active, \count($this->sessions) - 1);

        return $this->sessions[$this->active];
    }

    public function stopAll(): void
    {
        foreach ($this->sessions as $session) {
            $session->stop();
        }
    }
}
