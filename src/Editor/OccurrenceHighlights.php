<?php

declare(strict_types=1);

namespace Porta\Editor;

use Porta\Container\AsIdeService;

/**
 * The language server's answer to "where else does the symbol under the caret
 * appear, and which of those are writes" — held between the controller that
 * asks and the editor that paints it.
 *
 * The answer is STAMPED with exactly what was asked: file, document version,
 * caret line and utf16 column. Nothing merges and nothing is trusted by
 * position; a reply that no longer matches what is on screen simply loses to
 * the editor's own textual scan, which is what makes a late reply harmless
 * rather than a highlight on the wrong identifier.
 */
#[AsIdeService]
final class OccurrenceHighlights
{
    private string $stamp = '';

    /** @var list<array{0: int, 1: int, 2: int, 3: bool}> */
    private array $spans = [];

    /**
     * The last caret position reported to the asker, so a per-frame hook can
     * tell a real move from a repaint without asking anything.
     */
    private string $caret = '';

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: bool}> $spans [line, startDisplayCol, endDisplayCol, isWrite]
     */
    public function publish(string $path, int $version, int $line, int $utf16Col, array $spans): void
    {
        $this->stamp = self::stamp($path, $version, $line, $utf16Col);
        $this->spans = $spans;
    }

    /** Whether the answer on hand is about exactly this file, version and caret word. */
    public function isFresh(string $path, int $version, int $line, int $utf16Col): bool
    {
        return '' !== $this->stamp && $this->stamp === self::stamp($path, $version, $line, $utf16Col);
    }

    /**
     * The spans to paint: the server's when they are about what is on screen,
     * and $fallback (the editor's textual scan) otherwise.
     *
     * An EMPTY server answer still wins — "this symbol occurs once here" is an
     * answer, and falling back there would re-light the same-named symbols from
     * other scopes that asking the server was meant to stop.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: bool}> $fallback
     *
     * @return list<array{0: int, 1: int, 2: int, 3: bool}>
     */
    public function spansFor(?string $path, int $version, int $line, int $utf16Col, array $fallback): array
    {
        return null !== $path && $this->isFresh($path, $version, $line, $utf16Col) ? $this->spans : $fallback;
    }

    /**
     * True once per caret position, so a per-frame hook fires the lookup on a
     * real move and never on a repaint — a hook that re-armed a settle timer
     * every frame would let it fire never.
     */
    public function caretMovedTo(int $version, int $line, int $col): bool
    {
        $caret = $version.':'.$line.':'.$col;
        if ($caret === $this->caret) {
            return false;
        }
        $this->caret = $caret;

        return true;
    }

    private static function stamp(string $path, int $version, int $line, int $utf16Col): string
    {
        return $path.'@'.$version.':'.$line.':'.$utf16Col;
    }
}
