<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Input;

use PHPUnit\Framework\TestCase;
use Porta\I18n\Translator;
use Porta\Input\GitMouseRouter;
use Porta\Input\HitTester;
use Porta\Input\MouseEvent;
use Porta\Input\SettingsMouseRouter;
use Porta\Input\ViewPointers;
use Porta\Input\WheelAxis;
use Porta\Input\WheelGesture;
use Porta\Layout\ViewRegions;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Theme\Styles;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\DiffViewWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;
use Porta\Widget\Settings\KeybindingsListWidget;
use Porta\Widget\Settings\SettingsFormWidget;
use Porta\Widget\Settings\SettingsNavWidget;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Style\StyleSheet;

/**
 * The stages a view has no use for still have to answer.
 *
 * The pointer seam is defined by STAGE, not by verb, which is what let three
 * views share it — but it means every view implements every stage, including
 * the ones it does nothing with. Those are the branches no screen test walks:
 * a view that never arms a drag, and an axis nothing in it scrolls along.
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
            new ViewPointers(),
            new ViewRegions(Styles::dark()),
        );
    }

    private function gitRouter(): GitMouseRouter
    {
        $translator = new Translator('en');

        return new GitMouseRouter(
            new HitTester(new Renderer(new StyleSheet())),
            TestTui::create(100, 24),
            new ChangesListWidget($translator),
            new DiffViewWidget($translator),
            new CommitBoxWidget($translator),
            new HistoryListWidget($translator),
            new GitTabsWidget($translator),
            new ViewPointers(),
            new ViewRegions(Styles::dark()),
        );
    }

    private function mouse(): MouseEvent
    {
        $event = MouseEvent::parse("\x1b[<0;10;5M");
        \assert($event instanceof MouseEvent);

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
        foreach ([$this->gitRouter(), $this->router()] as $router) {
            $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Vertical, 3)), $router::class);
            $this->assertFalse($router->press($this->mouse()), $router::class);
        }
    }

    public function testAHorizontalNotchIsRefusedBecauseNoSettingsPaneScrollsSideways(): void
    {
        // The axis arrives as data on the gesture rather than as a separate
        // verb, which is how git's two scroll methods became one. A view that
        // only scrolls vertically has to say so.
        $router = $this->router();

        $this->assertFalse($router->wheel(new WheelGesture($this->mouse(), WheelAxis::Horizontal, 3)));
    }
}
