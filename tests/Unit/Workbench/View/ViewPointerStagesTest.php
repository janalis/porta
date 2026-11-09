<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\View;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;
use Porta\Plugin\Store\StoreRow;
use Porta\Settings\SettingsMouseRouter;
use Porta\Tests\Support\Probe;
use Porta\Tests\Support\TestTui;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\PluginStoreWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Porta\Workbench\View\WheelAxis;
use Porta\Workbench\View\WheelGesture;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * The stages a view has no use for still have to answer.
 *
 * The pointer seam is defined by STAGE, not by verb, which is what let three
 * views share it — but it means every view implements every stage, including
 * the ones it does nothing with. Those are the branches no screen test walks:
 * a view that never arms a drag, and an axis nothing in it scrolls along.
 *
 * The settings view stands for all of them here. Git's router made the same
 * three assertions and moved to that plugin's own tests when it did, because a
 * core test may not name a plugin class.
 */
final class ViewPointerStagesTest extends TestCase
{
    private function router(): SettingsMouseRouter
    {
        $translator = new Translator('en');

        return new SettingsMouseRouter(
            new HitTester(new Renderer(new StyleSheet())),
            TestTui::create(100, 24),
            new SettingsNavWidget($translator),
            new SettingsFormWidget($translator),
            new KeybindingsListWidget($translator),
        );
    }

    private function mouse(): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;10;5M");
        $this->assertInstanceOf(MouseEvent::class, $event, 'the parser produced a mouse event');

