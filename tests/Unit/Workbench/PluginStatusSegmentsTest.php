<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Workbench\PluginStatusSegments;
use Porta\Workbench\StatusSegmentSource;

/** The plugins' single slot in the status bar's prefix. */
final class PluginStatusSegmentsTest extends TestCase
{
    /** @param list<string> $segments */
    private function slot(array $segments): PluginStatusSegments
    {
        return new PluginStatusSegments(new class($segments) implements StatusSegmentSource {
            /** @param list<string> $segments */
            public function __construct(
                private readonly array $segments,
            ) {}

            public function statusSegments(): array
            {
                return $this->segments;
            }
        });
    }

    public function testSeveralPluginsShareTheOneSlot(): void
    {
        // One tagged contributor for all of them, because the tag is a compiled
        // container list a plugin can never be in.
        $this->assertSame('▶ build · 3 ahead', $this->slot(['▶ build', '3 ahead'])->statusSegment());
        $this->assertSame('▶ build', $this->slot(['▶ build'])->statusSegment());
    }

    public function testNothingToSayIsNullSoTheBarReservesNoSpace(): void
    {
        // Not '': the provider skips a null contributor entirely, and an empty
        // string would still cost the two-column separator after it.
        $this->assertNull($this->slot([])->statusSegment());
    }

    public function testEveryPluginSegmentPaintsInAnExistingRole(): void
    {
        // A plugin cannot choose a colour, and no theme gains an entry for
        // this — which is what keeps ThemeLockTest pinned.
        $this->assertSame(Role::Subtle, $this->slot([])->statusRole());
    }
}
