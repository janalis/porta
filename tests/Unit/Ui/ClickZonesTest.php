<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\ClickZones;

final class ClickZonesTest extends TestCase
{
    public function testSpansAreHalfOpenAndMissesReturnNull(): void
    {
        $zones = new ClickZones();
        $zones->add('regex', 10, 14);

        $this->assertNull($zones->at(9), 'before the span');
        $this->assertSame('regex', $zones->at(10), 'start is inclusive');
        $this->assertSame('regex', $zones->at(13));
        $this->assertNull($zones->at(14), 'end is exclusive');
        $this->assertNull($zones->at(50));
    }

    public function testFirstRecordedZoneWinsAnOverlap(): void
    {
        $zones = new ClickZones();
        $zones->add('first', 0, 10);
        $zones->add('second', 5, 15);

        $this->assertSame('first', $zones->at(7));
        $this->assertSame('second', $zones->at(12));
    }

    public function testClearDropsEverythingForTheNextRender(): void
    {
        $zones = new ClickZones();
        $zones->add('word', 0, 4);
        $zones->clear();

        $this->assertNull($zones->at(2));
    }

    public function testSpanIsTheNamedZonesColumnsForATooltipsAnchor(): void
    {
        $zones = new ClickZones();
        $zones->add('regex', 10, 14);
        $zones->add('word', 14, 18);
        $zones->add('word', 30, 34);

        $this->assertSame([14, 18], $zones->span('word'), 'the first zone of that name');
        $this->assertSame([10, 14], $zones->span('regex'));
        $this->assertNull($zones->span('case'));
    }
}
