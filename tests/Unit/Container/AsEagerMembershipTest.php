<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Editor\PreviewFeeder;
use Porta\Editor\StructureFollowsCaret;
use Porta\Input\TooltipRouter;
use Porta\Layout\ChromeHeight;
use Porta\Layout\PreviewSplit;
use Porta\Layout\ResponsiveLayout;
use Porta\Lsp\HighlightController;
use Porta\Overlay\Presenter\FindModalPresenter;
use Porta\Overlay\Presenter\FrameJoins;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\Presenter\ToastPresenter;
use Porta\Overlay\Presenter\TooltipPresenter;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Overlay\State\MenuOverlayController;
use Porta\Settings\SettingsViewController;
use Porta\Support\PerfCollector;

/**
 * The services EagerServices force-constructs at the ctor tail for their
 * self-registering side effect declare themselves with #[AsEager], which
 * autoconfiguration turns into a `porta.eager` tag carrying the priority.
 * A dropped attribute would silently stop that service constructing (it has
 * no inbound reference, so it would simply never exist), and a priority regression
 * would break the contractual find→picker→toast hand-over order — neither shows
 * up anywhere else. This test pins the tagged set AND its priority order so both
 * fail loudly here.
 */
final class AsEagerMembershipTest extends ContainerTestCase
{
    public function testEagerTagIsExactlyTheExpectedServicesInContractualOrder(): void
    {
        $container = $this->buildContainer();

        // findTaggedServiceIds returns registration order, so sort by the
        // #[AsEager] priority the autoconfig block wrote into the tag (higher
        // first — the order EagerServices constructs them in).
        $priorities = [];
        foreach ($container->findTaggedServiceIds('porta.eager') as $id => $tags) {
            $priorities[$id] = (int) $tags[0]['priority'];
        }
        \arsort($priorities);
        $actual = \array_keys($priorities);

        // The prioritised head, where the ORDER is the contract.
        $ordered = [
            ResponsiveLayout::class, // 600
            SettingsViewController::class, // 500
            WelcomeModalController::class, // 400
            FindModalPresenter::class, // 300 — find →
            PickerModalPresenter::class, // 200 — picker →
            ToastPresenter::class, // 100 — toast
            TooltipPresenter::class, // 50 — tooltip
        ];
        // And the priority-0 tail, where it is not: each of these exists only
        // to register itself with something, and nothing references them, so
        // without the tag they would never be constructed at all. Compared as a
        // SET, because their relative order is container discovery order and no
        // behaviour reads it — asserting it would pin an accident.
        //
        // Three left when a view took ownership of its pointer and its context
        // menus, and two left with git: a plugin class can never be a container
        // service, so what `plugin.php` builds by hand cannot carry a tag. The
        // tag is still the sanctioned way for a CORE service to exist for its
        // side effect, and every one that does is written down here.
        $unordered = [
            PerfCollector::class, // registers its frame hook at construction
            HighlightController::class, // watches the caret from the frame hook
            StructureFollowsCaret::class, // ditto: moves the Structure tab's selection
            ChromeHeight::class, // reads the terminal's rows from the frame hook
            FrameJoins::class, // installs the frame's join pass on BidiTui
            MenuOverlayController::class, // lays an open menu out again when a resize changes the screen
            TooltipRouter::class, // asks again at the pointer after a resize moved the elements
            PreviewFeeder::class, // hands a live preview the text once typing pauses
            PreviewSplit::class, // steps the preview aside while the split is too narrow for two sides
        ];

        $this->assertSame($ordered, \array_slice($actual, offset: 0, length: \count($ordered)), 'the contractual construction order');

        $tail = \array_slice($actual, offset: \count($ordered));
        \sort($tail);
        $expected = $unordered;
        \sort($expected);
        $this->assertSame($expected, $tail, 'every self-registering service must carry #[AsEager] or it is never built');
    }

    /**
     * The membership list above only fails when a service is ADDED without the
     * tag being written down. It cannot fail for the opposite mistake — writing
     * a self-registering service and never tagging it at all — because nothing
     * then names it anywhere.
     *
     * That mistake is real: `Lsp\HighlightController` shipped untagged, so its
     * per-frame caret watch was never installed and the feature was silently
     * dead with every gate green. This is the rule that catches the next one —
     * taking {@see FrameHooks} means the constructor registers a hook, and a
     * constructor nothing calls does not run.
     */
    public function testEveryServiceThatHooksTheFrameIsBuiltAtBoot(): void
    {
        $container = $this->buildContainer();
        $eager = \array_keys($container->findTaggedServiceIds('porta.eager'));

        $missing = [];
        foreach ($this->frameHookConsumers() as $class) {
            if (\in_array($class, $eager, strict: true)) {
                continue;
            }
            $missing[] = $class;
        }

        $this->assertSame([], $missing, 'a frame-hook consumer with no #[AsEager] is never constructed, so its hook never installs');
    }

    /**
     * Every class in src/ whose constructor takes a {@see FrameHooks}.
     *
     * @return list<class-string>
     */
    private function frameHookConsumers(): array
    {
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../src'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $code = (string) \file_get_contents($file->getPathname());
            if (1 !== \preg_match('/FrameHooks\s+\$/', $code) || 1 !== \preg_match('/^namespace\s+([^;]+);/m', $code, $ns)) {
                continue;
            }
            /** @var class-string $class */
            $class = $ns[1].'\\'.$file->getBasename('.php');
            $found[] = $class;
        }
        \sort($found);

        return $found;
    }
}
