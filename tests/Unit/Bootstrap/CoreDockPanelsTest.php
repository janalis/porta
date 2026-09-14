<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Bootstrap;

use PHPUnit\Framework\TestCase;
use Porta\Bootstrap\CoreDockPanels;
use Porta\I18n\Translator;
use Porta\Widget\DebugPanelWidget;
use Porta\Workbench\Dock\DockPanels;
use Porta\Workbench\Dock\DockTab;

/**
 * The debug panel's strip entry — read as the registry hands it over, not off
 * the screen.
 *
 * An E2e asserts "Debug: prog.php" appears while a session runs, but that
 * string is painted TWICE: by this label and by the session tab inside the
 * panel. So it stays green with the retitle deleted, and the label reduced to
 * the bare word went unnoticed. Calling the closure is the only way to see
 * which of the two produced it.
 */
final class CoreDockPanelsTest extends TestCase
{
    public function testTheDebugStripLabelFollowsTheLaunchedFile(): void
    {
        $panels = new DockPanels();
        $panel = new DebugPanelWidget();
        new CoreDockPanels($panels, $panel, new Translator('en'))->register();

        $entries = $panels->panels;
        $this->assertCount(1, $entries, 'core registers exactly the one panel it ships');
        $this->assertSame(DockTab::Debug, $entries[0]['tab'], 'under its own mode, so core reaches it by enum');
        $this->assertSame('debug', $entries[0]['id']);

        $label = $entries[0]['label'];
        $this->assertSame('Debug', $label(), 'no session: the bare word, with no trailing colon');

        // The closure is read per frame precisely so this takes effect without
        // re-registering anything.
        $panel->open('a.php');
        $this->assertSame('Debug: a.php', $label(), 'a running session names its file on the strip');
    }
}
