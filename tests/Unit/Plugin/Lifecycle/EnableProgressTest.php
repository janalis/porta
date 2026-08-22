<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Lifecycle;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Lifecycle\EnableProgress;

final class EnableProgressTest extends TestCase
{
    public function testDefaultsToNeitherActivatedNorReported(): void
    {
        $progress = new EnableProgress();

        $this->assertFalse($progress->activated);
        $this->assertFalse($progress->reported);
    }

    public function testWithActivationOrsInNotOverwrites(): void
    {
        $progress = new EnableProgress(activated: true);

        $progress = $progress->withActivation(false);

        $this->assertTrue($progress->activated, 'a prior activation must not be forgotten by a later false');
    }

    public function testWithActivationCanFlipFalseToTrue(): void
    {
        $progress = new EnableProgress();

        $progress = $progress->withActivation(true);

        $this->assertTrue($progress->activated);
        $this->assertFalse($progress->reported, 'unrelated to the reported flag');
    }

    public function testWithFailureSetsReportedWithoutTouchingActivated(): void
    {
        $progress = new EnableProgress(activated: true);

        $progress = $progress->withFailure();

        $this->assertTrue($progress->activated, 'withFailure must not clear a prior activation');
        $this->assertTrue($progress->reported);
    }
}
