<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;

/**
 * The places you jumped away from, oldest first — pushed by every navigation
 * verb before it moves the caret, popped by navigate-back.
 *
 * A service rather than a field on {@see \Porta\Lsp\NavigationController}
 * because it gained a second reader: PhpStorm's Recent Locations lists the
 * whole stack instead of walking it one pop at a time, and BROWSING the list
 * must not consume it.
 */
#[AsIdeService]
final class JumpHistory
{
    /**
     * Deep enough to cover a session's wandering, shallow enough that the list
     * stays a list. The number is PhpStorm's.
     */
    private const int DEPTH = 50;

    /** @var list<array{path: string, line: int, col: int}> */
    private array $stack = [];

    public function push(string $path, int $line, int $col): void
    {
        $this->stack[] = ['path' => $path, 'line' => $line, 'col' => $col];
        if (\count($this->stack) > self::DEPTH) {
            array_shift($this->stack);
        }
    }

    /** @return array{path: string, line: int, col: int}|null */
    public function pop(): ?array
    {
        return array_pop($this->stack);
    }

    /** @return list<array{path: string, line: int, col: int}> most recent first, as the list reads */
    public function entries(): array
    {
        return array_reverse($this->stack);
    }
}
