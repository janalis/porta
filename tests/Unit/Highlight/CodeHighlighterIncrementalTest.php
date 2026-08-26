<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Highlight;

use PHPUnit\Framework\TestCase;
use Porta\Highlight\CodeHighlighter;
use Porta\Highlight\HighlightRequest;
use Porta\Highlight\HighlightSink;
use Porta\Highlight\SyntaxHighlightProvider;
use Porta\Tests\Support\ClosureHighlightProvider;
use Porta\Tests\Support\Probe;
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

    public function testTheParseSeamIsToldFullVersusSplice(): void
    {
        /** @var list<bool> $parses */
        $parses = [];
        $highlighter = new CodeHighlighter(onParse: static function (bool $incremental) use (&$parses): void {
            $parses[] = $incremental;
        });
        $highlighter->setFile('big.php');
        $doc = $this->bigDocument();

        $highlighter->highlight($doc, 1);
        $this->assertSame([false], $parses, 'the first parse is a full one');

        $doc[1500] = '$edited = "new text";';
        $highlighter->highlight($doc, 2);
        $this->assertSame([false, true], $parses, 'the local edit reports a splice');
    }

    public function testLocalEditReusesUnchangedLines(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $first = $highlighter->highlight($doc, 1);
        $this->assertSame(1, Probe::get($highlighter, 'fullParses'));

        $doc[1500] = '$edited = "new text";';
        $second = $highlighter->highlight($doc, 2);

        $stats = ['fullParses' => Probe::get($highlighter, 'fullParses'), 'incrementalUpdates' => Probe::get($highlighter, 'incrementalUpdates')];
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

        array_splice($doc, offset: 100, length: 0, replacement: ['$inserted = true;']);
        $afterInsert = $highlighter->highlight($doc, 2);
        $this->assertSame('$inserted = true;', AnsiUtils::stripAnsiCodes($afterInsert[100]));
        $this->assertCount(\count($doc), $afterInsert);

        array_splice($doc, offset: 100, length: 1);
        $afterDelete = $highlighter->highlight($doc, 3);
        $this->assertCount(\count($doc), $afterDelete);
        $this->assertSame(1, Probe::get($highlighter, 'fullParses'));
        $this->assertSame(2, Probe::get($highlighter, 'incrementalUpdates'));
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

        $this->assertSame(2, Probe::get($highlighter, 'fullParses'));
        $this->assertSame(0, Probe::get($highlighter, 'incrementalUpdates'));
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

        $stats = ['fullParses' => Probe::get($highlighter, 'fullParses'), 'incrementalUpdates' => Probe::get($highlighter, 'incrementalUpdates')];
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

        $this->assertSame(2, Probe::get($highlighter, 'fullParses'));
        $this->assertSame(0, Probe::get($highlighter, 'incrementalUpdates'));
    }

    public function testEmptyEditedLineAndParserFailureFallbacks(): void
    {
        $failing = new CodeHighlighter(new ClosureHighlightProvider(static function (string $content): string {
            if (!str_contains($content, "\n")) {
                throw new \RuntimeException('single-line parse fails');
            }

            return $content; // full-document parse: passthrough keeps line count
        }));
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
        $mangler = new CodeHighlighter(new ClosureHighlightProvider(static fn (string $content): string => str_contains($content, "\n") ? $content : $content."\nEXTRA"));
        $mangler->setFile('big.php');
        $doc = $this->bigDocument();
        $mangler->highlight($doc, 1);

        $doc[500] = '$y = 2;';
        $result = $mangler->highlight($doc, 2);

        $this->assertSame('$y = 2;', $result[500]);
        $this->assertCount(\count($doc), $result);
    }

    /** An asynchronous backend cannot splice locally, so it always forces a full parse. */
    public function testAsyncBackendNeverTakesTheIncrementalPath(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $content): string => $content, async: true);
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('big.php');
        $doc = $this->bigDocument();

        $highlighter->highlight($doc, 1);
        $provider->deliverPending();
        $highlighter->highlight($doc, 1); // pick up the resolved cache as lastRawLines

        $doc[100] = '$edited = true;';
        $highlighter->highlight($doc, 2);

        $this->assertSame(0, Probe::get($highlighter, 'incrementalUpdates'), 'an async backend re-dispatches instead of splicing');
    }

    /** The line-count floor is inclusive: exactly INCREMENTAL_MIN_LINES still qualifies. */
    public function testIncrementalMinLinesBoundaryIsInclusive(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument(CodeHighlighter::INCREMENTAL_MIN_LINES);
        $highlighter->highlight($doc, 1);

        $doc[100] = '$edited = true;';
        $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'), 'exactly INCREMENTAL_MIN_LINES lines still takes the incremental path');
    }

    /** A line-count delta of exactly MAX_LOCAL_CHANGE (3) is still local, not a forced full parse. */
    public function testLineCountDeltaBoundaryIsInclusive(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        array_splice($doc, offset: 100, length: 0, replacement: ['$one = 1;', '$two = 2;', '$three = 3;']);
        $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'), 'a 3-line delta is still within MAX_LOCAL_CHANGE');
    }

    /**
     * staleEdits reaches exactly REPAIR_EVERY (25) after the 25th incremental
     * edit; the 26th edit's own check must be the one that trips the repair
     * (>=), not the 27th (>).
     */
    public function testRepairParseBoundaryTripsOnTheEditWhereStaleEditsReachesRepairEvery(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        for ($i = 0; $i < 25; ++$i) {
            $doc[1000] = "\$edit{$i} = {$i};";
            $highlighter->highlight($doc, $i + 2);
        }
        $this->assertSame(1, Probe::get($highlighter, 'fullParses'), 'still incremental through the 25th edit');

        $doc[1000] = '$edit25 = 25;';
        $highlighter->highlight($doc, 27);

        $this->assertSame(2, Probe::get($highlighter, 'fullParses'), 'the 26th edit, where staleEdits has reached REPAIR_EVERY, is the one that trips the repair');
    }

    /** A pure append (every old line matches as a prefix) must not read one past the old array. */
    public function testPrefixMatchLoopBoundaryDoesNotReadPastTheOldArray(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $doc[] = '$appended = true;'; // every old line is an exact prefix of the new document
        $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'));
    }

    /** A pure prepend (every old line matches as a suffix) must not read one before the old array. */
    public function testSuffixMatchLoopBoundaryDoesNotReadPastTheOldArray(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        array_unshift($doc, '$prepended = true;'); // every old line is an exact suffix of the new document
        $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'));
    }

    /** The changed "middle" region is exactly MAX_LOCAL_CHANGE lines wide at the boundary — still local. */
    public function testMiddleChangeSizeBoundaryIsInclusive(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        for ($i = 100; $i < 103; ++$i) { // exactly 3 changed lines, no count change
            $doc[$i] = "\$rewritten{$i} = true;";
        }
        $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'), 'a 3-line middle change is still within MAX_LOCAL_CHANGE');
        $this->assertSame(1, Probe::get($highlighter, 'fullParses'), 'no forced full reparse for a boundary-sized change');
    }

    /** Deleting lines exercises the suffix-splice path (suffix > 0) distinctly from a pure edit. */
    public function testSuffixSpliceReusesTheTrailingCachedLines(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $first = $highlighter->highlight($doc, 1);

        array_splice($doc, offset: 100, length: 1); // pure deletion: a real (nonzero) suffix shift
        $second = $highlighter->highlight($doc, 2);

        $this->assertSame(1, Probe::get($highlighter, 'incrementalUpdates'));
        $this->assertSame($first[2000], $second[1999], 'the trailing cached lines were reused via the suffix slice, shifted by the deletion');
    }

    /**
     * Content genuinely unchanged, only the version bumped: prefix consumes
     * the entire document, so the suffix bound must clamp to zero rather than
     * let the suffix loop walk backward off the start of the array.
     */
    public function testIdenticalContentAtANewVersionDoesNotWalkPastTheStartOfTheArray(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $result = $highlighter->highlight($doc, 2);

        $this->assertCount(\count($doc), $result);
    }

    /**
     * The "too big to splice" guard is an OR: either side alone exceeding
     * MAX_LOCAL_CHANGE must force a full parse, even when the OTHER side
     * (and the overall line-count delta) is small.
     */
    public function testEitherSideAloneExceedingMaxLocalChangeForcesAFullParse(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        // Replace 2 old lines with 5 new ones: middleOld=2 (within budget),
        // middleNew=5 (over budget), overall delta=+3 (within budget too) —
        // only the AND-vulnerable middleNew side trips the guard.
        array_splice($doc, offset: 100, length: 2, replacement: ['$a=1;', '$b=2;', '$c=3;', '$d=4;', '$e=5;']);
        $highlighter->highlight($doc, 2);

        $this->assertSame(2, Probe::get($highlighter, 'fullParses'), 'middleNew alone exceeding the budget must still force a full parse');
        $this->assertSame(0, Probe::get($highlighter, 'incrementalUpdates'));
    }

    /** Editing the very last line leaves zero common suffix — the splice must not append the whole cache. */
    public function testEditingTheLastLineLeavesZeroCommonSuffix(): void
    {
        $highlighter = $this->highlighter();
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $doc[\count($doc) - 1] = '$last = true;';
        $result = $highlighter->highlight($doc, 2);

        $this->assertCount(\count($doc), $result, 'a zero-length suffix must not fall back to slicing the entire cache');
    }

    /** An edited-to-empty line is a no-op short-circuit; it must never reach the backend. */
    public function testEmptyEditedLineNeverReachesTheBackend(): void
    {
        $provider = new ClosureHighlightProvider(static fn (string $content): string => "MARK:{$content}");
        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('big.php');
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $doc[500] = '';
        $result = $highlighter->highlight($doc, 2);

        $this->assertSame('', $result[500], 'an empty edited line short-circuits before the backend ever sees it');
    }

    /**
     * A per-line backend that delivers something and THEN throws must still
     * fall back to the raw line — the catch wins over whatever was captured.
     */
    public function testPerLineParserFailureIgnoresAnythingAlreadyDelivered(): void
    {
        $provider = new class implements SyntaxHighlightProvider {
            public function id(): string
            {
                return 'deliver-then-throw';
            }

            public function supports(string $language): bool
            {
                return true;
            }

            public function highlight(HighlightRequest $request, HighlightSink $sink): void
            {
                if (str_contains($request->text, "\n")) {
                    $sink->deliver($request->documentId, $request->version, $request->text);

                    return;
                }
                // Simulates a backend that half-answers, then errors: the
                // per-line splice path must not trust this partial delivery.
                $sink->deliver($request->documentId, $request->version, 'WRONG_MARKUP');

                throw new \RuntimeException('boom after delivering');
            }
        };

        $highlighter = new CodeHighlighter($provider);
        $highlighter->setFile('big.php');
        $doc = $this->bigDocument();
        $highlighter->highlight($doc, 1);

        $doc[500] = '$x = 1;';
        $result = $highlighter->highlight($doc, 2);

        $this->assertSame('$x = 1;', $result[500], 'the raw line wins over a delivery that arrived just before the throw');
    }
}
