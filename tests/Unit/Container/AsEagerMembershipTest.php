<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use Porta\Git\GitViewController;
use Porta\Input\GitMouseRouter;
use Porta\Input\SettingsMouseRouter;
use Porta\Layout\ResponsiveLayout;
use Porta\Overlay\Presenter\FindModalPresenter;
use Porta\Overlay\Presenter\PickerModalPresenter;
use Porta\Overlay\Presenter\ToastPresenter;
use Porta\Overlay\Presenter\WelcomeModalController;
use Porta\Settings\SettingsViewController;

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
        arsort($priorities);
        $actual = array_keys($priorities);

        $expected = [
            GitViewController::class, // 700
            ResponsiveLayout::class, // 600
            SettingsViewController::class, // 500
            WelcomeModalController::class, // 400
            FindModalPresenter::class, // 300 — find →
            PickerModalPresenter::class, // 200 — picker →
            ToastPresenter::class, // 100 — toast
            // The two view pointer routers, priority 0: nothing references them
            // any more (the mouse, hover and wheel routers look their pointer up
            // by view id instead of holding it), so without the tag they would
            // never be constructed and never register. Order between them does
            // not matter — each answers for its own view id.
            GitMouseRouter::class,
            SettingsMouseRouter::class,
        ];

        self::assertSame($expected, $actual, 'every eager service must carry #[AsEager] with its contractual priority');
    }
}
