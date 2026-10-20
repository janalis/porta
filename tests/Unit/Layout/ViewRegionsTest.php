<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use PHPUnit\Framework\TestCase;
use Porta\Layout\NarrowSplit;
use Porta\Layout\ViewRegions;
use Porta\Ui\Theme\Styles;
use Symfony\Component\Tui\Style\Direction;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * The runtime replacement for six compiled `porta.widget.*` slot services.
 *
 * What matters here is that a region can be minted by a caller core did not
 * know about at build time, and that the two callers who need it — the view
 * that fills it and the layout that arranges it — get the SAME one whichever
 * asks first.
 */
final class ViewRegionsTest extends TestCase
{
    private function regions(): ViewRegions
    {
        return new ViewRegions(Styles::dark());
    }

    public function testMintingTwiceYieldsTheSameRegion(): void
    {
        // The view mints in its constructor and the layout mints when it
        // arranges; neither can know which ran first. A second region would be
        // a second set of containers, and the one on screen would be whichever
        // got mounted — a bug with no error.
        $regions = $this->regions();

        $first = $regions->mint('git');
        $second = $regions->mint('git');

        $this->assertSame($first, $second);
        $this->assertSame($first->sidebar(), $second->sidebar(), 'and the same containers, not just the same wrapper');
        $this->assertCount(1, $regions->all());
    }

    public function testASplitIsMintedOncePerKeyAndItsDividerJoinsTheRegions(): void
    {
        $regions = $this->regions();
        $region = $regions->mint('git');

        $split = $region->split('history', NarrowSplit::Stacked, share: 400, minSide: 28);

        $this->assertSame($split, $region->split('history'), 'idempotent by key');
        $this->assertNotSame($split, $region->split('other'));
        $this->assertSame(['history' => $split, 'other' => $region->split('other')], $region->splits());
        $this->assertSame(40, $split->percent());
        $this->assertSame('git-history', $split->area->getId());
        $this->assertSame([$region->separator(), $split->separator, $region->split('other')->separator], $region->separators());
    }

    public function testAForgottenIdMintsAFreshRegion(): void
    {
        // The un-mint half of the live view lifecycle: a reused region's
        // containers would still hold the retracted view's children, so a
        // re-enable must get FRESH widgets under the same id.
        $regions = $this->regions();
        $first = $regions->mint('git');

        $regions->forget('git');

        $this->assertNull($regions->find('git'), 'gone without a minting side effect');
        $this->assertSame([], $regions->all());
        $second = $regions->mint('git');
        $this->assertNotSame($first, $second);
        $this->assertNotSame($first->sidebar(), $second->sidebar(), 'fresh containers, not the dead view\'s');
    }

    public function testARegionCarriesTheFourWidgetsAViewNeedsUnderItsOwnIds(): void
    {
        $region = $this->regions()->mint('git');

        $this->assertSame('git', $region->id);
        $this->assertSame('git-area', $region->area()->getId());
        $this->assertSame('git-left', $region->sidebar()->getId());
        $this->assertSame('git-separator', $region->separator()->getId());
        $this->assertSame('git-content', $region->content()->getId());
        $this->assertSame([], $region->area()->all(), 'the area is filled by the RTL-aware pane ordering, not here');
    }

    public function testPanesAreTheCanonicalLtrOrder(): void
    {
        $region = $this->regions()->mint('settings');

        $this->assertSame(
            ['settings-left', 'settings-separator', 'settings-content'],
            \array_map(static fn (AbstractWidget $w): string => $w->getId(), $region->panes()),
        );
    }

    public function testEveryMintedRegionIsListedInMintOrder(): void
    {
        // What PaneLayout iterates. A region absent from this list is a view
        // whose panes never get ordered — it would render in whatever order the
        // area happened to be filled, and RTL would stop mirroring it.
        $regions = $this->regions();
        $git = $regions->mint('git');
        $settings = $regions->mint('settings');
        $contributed = $regions->mint('acme');

        $this->assertSame([$git, $settings, $contributed], $regions->all());
    }

    public function testIndependentIdsMintIndependentContainers(): void
    {
        $regions = $this->regions();

        $this->assertNotSame($regions->mint('git')->sidebar(), $regions->mint('settings')->sidebar());
    }

    public function testTheMintedContainersCarryTheirExactStyle(): void
    {
        $region = $this->regions()->mint('git');

        $this->assertSame(Direction::Horizontal, $region->area()->getStyle()?->getDirection());
        $this->assertSame(0, $region->area()->getStyle()?->getGap());
        $this->assertSame(0, $region->sidebar()->getStyle()?->getFlex(), 'the sidebar is a fixed pane, not a flex-1 share');
        $this->assertSame(1, $region->content()->getStyle()?->getFlex(), 'the content fills whatever the sidebar leaves');
    }
}
