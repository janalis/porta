<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Document;
use Porta\Tests\Support\Probe;
use Porta\Widget\CodeEditorWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * Soft wrap and code vision ON TOGETHER. Each is tested alone elsewhere; this
 * is the join, which is where the one real bug in them lived — the caret clamp
 * counted document lines and both features add rows to a line, so either alone
 * was survivable and both together put the caret off screen.
 *
 * A line's rows are its lenses THEN its wrapped segments, and every consumer —
 * the walk, the clamp, hit-testing — has to agree on that order.
 */
final class WrapAndLensesTogetherTest extends TestCase
{
    private const string DOWN = "\x1b[B";

    private CodeEditorWidget $editor;

    /** Two lenses above a 60-column line, then two short lines. */
    private function editor(int $rows = 6): CodeEditorWidget
    {
        $this->editor = new CodeEditorWidget();
        $this->editor->showDocument('/p/a.php', new Document("top\n".str_repeat('W', times: 60)."\nlast\n"));
        $this->editor->setFocused(true);
        $this->editor->display()->setSoftWrap(true);
        $lens = static fn (string $t): array => ['title' => $t, 'command' => '', 'arguments' => []];
        $this->editor->decorations()->setCodeLenses([1 => [$lens('3 usages'), $lens('2 implementations')]]);
        $this->editor->render(new RenderContext(30, $rows));

        return $this->editor;
    }

    /** @return list<string> */
    private function rows(int $rows = 6): array
    {
        return array_map(
            static fn (string $r): string => trim(AnsiUtils::stripAnsiCodes($r)),
            $this->editor->render(new RenderContext(30, $rows)),
        );
    }

    public function testALinesLensesComeFirstThenItsWrappedSegments(): void
    {
        $this->editor();

        $painted = $this->rows();

        $this->assertSame('1   top', $painted[0]);
        $this->assertSame('3 usages', $painted[1], 'both lenses before any of the line');
        $this->assertSame('2 implementations', $painted[2]);
        $this->assertStringContainsString('WWW', $painted[3], 'then the line itself');
        $this->assertStringContainsString('2', $painted[3], 'numbered on its first row only');
        $this->assertStringContainsString('WWW', $painted[4], 'and its continuation');
        $this->assertSame('', trim($painted[4], "W \t"), 'which carries no number');
    }

    /**
     * The caret walks the SEGMENTS and never stops on a lens row — vertical
     * motion is in document coordinates, so there is nothing to skip, and this
     * pins that it stays true with both features on.
     */
    public function testTheCaretWalksSegmentsPastTheLensesWithoutStoppingOnThem(): void
    {
        $editor = $this->editor();
        $editor->getDocument()->setCursor(0, 0);

        $editor->handleInput(self::DOWN);
        $this->assertSame([1, 0], $this->caret(), 'into the lensed line at its first segment');

        $editor->handleInput(self::DOWN);
        [$line, $column] = $this->caret();
        $this->assertSame(1, $line, 'still the same line');
        $this->assertGreaterThan(0, $column, 'a segment further along, not a lens row');
    }

    /** With both on, the caret still cannot be scrolled off the bottom. */
    public function testTheViewportFollowsTheCaretWithBothFeaturesOn(): void
    {
        $editor = $this->editor(rows: 5);
        $editor->getDocument()->setCursor(2, 0);
        $this->rows(5);

        $this->assertStringContainsString('last', implode("\n", $this->rows(5)), 'the caret line is on screen');
        $this->assertGreaterThan(0, Probe::get($editor, 'scroll')->vScroll() + Probe::get($editor, 'scroll')->vScrollSegment(), 'which took scrolling');
    }

    /** @return array{int, int} line, display column */
    private function caret(): array
    {
        $document = $this->editor->getDocument();

        return [$document->getCursorLine(), $document->getCursorDisplayCol()];
    }
}
