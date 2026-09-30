<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Widget\CodeEditorWidget;
use Porta\Workbench\AgentPresence;

/**
 * An {@see AgentPresence} for a hand-built provider or widget: over a fake
 * scheduler the test can fire, painting into a throwaway editor's store —
 * so a test with no agents in it costs one line.
 */
final class TestAgentPresence
{
    public static function create(FakeScheduler $scheduler = new FakeScheduler(), ?CodeEditorWidget $editor = null): AgentPresence
    {
        return new AgentPresence($scheduler, ($editor ?? new CodeEditorWidget())->decorations, TestTui::create());
    }
}
