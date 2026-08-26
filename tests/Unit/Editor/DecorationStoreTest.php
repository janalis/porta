<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\DecorationStore;

final class DecorationStoreTest extends TestCase
{
    public function testDiagnosticCountsFollowEveryDiagnosticsMutator(): void
    {
        // The counts are maintained at push time (the status bar polls them
        // every frame) — BOTH mutators must recount, or a buffer switch would
        // show the previous file's totals.
        $store = new DecorationStore(static function (): void {});
        $this->assertSame(['errors' => 0, 'warnings' => 0], $store->diagnosticCounts());

        $store->setDiagnostics([
            3 => [
                ['severity' => 1, 'message' => 'boom', 'col' => 0],
                ['severity' => 2, 'message' => 'meh', 'col' => 4],
            ],
        ]);
        $this->assertSame(['errors' => 1, 'warnings' => 1], $store->diagnosticCounts());

        $store->resetForBuffer([5 => [['severity' => 2, 'message' => 'other file', 'col' => 0]]]);
        $this->assertSame(['errors' => 0, 'warnings' => 1], $store->diagnosticCounts(), 'a buffer switch recounts');

        $store->setDiagnostics([]);
        $this->assertSame(['errors' => 0, 'warnings' => 0], $store->diagnosticCounts(), 'clearing recounts to zero');
    }
}
