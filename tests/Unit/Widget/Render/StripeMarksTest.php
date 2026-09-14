<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Render;

use PHPUnit\Framework\TestCase;
use Porta\Editor\ChangeMarkKind;
use Porta\Editor\DecorationStore;
use Porta\Editor\DiagnosticSeverity;
use Porta\Widget\Render\StripeMarks;

final class StripeMarksTest extends TestCase
{
    private function store(): DecorationStore
    {
        return new DecorationStore(static function (): void {});
    }

    public function testItFlattensDiagnosticsToTheWorstSeverityPerLine(): void
    {
        $store = $this->store();
        $store->setDiagnostics([
            4 => [
                ['severity' => DiagnosticSeverity::Information, 'message' => 'info', 'col' => 0],
                ['severity' => DiagnosticSeverity::Error, 'message' => 'err', 'col' => 0],
            ],
            9 => [['severity' => DiagnosticSeverity::Warning, 'message' => 'warn', 'col' => 0]],
        ]);

        $marks = StripeMarks::from($store, vcsGutter: true);

        $this->assertSame([4 => DiagnosticSeverity::Error, 9 => DiagnosticSeverity::Warning], $marks->severityByLine);
    }

    /** An empty list is not a diagnostic — it must not put a mark on the stripe. */
    public function testALineWithAnEmptyDiagnosticListCarriesNoSeverity(): void
    {
        $store = $this->store();
        $store->setDiagnostics([4 => [], 9 => [['severity' => DiagnosticSeverity::Error, 'message' => 'e', 'col' => 0]]]);

        $marks = StripeMarks::from($store, vcsGutter: true);

        $this->assertSame([9 => DiagnosticSeverity::Error], $marks->severityByLine);
    }

    /** Likewise a line whose match list emptied out: the line keeps its key, but it is no longer a hit. */
    public function testALineWithNoRemainingMatchesIsNotASearchHit(): void
    {
        $store = $this->store();
        $store->setSearchMatches([
            2 => [],
            6 => [['start' => 0, 'end' => 3, 'current' => true]],
        ]);

        $marks = StripeMarks::from($store, vcsGutter: true);

        $this->assertSame([6], $marks->searchLines);
    }

    public function testItCarriesEveryBreakpointThroughWhateverItsFlavour(): void
    {
        $store = $this->store();
        $store->setBreakpointLines([3, 8], [8]);

        $marks = StripeMarks::from($store, vcsGutter: true);

        $this->assertSame([3, 8], $marks->breakpointLines, 'a conditional breakpoint is still a breakpoint on the stripe');
    }

    /**
     * The stripe's change marks follow the gutter's own version-control toggle,
     * so the two can never disagree about whether this buffer shows VCS.
     */
    public function testChangeMarksFollowTheGutterToggle(): void
    {
        $store = $this->store();
        $store->setVcsMarks([1 => ChangeMarkKind::Added]);

        $this->assertSame([1 => ChangeMarkKind::Added], StripeMarks::from($store, vcsGutter: true)->vcsByLine);
        $this->assertSame([], StripeMarks::from($store, vcsGutter: false)->vcsByLine, 'the gutter strip is off, so the stripe drops them too');
    }
}
