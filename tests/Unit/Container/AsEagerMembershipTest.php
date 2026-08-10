<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

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

        // The prioritised head, where the ORDER is the contract.
        $ordered = [
            ResponsiveLayout::class, // 600
            SettingsViewController::class, // 500
            WelcomeModalController::class, // 400
            FindModalPresenter::class, // 300 — find →
            PickerModalPresenter::class, // 200 — picker →
            ToastPresenter::class, // 100 — toast
        ];
        // And the priority-0 tail, where it is not: each of these exists only
        // to register itself with something, and nothing references them, so
        // without the tag they would never be constructed at all. Compared as a
        // SET, because their relative order is container discovery order and no
        // behaviour reads it — asserting it would pin an accident.
        //
        // Empty, and that is the finding rather than an omission. Three left
        // when a view took ownership of its pointer and its context menus, and
        // the last two left with git: a plugin class can never be a container
        // service, so what `plugin.php` builds by hand cannot carry a tag. The
        // list stays because the tag is still the sanctioned way for a CORE
        // service to exist for its side effect, and the day one does it must
        // be written down here.
        $unordered = [];

        self::assertSame($ordered, \array_slice($actual, offset: 0, length: \count($ordered)), 'the contractual construction order');

        $tail = \array_slice($actual, offset: \count($ordered));
        sort($tail);
        $expected = $unordered;
        sort($expected);
        self::assertSame($expected, $tail, 'every self-registering service must carry #[AsEager] or it is never built');
    }
}
