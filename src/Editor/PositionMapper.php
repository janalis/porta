<?php

declare(strict_types=1);

namespace Porta\Editor;

/**
 * Coordinate conversions over a {@see Document}'s current lines: byte columns
 * ↔ UTF-16 code units (LSP positions), byte columns ↔ display columns (terminal
 * cells, tabs expanded), (line, col) ↔ flat byte offset, and the tab-expanded
 * "visual" lines the highlighter and renderer measure against. Reads the live
 * buffer through the document's accessors and owns the visual-lines cache
 * (keyed on the document version). Split out so the buffer class is not also
 * the coordinate system.
 */
final class PositionMapper
{
    /** @var list<string>|null tab-expanded lines, memoized per document version */
    private ?array $visualLines = null;
    private int $visualLinesVersion = -1;

    public function __construct(
        private readonly Document $document,
    ) {}

    /** Byte column → UTF-16 code-unit column on the given line (for LSP positions). */
    public function byteColToUtf16(int $line, int $byteCol): int
    {
        return TextMetrics::byteColToUtf16($this->document->getLine($this->document->clampLine($line)), $byteCol);
    }

    /** Convert a UTF-16 code-unit column (LSP position) on a line to a byte offset. */
    public function utf16ColToByteCol(int $line, int $utf16Col): int
    {
        return TextMetrics::utf16ColToByteCol($this->document->getLine($this->document->clampLine($line)), $utf16Col);
    }

    /** Display column (terminal cells) of a byte offset on the given line. */
    public function displayColAt(int $line, int $byteCol): int
    {
        return TextMetrics::displayWidth(substr($this->document->getLine($line), offset: 0, length: $byteCol), $this->document->tabWidth());
    }

    /** Full display width (terminal cells) of a line. */
    public function lineDisplayWidth(int $line): int
    {
        return TextMetrics::displayWidth($this->document->getLine($line), $this->document->tabWidth());
    }

    /** Byte column at (or before) a target display column on the given line. */
    public function byteColForDisplayCol(int $line, int $displayCol): int
    {
        return TextMetrics::byteColForDisplayCol($this->document->getLine($this->document->clampLine($line)), $displayCol, $this->document->tabWidth());
    }

    /**
     * Byte offset of a (line, col) position within the full text (lines joined
     * by "\n"). Clamps out-of-range positions to the document bounds.
     */
    public function offsetAt(int $line, int $col): int
    {
        $line = $this->document->clampLine($line);
        $offset = 0;
        for ($i = 0; $i < $line; ++$i) {
            $offset += \strlen($this->document->getLine($i)) + 1; // +1 for the newline
        }

        return $offset + $this->document->clampCol($line, $col);
    }

    /**
     * The (line, col) position of a byte offset within the full text — the
     * inverse of {@see offsetAt()}. Clamps to the document bounds.
     *
     * @return array{line: int, col: int}
     */
    public function positionAt(int $offset): array
    {
        $offset = max(0, $offset);
        $line = 0;
        $count = $this->document->getLineCount();
        while ($line < ($count - 1)) {
            $lineLen = \strlen($this->document->getLine($line)) + 1; // include newline
            if ($offset < $lineLen) {
                break;
            }
            $offset -= $lineLen;
            ++$line;
        }

        return ['line' => $line, 'col' => min($offset, \strlen($this->document->getLine($line)))];
    }

    /**
     * The document's lines with tabs expanded to spaces at tab stops, for the
     * highlighter and renderer. Cached per version; lines without tabs are
     * returned unchanged so tab-free files are untouched.
     *
     * @return list<string>
     */
    public function visualLines(): array
    {
        $version = $this->document->getVersion();
        if ($this->visualLinesVersion === $version && null !== $this->visualLines) {
            return $this->visualLines;
        }
        $this->visualLinesVersion = $version;

        // Copy-on-write over the document's own array: the common tab-free
        // file returns it as-is instead of rebuilding an N-slot copy per
        // keystroke; a file with tabs pays one engine-level copy plus the
        // expansion of exactly its tabbed lines.
        $tabWidth = $this->document->tabWidth();
        $lines = $this->document->getLines();
        /** @var list<string>|null $expanded only existing indexes are overwritten, so the list shape holds */
        $expanded = null;
        foreach ($lines as $i => $line) {
            if (!str_contains($line, "\t")) {
                continue;
            }

            $expanded ??= $lines;
            $expanded[$i] = TextMetrics::expandTabs($line, $tabWidth);
        }

        /** @var list<string> $result the loop only overwrites existing indexes, so the list shape holds */
        $result = $expanded ?? $lines;

        return $this->visualLines = $result;
    }

    /** Drop the tab-expansion cache after a tab-width change (next call rebuilds). */
    public function invalidateVisualLines(): void
    {
        $this->visualLinesVersion = -1;
    }
}
