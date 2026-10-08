<?php

declare(strict_types=1);

namespace Porta\Markdown\Block;

/**
 * Where the block parser stands on the current line: the byte offset, the
 * tab-expanded column, and whether a tab was only partly consumed — the
 * CommonMark bookkeeping that lets a container eat "four columns" of a tab
 * and leave the rest as indentation for whatever it contains.
 */
final class LineCursor
{
    public string $line = '';
    /** The source offset of the line's first byte. */
    public int $lineStart = 0;
    public int $offset = 0;
    public int $column = 0;
    public bool $partialTab = false;
    public int $nextNonspace = 0;
    public int $nextNonspaceColumn = 0;
    public int $indent = 0;
    public bool $blank = false;

    public function reset(string $line, int $lineStart): void
    {
        $this->line = $line;
        $this->lineStart = $lineStart;
        $this->offset = 0;
        $this->column = 0;
        $this->partialTab = false;
        $this->findNextNonspace();
    }

    public function indented(): bool
    {
        return $this->indent >= 4;
    }

    /** The byte at the next non-space position, or '' at the end. */
    public function peekNonspace(): string
    {
        return $this->line[$this->nextNonspace] ?? '';
    }

    /** The rest of the line from the next non-space byte. */
    public function restFromNonspace(): string
    {
        return \substr($this->line, $this->nextNonspace);
    }

    public function findNextNonspace(): void
    {
        $i = $this->offset;
        $cols = $this->column;
        $length = \strlen($this->line);
        while ($i < $length && (' ' === $this->line[$i] || "\t" === $this->line[$i])) {
            $cols += ' ' === $this->line[$i] ? 1 : 4 - ($cols % 4);
            ++$i;
        }
        $this->blank = $i >= $length;
        $this->nextNonspace = $i;
        $this->nextNonspaceColumn = $cols;
        $this->indent = $this->nextNonspaceColumn - $this->column;
    }

    public function advanceNextNonspace(): void
    {
        $this->offset = $this->nextNonspace;
        $this->column = $this->nextNonspaceColumn;
        $this->partialTab = false;
    }

    /** Move on by $count bytes, a tab counting as the columns to its stop. */
    public function advance(int $count): void
    {
        $length = \strlen($this->line);
        for (; $count > 0 && $this->offset < $length; --$count) {
            $this->column += "\t" === $this->line[$this->offset] ? 4 - ($this->column % 4) : 1;
            $this->partialTab = false;
            ++$this->offset;
        }
    }

    /**
     * Move on by $count COLUMNS: a tab straddling the target column is
     * consumed only in part, and the rest of it stays indentation.
     */
    public function advanceColumns(int $count): void
    {
        $length = \strlen($this->line);
        while ($count > 0 && $this->offset < $length) {
            $step = "\t" === $this->line[$this->offset] ? \min(4 - ($this->column % 4), $count) : 1;
            $this->partialTab = "\t" === $this->line[$this->offset] && (4 - ($this->column % 4)) > $count;
            $this->column += $step;
            $this->offset += $this->partialTab ? 0 : 1;
            $count -= $step;
        }
    }

    /** The source offset of a byte of the current line. */
    public function source(int $offset): int
    {
        return $this->lineStart + $offset;
    }
}
