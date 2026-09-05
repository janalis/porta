<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;
use Porta\Support\ProjectPaths;

/**
 * Where you were last TYPING — PhpStorm's Ctrl+Shift+Backspace.
 *
 * The sibling of {@see JumpHistory}, and deliberately not the same list.
 * Navigate-back returns you from a JUMP, so it only knows the places a
 * navigation verb pushed; scrolling away from your work, or clicking into
 * another file, pushes nothing. This one answers the other question, and it is
 * the more common one: "take me back to what I was writing".
 *
 * Repeated presses walk further back, which is why the whole list is kept and
 * not just the newest entry. Recording an edit resets that walk — you are
 * somewhere new, so the next press means "the place before HERE".
 */
#[AsIdeService]
final class EditHistory
{
    /**
     * Shallower than the jump stack's 50 on purpose: typing produces entries
     * far faster than navigating does, and a walk back through more than a
     * handful of edit sites has stopped being a shortcut.
     */
    private const int DEPTH = 10;

    /** @var list<array{path: string, line: int}> oldest first */
    private array $sites = [];

    /** How far back the current run of presses has walked; 0 = not walking. */
    private int $walked = 0;

    /**
     * Note an edit at $path:$line (0-based).
     *
     * Consecutive edits at the same place are one place — typing a word is one
     * edit site, not eight — and so are edits on the line already at the top,
     * which is what a run of keystrokes produces.
     */
    public function record(string $path, int $line): void
    {
        $this->walked = 0;
        $newest = $this->sites[\count($this->sites) - 1] ?? null;
        if (null !== $newest && $newest['path'] === $path && $newest['line'] === $line) {
            return;
        }

        $this->sites[] = ['path' => $path, 'line' => $line];
        if (\count($this->sites) > self::DEPTH) {
            array_shift($this->sites);
        }
    }

    /**
     * Follow a renamed file, so a walk back still lands where you were typing.
     *
     * Collapsing again afterwards, because a rename can make two neighbours the
     * same place — edit `A.php:5`, edit `B.php:5`, rename B to A — and the
     * class promises that one place is one entry. Without it a press would
     * appear to do nothing.
     */
    public function rename(string $from, string $to): void
    {
        $sites = [];
        foreach ($this->sites as $site) {
            $renamed = $site['path'] === $from ? ['path' => $to, 'line' => $site['line']] : $site;
            if (($sites[\count($sites) - 1] ?? null) !== $renamed) {
                $sites[] = $renamed;
            }
        }
        $this->sites = $sites;
    }

    /**
     * Drop every site in a deleted file, so a walk back does not land on one.
     *
     * Collapsing afterwards for {@see rename()}'s reason — removing a site can
     * put two neighbours at the same place — and resetting the walk, because
     * the entries it was counting back through are not the same entries any
     * more. A press after a delete means "the place before here", which is what
     * a fresh walk gives.
     */
    public function forget(string $path): void
    {
        $sites = [];
        foreach ($this->sites as $site) {
            if (ProjectPaths::isUnder($site['path'], $path)) {
                continue;
            }
            if (($sites[\count($sites) - 1] ?? null) !== $site) {
                $sites[] = $site;
            }
        }
        $this->sites = $sites;
        $this->walked = 0;
    }

    /**
     * The next site to go to, walking back one more each call, or null when
     * there is nothing left to walk to.
     *
     * @return array{path: string, line: int}|null
     */
    public function walkBack(): ?array
    {
        $index = \count($this->sites) - 1 - $this->walked;
        if ($index < 0) {
            return null;
        }

        ++$this->walked;

        return $this->sites[$index];
    }
}
