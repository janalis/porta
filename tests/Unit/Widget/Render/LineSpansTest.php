<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\Caret;
use Porta\Editor\Document;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Render\LineDecorator;
use Porta\Widget\Render\LineSpans;

final class LineSpansTest extends TestCase
{
    public function testLayeringOrderIsSelectionSearchOccurrenceBracketDiagnosticCaret(): void
    {
        $doc = new Document("hello world\n");
        $styles = new Styles(DarkTheme::definition());
        $result = LineSpans::forLine(
            $doc,
            0,
            ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 5],
            [new Caret(0, 3, 0, 3)],
            [['start' => 6, 'end' => 11, 'current' => true]],
            [[0, 0, 5]],
            [[0, 4]],
            [['severity' => 1, 'message' => 'boom', 'col' => 0, 'endCol' => 5]],
            $styles,
        );

        $opens = array_column($result['spans'], 2);
        $this->assertSame($styles->of(Role::SelectionBg)->getAnsiRestore(), $opens[0], 'selection first');
        $this->assertSame($styles->of(Role::SearchCurrentBg)->getAnsiRestore()."\x1b[1m", $opens[1], 'current search match next, bold for a non-color cue');
        $this->assertSame($styles->of(Role::OccurrenceBg)->getAnsiRestore(), $opens[2]);
        $this->assertSame(LineDecorator::BRACKET_SGR, $opens[3]);
        $this->assertSame("\x1b[7m", $opens[\count($opens) - 1], 'caret cell layers last');
        $this->assertSame(4, $result['padTo'], 'caret at col 3 needs 4 cells');
    }

    public function testPrimarySelectionAndEquivalentCaretRecordProduceIdenticalSpans(): void
    {
        $doc = new Document("alpha beta\ngamma delta\nepsilon\n");
        $styles = new Styles(DarkTheme::definition());

        foreach ([0, 1, 2] as $line) {
            $viaSelection = LineSpans::forLine(
                $doc,
                $line,
                ['startLine' => 0, 'startCol' => 2, 'endLine' => 2, 'endCol' => 4],
                [],
                [],
                [],
                null,
                [],
                $styles,
            );
            // The same range expressed as a (reversed) caret record, minus the
            // caret cell the multi-caret path adds on the caret's own line.
            $viaCaret = LineSpans::forLine(
                $doc,
                $line,
                null,
                [new Caret(0, 2, 2, 4)],
                [],
                [],
                null,
                [],
                $styles,
            );
            $caretSelectionSpans = array_values(array_filter($viaCaret['spans'], static fn (array $s) => "\x1b[7m" !== $s[2]));
            $this->assertSame($viaSelection['spans'], $caretSelectionSpans, "line {$line}: one range → one span shape");
        }
    }

    public function testCollapsedCaretDrawsOnlyItsCell(): void
    {
        $doc = new Document("ab\n");
        $result = LineSpans::forLine($doc, 0, null, [new Caret(0, 2, 0, 2)], [], [], null, [], new Styles(DarkTheme::definition()));

        $this->assertCount(1, $result['spans'], 'no selection span for a collapsed caret');
        $this->assertSame([2, 3, "\x1b[7m", "\x1b[27m", null], $result['spans'][0]);
        $this->assertSame(3, $result['padTo']);
    }

    public function testOffLineDecorationsProduceNoSpans(): void
    {
        $doc = new Document("one\ntwo\n");
        $result = LineSpans::forLine(
            $doc,
            1,
            ['startLine' => 0, 'startCol' => 0, 'endLine' => 0, 'endCol' => 2],
            [new Caret(0, 1, 0, 1)],
            [],
            [[0, 0, 3]],
            [[0, 1]],
            [],
            new Styles(DarkTheme::definition()),
        );

        $this->assertSame([], $result['spans']);
        $this->assertSame(0, $result['padTo']);
    }
}
