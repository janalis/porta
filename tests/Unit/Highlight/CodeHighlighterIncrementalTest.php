<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class CodeHighlighterIncrementalTest extends TestCase
{
    /** @return string[] */
    private function bigDocument(int $lines = CodeHighlighter::INCREMENTAL_MIN_LINES + 10): array
    {
        $doc = ['<?php'];
        for ($i = 1; $i < $lines; ++$i) {
            $doc[] = "\$var{$i} = {$i}; // line {$i}";
        }

        return $doc;
    }

    private function highlighter(): CodeHighlighter
    {
        $highlighter = new CodeHighlighter();
        $highlighter->setFile('big.php');

        return $highlighter;
    }

    public function testLocalEditReusesUnchangedLines(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $first = $highlighter->highlight($doc, 1);
        $this->assertSame(1, $highlighter->getStats()['fullParses']);

        $doc[1500] = '$edited = "new text";';
        $second = $highlighter->highlight($doc, 2);

        $stats = $highlighter->getStats();
        $this->assertSame(1, $stats['fullParses'], 'no second full parse');
        $this->assertSame(1, $stats['incrementalUpdates']);
        $this->assertCount(\count($doc), $second);
        $this->assertSame($first[10], $second[10], 'untouched prefix line reused verbatim');
        $this->assertSame($first[2000], $second[2000], 'untouched suffix line reused verbatim');
        $this->assertSame('$edited = "new text";', AnsiUtils::stripAnsiCodes($second[1500]));
        $this->assertStringContainsString("\x1b[", $second[1500], 'edited line still highlighted');
    }

    public function testLineInsertionAndDeletionStayIncremental(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        array_splice($doc, 100, 0, ['$inserted = true;']);
        $afterInsert = $highlighter->highlight($doc, 2);
        $this->assertSame('$inserted = true;', AnsiUtils::stripAnsiCodes($afterInsert[100]));
        $this->assertCount(\count($doc), $afterInsert);

        array_splice($doc, 100, 1);
        $afterDelete = $highlighter->highlight($doc, 3);
        $this->assertCount(\count($doc), $afterDelete);
        $this->assertSame(1, $highlighter->getStats()['fullParses']);
        $this->assertSame(2, $highlighter->getStats()['incrementalUpdates']);
    }

    public function testNonLocalChangeForcesFullParse(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        for ($i = 200; $i < 220; ++$i) { // 20 scattered lines changed
            $doc[$i] = "\$rewritten{$i} = true;";
        }
        $highlighter->highlight($doc, 2);

        $this->assertSame(2, $highlighter->getStats()['fullParses']);
        $this->assertSame(0, $highlighter->getStats()['incrementalUpdates']);
    }

    public function testRepairParseAfterManyIncrementalEdits(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $version = 2;
        for ($i = 0; $i < 30; ++$i) {
            $doc[1000] = "\$edit{$i} = {$i};";
            $highlighter->highlight($doc, $version++);
        }

        $stats = $highlighter->getStats();
        // Edits 1-25 incremental, edit 26 triggers a repair parse (counter
        // resets), edits 27-30 incremental again.
        $this->assertSame(29, $stats['incrementalUpdates']);
        $this->assertSame(2, $stats['fullParses']);
    }

    public function testSmallDocumentsAlwaysFullParse(): void
    {
        $highlighter = $this->highlighter();
        $doc = ['<?php', '$a = 1;', '$b = 2;'];
        $highlighter->highlight($doc, 1);

        $doc[1] = '$a = 99;';
        $highlighter->highlight($doc, 2);

        $this->assertSame(2, $highlighter->getStats()['fullParses']);
        $this->assertSame(0, $highlighter->getStats()['incrementalUpdates']);
    }

    public function testEmptyEditedLineAndParserFailureFallbacks(): void
    {
        $failing = new CodeHighlighter(static function (string $content): string {
            if (!str_contains($content, "\n")) {
                throw new \RuntimeException('single-line parse fails');
            }

            return $content; // full-document parse: passthrough keeps line count
        });
        $failing->setFile('big.php');
        $doc = $this->bigDocument();
        $failing->highlight($doc, 1);

        $doc[500] = '';
        $result = $failing->highlight($doc, 2);
        $this->assertSame('', $result[500], 'empty line short-circuits');

        $doc[500] = '$x = 1;';
        $result = $failing->highlight($doc, 3);
        $this->assertSame('$x = 1;', $result[500], 'per-line parser failure falls back to raw text');
    }

    public function testSingleLineParserProducingNewlinesFallsBackToRaw(): void
    {
        $mangler = new CodeHighlighter(static function (string $content): string {
            return str_contains($content, "\n") ? $content : $content."\nEXTRA";
        });
        $mangler->setFile('big.php');
        $doc = $this->bigDocument();
        $mangler->highlight($doc, 1);

        $doc[500] = '$y = 2;';
        $result = $mangler->highlight($doc, 2);

        $this->assertSame('$y = 2;', $result[500]);
        $this->assertCount(\count($doc), $result);
    }
}
