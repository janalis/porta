<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Glyphs;
use Porta\Widget\ResultsPanelWidget;
use Porta\Widget\Support\ResultsPanelFields;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * The Problems panel's rule column, which is the one part of a results row
 * that must survive the squeeze.
 *
 * Split out of {@see ResultsPanelWidgetTest} rather than added to it: that
 * class is what pins mago's `too-many-methods` threshold, and a focused class
 * about one behaviour is the better shape anyway.
 *
 * @phpstan-import-type ResultRow from \Porta\Support\ResultRowShape
 */
final class ResultsPanelRuleColumnTest extends TestCase
{
    /** @param list<ResultRow> $rows */
    private function panelText(array $rows, int $columns, string $mode = 'problems'): string
    {
        $panel = new ResultsPanelWidget();
        $panel->open('Problems' === $mode ? 'Problems' : 'Find in Project', 'problems' === $mode ? ResultsPanelFields::resultsOnly() : ResultsPanelFields::searchResults());
        $panel->setResults($rows);

        return implode("\n", array_map(AnsiUtils::stripAnsiCodes(...), $panel->render(new RenderContext($columns, 6))));
    }

    /**
     * The rule that fired belongs at the END of a Problems row — after the
     * sentence you read the row for — which put it first in line to be cut.
     * At 100 columns, on exactly the long messages where a rule name is the
     * only thing telling two findings apart, it was simply gone.
     */
    public function testTheRuleThatFiredOutlivesTheSentenceItFollows(): void
    {
        $plain = $this->panelText([[
            'path' => 'src/Overlay/Presenter/WelcomeModalController.php',
            'line' => 117,
            'text' => '▲ Name "Other" is imported but this file never references it anywhere',
            'tail' => 'worse.unused_import',
        ]], 100);

        $this->assertStringContainsString('worse.unused_import', $plain, 'the rule survives');
        $this->assertStringContainsString(Glyphs::ELLIPSIS, $plain, 'and the sentence is what yielded, legibly');
    }

    /** A row that fits is untouched: the reservation only spends width it has to. */
    public function testAShortProblemRowIsLeftExactlyAsItWas(): void
    {
        $plain = $this->panelText([['path' => 'src/A.php', 'line' => 0, 'text' => '✗ boom', 'tail' => '1005']], 100);

        $this->assertStringContainsString('✗ boom  1005', $plain);
        $this->assertStringNotContainsString(Glyphs::ELLIPSIS, $plain);
    }

    /**
     * Too narrow to hold both and the sentence wins: a rule name with no
     * message beside it names nothing anyone could act on, so below the floor
     * the row goes back to the ordinary whole-row cut.
     */
    public function testOnAVeryNarrowPanelTheSentenceOutranksTheRule(): void
    {
        $plain = $this->panelText([[
            'path' => 'src/A.php',
            'line' => 0,
            'text' => '▲ Name "Other" is imported but never used here',
            'tail' => 'worse.unused_import',
        ]], 46);

        $this->assertStringContainsString('Name "Other"', $plain, 'the sentence keeps its opening');
        $this->assertStringNotContainsString('worse.unused_import', $plain, 'and the rule goes, as it always did');
    }

    /** Search results carry no tail, and their highlight spans must still line up with the string. */
    public function testASearchRowWithoutATailIsUntouched(): void
    {
        $plain = $this->panelText([['path' => 'src/A.php', 'line' => 0, 'text' => 'needle here', 'ranges' => [['start' => 0, 'length' => 6]]]], 100, mode: 'search');

        $this->assertStringContainsString('needle here', $plain);
    }
}
