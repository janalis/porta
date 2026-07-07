<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Layout;

use Porta\I18n\Translator;
use PHPUnit\Framework\TestCase;
use Porta\Layout\GitSidebar;
use Porta\Widget\Git\ChangesListWidget;
use Porta\Widget\Git\CommitBoxWidget;
use Porta\Widget\Git\GitTabsWidget;
use Porta\Widget\Git\HistoryListWidget;

final class GitSidebarTest extends TestCase
{
    public function testFansTheWidthOutToAllFourWidgetsWithClamping(): void
    {
        $tabs = new GitTabsWidget(new Translator('en'));
        $changes = new ChangesListWidget(new Translator('en'));
        $commitBox = new CommitBoxWidget(new Translator('en'));
        $history = new HistoryListWidget(new Translator('en'));
        $sidebar = new GitSidebar($tabs, $changes, $commitBox, $history);

        $this->assertSame(44, $sidebar->width(), 'the default ctor width');

        $sidebar->setWidth(60);
        $this->assertSame(60, $sidebar->width());
        $this->assertSame(60, $tabs->getWidth());
        $this->assertSame(60, $changes->getWidth());
        $this->assertSame(60, $commitBox->getWidth());
        $this->assertSame(60, $history->getWidth());

        $sidebar->setWidth(1); // clamps to the widgets' floor
        $this->assertSame(24, $sidebar->width());
        $sidebar->setWidth(24); // same width again: a no-op
        $this->assertSame(24, $history->getWidth());
        $sidebar->setWidth(9_999);
        $this->assertSame(200, $sidebar->width(), 'clamped to the ceiling');
    }
}
