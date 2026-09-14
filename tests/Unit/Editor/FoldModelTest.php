<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Editor\FoldModel;

final class FoldModelTest extends TestCase
{
    public function testSnapVisiblePublicApiWithNoFoldsIsIdentity(): void
    {
        $model = new FoldModel(new Document("a\nb\nc\n"));

        // Calling snapVisible() directly (it must be public) with no folds at
        // all is a no-op: the line is already visible.
        $this->assertSame(3, $model->snapVisible(3));
    }

    public function testSnapVisibleEndBoundaryIsInclusive(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 10));
        $model = new FoldModel($doc);
        $model->setRegions([[0, 5]], $doc->version);
        $model->foldAll();

        // The fold's end line is still hidden/snapped (inclusive upper bound)...
        $this->assertSame(0, $model->snapVisible(5));
        // ...but the line right after it is already visible.
        $this->assertSame(6, $model->snapVisible(6));
    }

    public function testSnapVisibleGuardCapsPathologicalChainAtOneThousandPasses(): void
    {
        $count = 1001;
        $doc = new Document("l\n");
        $model = new FoldModel($doc);

        // A chain of adjacent single-step regions, supplied with the highest
        // start first so that (with `break` intact) each do/while pass can only
        // resolve exactly one level: unwinding the whole chain takes exactly
        // $count passes. The 1000-pass guard caps snapVisible one level short
        // of the true fixed point (0), landing on 1 instead.
        $regions = [];
        for ($j = $count - 1; $j >= 0; --$j) {
            $regions[] = [$j, $j + 1];
        }
        $model->setRegions($regions, $doc->version);
        $model->foldAll();

        $this->assertSame(1, $model->snapVisible($count));
    }

    /**
     * The region search keeps looking past regions that do not contain the
     * caret: a caret in the SECOND block folds that block even though an
     * earlier region was checked first and missed.
     */
    public function testToggleFindsTheCaretsRegionPastAnEarlierOneThatMisses(): void
    {
        $doc = new Document("a {\n}\nb {\n  x\n}\n");
        $model = new FoldModel($doc);
        $model->setRegions([[0, 1], [2, 4]], $doc->version);
        $doc->setCursor(3, 0);

        $model->toggle();

        $this->assertSame(4, $model->foldEndAt(2), 'the second block folded');
        $this->assertSame(2, $doc->cursorLine, 'and the caret moved to its visible start line');
    }

    public function testToggleCreatesAndRemovesFoldAtCaret(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 10));
        $model = new FoldModel($doc);
        $model->setRegions([[2, 5]], $doc->version);
        $doc->setCursor(2, 0);

        $model->toggle();
        $this->assertSame(5, $model->foldEndAt(2));

        // Toggling again at the (still visible) fold-start line must expand it.
        $model->toggle();
        $this->assertNull($model->foldEndAt(2));
    }

    public function testToggleWithNoEnclosingRegionIsNoOp(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 10));
        $model = new FoldModel($doc);
        $model->setRegions([], $doc->version);
        $doc->setCursor(3, 0);

        $model->toggle();

        $this->assertNull($model->foldEndAt(3));
    }

    public function testToggleStoresFoldKeyedByRegionStartAndRepositionsCaret(): void
    {
        // Start line is long (11 cols), the end line is short (1 col): the
        // caret's clamped column after toggling proves which line's length was
        // actually used.
        $doc = new Document("0123456789{\n".\str_repeat('a', times: 20)."\n}\n");
        $model = new FoldModel($doc);
        $model->setRegions([[0, 2]], $doc->version);
        $doc->setCursor(1, 20);

        $model->toggle();

        $this->assertSame(2, $model->foldEndAt(0));
        $this->assertSame(0, $doc->cursorLine);
        $this->assertSame(11, $doc->cursorCol);
    }

    public function testFoldableRegionBoundariesAreInclusiveOnBothEnds(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 10));

        $atStart = new FoldModel($doc);
        $atStart->setRegions([[3, 7]], $doc->version);
        $doc->setCursor(3, 0);
        $atStart->toggle();
        $this->assertSame(7, $atStart->foldEndAt(3), 'the region start line must be foldable');

        $atEnd = new FoldModel($doc);
        $atEnd->setRegions([[3, 7]], $doc->version);
        $doc->setCursor(7, 0);
        $atEnd->toggle();
        $this->assertSame(7, $atEnd->foldEndAt(3), 'the region end line must be foldable too');
    }

    public function testFoldableRegionRejectsLineOutsideRange(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 30));
        $model = new FoldModel($doc);
        $model->setRegions([[3, 7]], $doc->version);
        $doc->setCursor(20, 0);

        $model->toggle();

        $this->assertNull($model->foldEndAt(3));
    }

    public function testFoldableRegionPicksFirstFoundOnStartTie(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 15));
        $model = new FoldModel($doc);
        // Two candidates share the same start: the first one encountered wins.
        $model->setRegions([[0, 10], [0, 5]], $doc->version);
        $doc->setCursor(3, 0);

        $model->toggle();

        $this->assertSame(10, $model->foldEndAt(0));
    }

    public function testFoldableRegionPicksInnermostByHighestStart(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 15));
        $model = new FoldModel($doc);
        $model->setRegions([[0, 10], [2, 5]], $doc->version);
        $doc->setCursor(3, 0);

        $model->toggle();

        $this->assertSame(5, $model->foldEndAt(2), 'the innermost (higher-start) region must win');
        $this->assertNull($model->foldEndAt(0));
    }

    public function testFoldableRegionAcceptsFirstCandidateWithZeroStart(): void
    {
        $doc = new Document(\str_repeat("l\n", times: 10));
        $model = new FoldModel($doc);
        $model->setRegions([[0, 4]], $doc->version);
        $doc->setCursor(2, 0);

        $model->toggle();

        $this->assertSame(4, $model->foldEndAt(0));
    }

    public function testBraceScanIgnoresSameLineBracePairs(): void
    {
        $doc = new Document("{}\n");
        $model = new FoldModel($doc);
        $doc->setCursor(0, 0);

        $model->toggle();

        $this->assertNull($model->foldEndAt(0));
    }

    public function testBraceScanContinuesPastFirstBraceOnALine(): void
    {
        $doc = new Document("outer {\n  inner { x } }\n");
        $model = new FoldModel($doc);
        $doc->setCursor(0, 0);

        $model->toggle();

        $this->assertSame(1, $model->foldEndAt(0));
    }

    public function testFoldMarkSaysWhetherARegionStartsThereAndIfItIsCollapsed(): void
    {
        $doc = new Document("<?php\nfunction a()\n{\n    return 1;\n}\n");
        $model = new FoldModel($doc);

        $this->assertNull($model->foldMarkAt(1), 'the declaration line closes no brace of its own');
        $this->assertFalse($model->foldMarkAt(2), 'the brace line starts a region, and it is open');

        $doc->setCursor(2, 0);
        $model->toggle();
        $this->assertTrue($model->foldMarkAt(2), 'the same line, now collapsed');

        $model->toggle();
        $this->assertFalse($model->foldMarkAt(2), 'and open again');
    }

    /** @return \Generator<string, array{string, bool|null}> the expected foldMarkAt answer */
    public static function braceLines(): \Generator
    {
        yield 'an opening brace alone' => ['{', false];
        yield 'a trailing opening brace' => ['function a() {', false];
        // The naive scan records a region starting here too: the `}` ends the
        // previous one and the `{` opens the next.
        yield 'a closing and an opening brace' => ['} else {', false];
        yield 'a whole block on one line' => ['function a() { return 1; }', null];
        yield 'a closing brace alone' => ['}', null];
        yield 'no braces at all' => ['$a = 1;', null];
        yield 'an empty line' => ['', null];
    }

    #[DataProvider('braceLines')]
    public function testALineStartsARegionWhenItLeavesABraceOpen(string $text, ?bool $expected): void
    {
        $model = new FoldModel(new Document("<?php\n".$text."\n"));

        $this->assertSame($expected, $model->foldMarkAt(1));
    }

    /**
     * A language server's regions replace the brace guess entirely — it folds
     * from the DECLARATION, where the brace scan can only see the `{`.
     */
    public function testServerRegionsReplaceTheBraceGuessWhileTheyMatchTheDocument(): void
    {
        $doc = new Document("<?php\nfunction a()\n{\n    return 1;\n}\n");
        $model = new FoldModel($doc);
        $model->setRegions([[1, 4]], $doc->version);

        $this->assertFalse($model->foldMarkAt(1), 'the declaration is the start now');
        $this->assertNull($model->foldMarkAt(2), 'and the brace line is not');

        // An edit strands the server's regions, so the brace guess comes back.
        $doc->setCursor(0, 5);
        $doc->insert(' ');
        $this->assertNull($model->foldMarkAt(1));
        $this->assertFalse($model->foldMarkAt(2), 'the stale regions were dropped with their version');
    }
}