        return $event;
    }

    public function testTheSettingsViewArmsNoDragButStillAnswersBothDragStages(): void
    {
        $router = $this->router();

        $this->assertFalse($router->continueDrag($this->mouse()), 'nothing in the settings view drags');
        $router->cancelDrag(); // and disarming what was never armed is not an error

        $this->addToAssertionCount(1);
    }

    public function testANotchOrPressThatMissesEveryPaneIsRefused(): void
    {
        // Nothing is laid out here, so every hit-test misses — which is the
        // state these two stages report by returning false. The caller reads
        // that to decide whether the workspace beneath should get the event,
        // so a stage that swallowed a miss would eat clicks over the chrome.
        $router = $this->router();

        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 3)));
        $this->assertFalse($router->press($this->mouse()));
    }

    public function testAHorizontalNotchIsRefusedBecauseNoSettingsPaneScrollsSideways(): void
    {
        // The axis arrives as data on the gesture rather than as a separate
        // verb, which is how git's two scroll methods became one. A view that
        // only scrolls vertically has to say so.
        $router = $this->router();

        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Horizontal, 3)));
    }

    /**
     * The two tests above never lay out a real pane, so they cannot tell a
     * correctly-refused horizontal notch from one that fell through to a
     * pane it should never have reached — both read as "false" either way.
     * These two need an actual hit.
     */
    public function testAVerticalNotchOnTheHitPaneScrollsItAndAHorizontalOneOnTheSamePaneIsStillRefused(): void
    {
        $translator = new Translator('en');
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $nav = new SettingsNavWidget($translator);
        $tui->add($nav); // the only widget attached: it fills the whole screen
        $tui->requestRender();
        $tui->processRender();

        $router = new SettingsMouseRouter(
            new HitTester($renderer),
            $tui,
            $nav,
            new SettingsFormWidget($translator), // never attached: HitTester::rect() is null, a guaranteed miss
            new KeybindingsListWidget($translator), // same
            new PluginStoreWidget($translator), // same — and wired in, the nav must still be reached
        );

        $this->assertTrue($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 3)), 'a vertical notch over the hit pane scrolls it');
        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Horizontal, 3)), 'no settings pane scrolls sideways, even one the pointer is actually over');
    }

    /**
     * $this->nav is checked first in the wheel() scan; a miss on it must
     * `continue` to the next pane, not abandon the whole scan. Nav here is
     * never attached (a guaranteed miss via a null rect), so only `continue`
     * reaches the keybindings list — a `break` regression would stop at nav
     * and never even try it.
     */
    public function testANotchMissingTheFirstPaneStillReachesALaterOne(): void
    {
        $translator = new Translator('en');
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $keys = new KeybindingsListWidget($translator);
        $tui->add($keys); // the only widget attached: it fills the whole screen

        $tui->requestRender();
        $tui->processRender();

        $router = new SettingsMouseRouter(
            new HitTester($renderer),
            $tui,
            new SettingsNavWidget($translator), // never attached: a guaranteed miss, checked before $keys
            new SettingsFormWidget($translator), // never attached: also a guaranteed miss
            $keys,
        );

        $this->assertTrue($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 3)), 'missing nav and form must not stop the scan before it reaches keys');
    }

    /** The store is a settings pane like the others: the wheel, a press and the pointer's tint reach it. */
    public function testTheStorePageTakesTheWheelAPressAndTheHover(): void
    {
        $translator = new Translator('en');
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $store = new PluginStoreWidget($translator);
        $crumbs = [];
        $store->onCrumb(static function (string $id) use (&$crumbs): void {
            $crumbs[] = $id;
        });
        $tui->add($store); // the only widget attached: it fills the whole screen
        $tui->requestRender();
        $tui->processRender();
        $router = new SettingsMouseRouter(new HitTester($renderer), $tui, new SettingsNavWidget($translator), new SettingsFormWidget($translator), new KeybindingsListWidget($translator), $store);

        $this->assertTrue($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 1)));
        $onTitle = MouseEvent::parse("\x1b[<0;3;1M");
        $this->assertInstanceOf(MouseEvent::class, $onTitle);
        $tui->processRender();
        Probe::set($tui, 'renderRequested', false);
        $router->hover($onTitle); // tints the Settings crumb
        $this->assertTrue(Probe::get($tui, 'renderRequested'), 'a crumb that took the tint repaints');
        Probe::set($tui, 'renderRequested', false);
        $router->hover($onTitle);
        $this->assertFalse(Probe::get($tui, 'renderRequested'), 'the same crumb again changes nothing, and repaints nothing');
        $this->assertTrue($router->press($onTitle));
        $this->assertSame(['general'], $crumbs);
        $router->hover($this->mouse());
        $this->assertSame($store, $tui->getFocus());
    }

    /**
     * Each of the four things a hover can change repaints on its own: a
     * store tile's tint, and a page title's crumb tint.
     */
    public function testAHoverThatTintsOnlyOneThingStillRepaints(): void
    {
        $translator = new Translator('en');
        $renderer = new Renderer(new StyleSheet());
        $tui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $store = new PluginStoreWidget($translator);
        $store->setStore([new StoreRow(new StoreEntry('a/porta-b', 'b', 'B', 'A', '', StoreCategory::Other, '1'), StoreAction::Get)], false, 0);
        $tui->add($store);
        $tui->requestRender();
        $tui->processRender();
        $router = new SettingsMouseRouter(new HitTester($renderer), $tui, new SettingsNavWidget($translator), new SettingsFormWidget($translator), new KeybindingsListWidget($translator), $store);
        $onRow = MouseEvent::parse("\x1b[<35;10;8M"); // the tile, under its shelf's heading
        $this->assertInstanceOf(MouseEvent::class, $onRow);
        Probe::set($tui, 'renderRequested', false);
        $router->hover($onRow);
        $this->assertTrue(Probe::get($tui, 'renderRequested'), 'a store tile took the tint');

        $form = new SettingsFormWidget($translator);
        $form->setRows([], [['label' => 'Settings', 'id' => 'general'], ['label' => 'General', 'id' => 'general']]);
        $formTui = new Tui(terminal: new VirtualTerminal(columns: 100, rows: 24), renderer: $renderer);
        $formTui->add($form);
        $formTui->requestRender();
        $formTui->processRender();
        $formRouter = new SettingsMouseRouter(new HitTester($renderer), $formTui, new SettingsNavWidget($translator), $form, new KeybindingsListWidget($translator));
        $onTitle = MouseEvent::parse("\x1b[<35;3;1M");
        $this->assertInstanceOf(MouseEvent::class, $onTitle);
        Probe::set($formTui, 'renderRequested', false);
        $formRouter->hover($onTitle);
        $this->assertTrue(Probe::get($formTui, 'renderRequested'), 'the form\'s crumb took the tint');
    }
}
