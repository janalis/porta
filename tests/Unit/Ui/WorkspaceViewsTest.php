<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use Porta\Ui\WorkspaceViews;

final class WorkspaceViewsTest extends TestCase
{
    public function testAnswersFalseAndNoOpsUntilControllersAttach(): void
    {
        $views = new WorkspaceViews();

        $this->assertFalse($views->isAttached(), 'nothing attached at boot');
        $this->assertFalse($views->isGitOpen());
        $this->assertFalse($views->isSettingsOpen());
        $views->refreshSettings(); // a no-op, not an error

        $this->addToAssertionCount(1);
    }
}
