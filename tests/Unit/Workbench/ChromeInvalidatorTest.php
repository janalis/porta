<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\ChromeInvalidator;
use Symfony\Component\Tui\Render\ArrayLineBuffer;
use Symfony\Component\Tui\Widget\ContainerWidget;

final class ChromeInvalidatorTest extends TestCase
{
    public function testInvalidateAllClearsEveryRegisteredTreeRecursively(): void
    {
        $leaf = new StatusBarWidget();
        $container = new ContainerWidget();
        $container->add($leaf);
        $leaf->setRenderCache(new ArrayLineBuffer(['cached']), 10, 1);
        $container->setRenderCache(new ArrayLineBuffer(['cached']), 10, 1);

        $chrome = new ChromeInvalidator([$container]);
        $chrome->invalidateAll();

        $this->assertNull($container->getRenderCache(10, 1), 'the root cache is cleared');
        $this->assertNull($leaf->getRenderCache(10, 1), 'children are cleared recursively');
    }

    public function testInvalidateClearsASingleTree(): void
    {
        $widget = new StatusBarWidget();
        $widget->setRenderCache(new ArrayLineBuffer(['cached']), 10, 1);

        new ChromeInvalidator()->invalidate($widget);

        $this->assertNull($widget->getRenderCache(10, 1));
    }
}
