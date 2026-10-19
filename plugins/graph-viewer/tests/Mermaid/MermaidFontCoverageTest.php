<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Tests\Mermaid;

use PHPUnit\Framework\TestCase;
use Porta\GraphViewer\Graph\GraphFont;
use Porta\GraphViewer\Mermaid\MermaidFallbackFonts;
use Porta\GraphViewer\Mermaid\MermaidFont;
use Porta\GraphViewer\Mermaid\MermaidFontCoverage;

/** Font coverage from `cmap` tables, on the bundled faces and on minimal synthetic fonts. */
final class MermaidFontCoverageTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (!\is_file($file)) {
                continue;
            }
            \unlink($file);
        }
    }

    public function testTheBundledFacesCoverLatinAndSymbolsButNotCjkOrEmoji(): void
    {
        foreach (GraphFont::cases() as $face) {
            $coverage = new MermaidFontCoverage(MermaidFont::file($face, MermaidFont::FILE));
            $this->assertTrue($coverage->covers(0x41));
            $this->assertTrue($coverage->covers(0xE9));
            $this->assertTrue($coverage->covers(0x2192));
            $this->assertFalse($coverage->covers(0x6F22));
            $this->assertFalse($coverage->covers(0x1_F60E));
        }
    }

    public function testGroupedTablesCoverWholeRangesBeyondTheBasicPlane(): void
    {
        $coverage = new MermaidFontCoverage($this->font(self::grouped([
            [0x41,     0x5A,     1],
            [0x1_F600, 0x1_F64F, 30],
            [0x2000,   0x2000,   0],
        ])));
        $this->assertTrue($coverage->covers(0x41));
        $this->assertTrue($coverage->covers(0x5A));
        $this->assertFalse($coverage->covers(0x5B));
        $this->assertFalse($coverage->covers(0x40));
        $this->assertTrue($coverage->covers(0x1_F60E));
        $this->assertFalse($coverage->covers(0x2000), 'a group mapped to glyph zero is missing');
        $this->assertTrue($coverage->covers(0x1_F60E), 'answers are remembered');
    }

    public function testSegmentedTablesHonorDeltasAndGlyphIndexes(): void
    {
        // Segments: 0x41–0x43 by delta, 0x61–0x62 through the glyph array (0x62 maps to zero), then 0xFFFF.
        $coverage = new MermaidFontCoverage($this->font(self::segments()));
        $this->assertTrue($coverage->covers(0x41));
        $this->assertTrue($coverage->covers(0x43));
        $this->assertFalse($coverage->covers(0x44));
        $this->assertTrue($coverage->covers(0x61));
        $this->assertFalse($coverage->covers(0x62));
        $this->assertFalse($coverage->covers(0xFFFF));
        $this->assertFalse($coverage->covers(0x1_F60E), 'format 4 stops at the Basic Multilingual Plane');
    }

    public function testTheFullRepertoireTableWinsOverTheBasicPlaneOne(): void
    {
        $bmp = \pack('nnnnnnn', 4, 24, 0, 2, 0, 0, 0).\pack('n', 0xFFFF).\pack('n', 0).\pack('n', 0xFFFF).\pack('n', 1).\pack('n', 0);
        $full = \pack('nnNNN', 12, 0, 28, 0, 1).\pack('NNN', 0x1_F600, 0x1_F64F, 30);
        $cmap = \pack('nn', 0, 2).\pack('nnN', 3, 1, 20).\pack('nnN', 3, 10, 20 + \strlen($bmp)).$bmp.$full;
        $this->assertTrue(new MermaidFontCoverage($this->font(self::sfnt($cmap)))->covers(0x1_F60E));
    }

    public function testCollectionsAnswerForTheirFirstFace(): void
    {
        $font = self::grouped([[0x6F22, 0x6F22, 5]]);
        $collection = 'ttcf'.\pack('NNN', 0x0001_0000, 1, 16).$font;
        // The face offsets inside the collection move by the 16-byte header.
        $collection = \substr($collection, offset: 0, length: 16).self::shifted($font, 16);
        $this->assertTrue(new MermaidFontCoverage($this->font($collection))->covers(0x6F22));
    }

    public function testFontsWithoutAUsableTableCoverNothing(): void
    {
        $this->assertFalse(new MermaidFontCoverage('/nonexistent/font.ttf')->covers(0x41));
        $this->assertFalse(new MermaidFontCoverage($this->font(\pack('NnnnnNNNN', 0x0001_0000, 1, 0, 0, 0, 0x6E61_6D65, 0, 0, 0)))->covers(0x41), 'no cmap table');
        // A Macintosh-platform subtable is not Unicode.
        $this->assertFalse(new MermaidFontCoverage($this->font(self::grouped([[0x41, 0x5A, 1]], platform: 1, encoding: 0)))->covers(0x41));
        $this->assertFalse(new MermaidFontCoverage($this->font(self::grouped([[0x41, 0x5A, 1]], format: 6)))->covers(0x41));
    }

    public function testFallbacksTakeTheFirstReadableFaceThatCovers(): void
    {
        $latin = $this->font(self::grouped([[0x41, 0x5A, 1]]));
        $cjk = $this->font(self::grouped([[0x41, 0x41, 2], [0x6F22, 0x6F22, 5]]));
        $fallbacks = new MermaidFallbackFonts(['/nonexistent/font.ttf', $latin, $cjk]);
        $this->assertSame($cjk, $fallbacks->font(0x6F22));
        $this->assertSame($latin, $fallbacks->font(0x41));
        $this->assertNull($fallbacks->font(0x1_F60E));
        $this->assertSame($fallbacks->coverage($latin), $fallbacks->coverage($latin));
        $this->assertNotEmpty(MermaidFallbackFonts::HOST);
    }

    /** @param list<array{int, int, int}> $groups */
    private static function grouped(array $groups, int $platform = 3, int $encoding = 10, int $format = 12): string
    {
        $table = \pack('nnNNN', $format, 0, 16 + (12 * \count($groups)), 0, \count($groups));
        foreach ($groups as [$start, $end, $glyph]) {
            $table .= \pack('NNN', $start, $end, $glyph);
        }

        return self::sfnt(\pack('nn', 0, 1).\pack('nnN', $platform, $encoding, 12).$table);
    }

    private static function segments(): string
    {
        $ends = [0x43, 0x62, 0xFFFF];
        $starts = [0x41, 0x61, 0xFFFF];
        $deltas = [-0x40, 5, 0];
        $ranges = [0, 4, 0];
        $count = \count($ends);
        $glyphs = \pack('nn', 7, 0);
        $length = 16 + (8 * $count) + \strlen($glyphs);
        $table = \pack('nnnnnnn', 4, $length, 0, 2 * $count, 0, 0, 0);
        $table .= \pack('n*', ...$ends).\pack('n', 0).\pack('n*', ...$starts);
        $table .= \pack('n*', ...\array_map(static fn (int $delta): int => $delta & 0xFFFF, $deltas)).\pack('n*', ...$ranges).$glyphs;

        return self::sfnt(\pack('nn', 0, 1).\pack('nnN', 3, 1, 12).$table);
    }

    /** One `cmap` table behind a one-table directory. */
    private static function sfnt(string $cmap): string
    {
        return \pack('NnnnnNNNN', 0x0001_0000, 1, 0, 0, 0, 0x636D_6170, 0, 28, \strlen($cmap)).$cmap;
    }

    /** The font with its table offsets moved, as a collection places it. */
    private static function shifted(string $font, int $by): string
    {
        $offset = \unpack('N', $font, offset: 20)[1] ?? 0;

        return \substr($font, offset: 0, length: 20).\pack('N', (int) $offset + $by).\substr($font, offset: 24);
    }

    private function font(string $bytes): string
    {
        $path = (string) \tempnam(\sys_get_temp_dir(), prefix: 'porta-font-');
        \file_put_contents($path, $bytes);
        $this->files[] = $path;

        return $path;
    }
}
