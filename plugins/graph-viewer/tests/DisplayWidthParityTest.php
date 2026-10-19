<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests;

use Janalis\Viz\Text\DisplayWidth;
use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiText;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * The native layouts measure labels with janalis/viz's own DisplayWidth, and the
 * pane draws them with symfony/tui: one column apart and every cell rendering of
 * a Mermaid scene would be misaligned. Every line of the Mermaid corpus is a label
 * somewhere, so the two must agree on all of them.
 */
final class DisplayWidthParityTest extends TestCase
{
    public function testTheLibraryMeasuresEveryCorpusLineAsTheTerminalDraws(): void
    {
        $lines = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/fixtures/mermaid', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'mmd' !== $file->getExtension()) {
                continue;
            }
            foreach (\explode("\n", (string) \file_get_contents($file->getPathname())) as $line) {
                $this->assertSame(AnsiUtils::visibleWidth($line), DisplayWidth::of($line), $file->getFilename().': '.$line);
                ++$lines;
            }
        }

        $this->assertGreaterThan(10_000, $lines, 'the corpus was read');
    }

    public function testWrappingCutsWhereTheTerminalWould(): void
    {
        foreach (['日本語のラベル', "e\u{0301}tude longue", "👨\u{200D}👩\u{200D}👧 family", "\tindented text"] as $text) {
            foreach ([1, 2, 3, 5, 8] as $width) {
                $this->assertSame(AnsiText::wrap($text, $width), DisplayWidth::wrap($text, $width), $text.' @ '.$width);
            }
        }
    }
}
