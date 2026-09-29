<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\AnsiSlice;
use Porta\Widget\Render\OverlayCompositor;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class AnsiSliceTest extends TestCase
{
    /** @return iterable<string, array{string}> styled lines whose runs carry combining marks */
    public static function marked(): iterable
    {
        // The vendor cut each of these short: a run with a mark that FITS the
        // slice is sized by mb_strwidth(), one column per mark.
        yield 'decomposed accent' => ["cafe\u{0301}\x1b[1m latte\x1b[0m done"];
        yield 'accents across a style change' => ["e\u{0301}e\u{0301}\x1b[1me\u{0301}e\u{0301}\x1b[0mxyzxyzxyz"];
        yield 'hebrew niqqud' => ["\u{05E9}\u{05B8}\u{05DC}\x1b[1mabcdefgh\x1b[0m"];
        yield 'a line already holding the first stand-in' => ["\u{100000}e\u{0301}\x1b[1mxy\x1b[0mz"];
        yield 'an OSC link around the accent' => ["\x1b]8;;https://x\x1b\\cafe\u{0301}\x1b]8;;\x1b\\ latte"];
    }

    #[DataProvider('marked')]
    public function testEverySliceIsAsWideAsAsked(string $line): void
    {
        $total = AnsiUtils::visibleWidth($line);
        for ($start = 0; $start < $total; ++$start) {
            for ($length = 1; ($start + $length) <= $total; ++$length) {
                $this->assertSame($length, AnsiUtils::visibleWidth(AnsiSlice::byColumn($line, $start, $length)), "byColumn({$start}, {$length})");
                $slice = AnsiSlice::withWidth($line, $start, $length);
                $this->assertSame($slice['width'], AnsiUtils::visibleWidth($slice['text']), "withWidth({$start}, {$length}) reports its real width");
            }
        }
    }

    #[DataProvider('marked')]
    public function testTwoSlicesMakeTheLineAgain(string $line): void
    {
        $total = AnsiUtils::visibleWidth($line);
        for ($cut = 0; $cut <= $total; ++$cut) {
            $joined = AnsiSlice::byColumn($line, 0, $cut).AnsiSlice::byColumn($line, $cut, $total - $cut);

            $this->assertSame(AnsiUtils::stripAnsiCodes($line), AnsiUtils::stripAnsiCodes($joined), "cut at {$cut}");
        }
    }

    #[DataProvider('marked')]
    public function testTruncatingKeepsWhatFits(string $line): void
    {
        $total = AnsiUtils::visibleWidth($line);
        for ($width = 4; $width < $total; ++$width) {
            $truncated = AnsiSlice::truncate($line, $width, ellipsis: '…');

            $this->assertSame($width, AnsiUtils::visibleWidth($truncated), "truncate({$width})");
            $this->assertStringEndsWith('…', AnsiUtils::stripAnsiCodes($truncated));
        }
        $this->assertSame($line, AnsiSlice::truncate($line, $total), 'nothing to cut');
    }

    public function testAPlainLineIsTheVendorsOwnAnswer(): void
    {
        $line = "\x1b[1mplain\x1b[0m ascii and ✓ wide 中文";

        $this->assertSame(AnsiUtils::sliceByColumn($line, 2, 9), AnsiSlice::byColumn($line, 2, 9));
        $this->assertSame(AnsiUtils::sliceWithWidth($line, 0, 7, strict: true), AnsiSlice::withWidth($line, 0, 7, strict: true));
        $this->assertSame(AnsiUtils::truncateToWidth($line, 10, pad: true), AnsiSlice::truncate($line, 10, pad: true));
    }

    public function testAWideCharacterWithAMarkSlicesLikeOneWithout(): void
    {
        $marked = "\u{4E2D}\u{0301}x\x1b[1myz\x1b[0mabc";
        $plain = "\u{4E2D}x\x1b[1myz\x1b[0mabc";

        foreach ([[0, 3], [1, 7], [2, 4], [0, 8]] as [$start, $length]) {
            $this->assertSame(
                AnsiUtils::visibleWidth(AnsiUtils::sliceByColumn($plain, $start, $length)),
                AnsiUtils::visibleWidth(AnsiSlice::byColumn($marked, $start, $length)),
                "byColumn({$start}, {$length})",
            );
        }
    }

    public function testAnOverlayOverAccentedTextIsNotCutShort(): void
    {
        // The symptom: a popup spliced over a row with a decomposed accent
        // lost a column per mark and was padded blank to make up the width.
        $row = "\x1b[32mcafe\u{0301}\x1b[0m au lait, cre\u{0300}me bru\u{0302}le\u{0301}e".\str_repeat(' ', times: 10);
        $columns = AnsiUtils::visibleWidth($row);

        $composed = OverlayCompositor::into($row, 12, 6, $columns, '[MENU]');

        $this->assertSame($columns, AnsiUtils::visibleWidth($composed));
        $plain = AnsiUtils::stripAnsiCodes($composed);
        $this->assertSame(12, AnsiUtils::visibleWidth(\substr($plain, offset: 0, length: (int) \strpos($plain, needle: '[MENU]'))), 'the popup starts at its own column');
        // Everything before the popup survives: the vendor dropped the `t` and
        // padded a blank in its place, which kept the popup's column honest
        // and hid the loss.
        $this->assertStringContainsString("cafe\u{0301} au lait[MENU]", $plain);
    }
}
