<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Widget\DebugPanelWidget;
use Porta\Widget\TerminalPanelWidget;
use Porta\Workbench\Dock\DockPanels;
use Porta\Workbench\Dock\DockTab;

/**
 * The registry's whole job is the null.
 *
 * A core panel registers with its {@see DockTab}; a contributed one registers
 * with null and an id. Lookups match the enum case first, so a contributed
 * panel that shared a case would be reachable — and clobberable — by a core
 * caller asking for that case. A null can never equal an enum case, which is
 * the same reason {@see \Porta\Workbench\Dock\DockToolTab} carries one.
 */
final class DockPanelsTest extends TestCase
{
    public function testAContributedPanelIsUnreachableByMode(): void
    {
        $core = new DebugPanelWidget();
        $contributed = new TerminalPanelWidget(); // any DockPanelWidget will do

        $panels = new DockPanels();
        $panels->register($core, 'debug', static fn (): string => 'Debug', DockTab::Debug);
        $panels->register($contributed, 'acme:logs', static fn (): string => 'Logs');

        $this->assertSame($core, $panels->find(DockTab::Debug, ''), 'the core panel answers to its case');
        $this->assertSame($contributed, $panels->find(null, 'acme:logs'), 'the contributed one answers to its id');

        // The point: asking for a case can never reach a contributed panel,
        // whatever it was named, and asking for an unknown id reaches nothing.
        $this->assertNull($panels->find(DockTab::Terminal, 'acme:logs'), 'a case never falls through to an id');
        $this->assertNull($panels->find(null, 'acme:nope'));
        $this->assertNull($panels->find(null, ''), 'and no panel can carry the empty id — see below');
    }

    public function testAPanelWithNoIdIsRefusedAtRegistration(): void
    {
        $panels = new DockPanels();

        $this->expectException(\InvalidArgumentException::class);
        // Nothing could reveal or close it, so it is refused where it is given
        // rather than tolerated where it is looked up.
        $panels->register(new DebugPanelWidget(), '', static fn (): string => 'Nameless');
    }

    public function testTwoContributedPanelsAreToldApartById(): void
    {
        $first = new TerminalPanelWidget();
        $second = new DebugPanelWidget();

        $panels = new DockPanels();
        // Same label on purpose — two plugins may choose one title, so the id
        // is what identity rests on.
        $panels->register($first, 'a:logs', static fn (): string => 'Logs');
        $panels->register($second, 'b:logs', static fn (): string => 'Logs');

        $this->assertSame($first, $panels->find(null, 'a:logs'));
        $this->assertSame($second, $panels->find(null, 'b:logs'));
    }

    public function testTheLabelIsReadPerCallSoAPanelMayRetitleItself(): void
    {
        $panel = new DebugPanelWidget();
        $title = 'first';

        $panels = new DockPanels();
        $panels->register(
            $panel,
            'debug',
            static function () use (&$title): string {
                return $title;
            },
            DockTab::Debug,
        );

        $this->assertSame('first', $panels->panels[0]['label']());
        $title = 'second';
        $this->assertSame('second', $panels->panels[0]['label'](), 'the strip reads the label per frame');
    }
}
