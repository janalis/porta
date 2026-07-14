<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Container;

use PHPUnit\Framework\TestCase;
use Porta\Container\AsWidget;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\FileTreeWidget;

final class AsWidgetTest extends TestCase
{
    public function testCarriesTheWidgetIdAndOptionalFlex(): void
    {
        $plain = new AsWidget('editor');
        $this->assertSame('editor', $plain->id);
        $this->assertNull($plain->flex);

        $flexed = new AsWidget('tree', flex: 0);
        $this->assertSame('tree', $flexed->id);
        $this->assertSame(0, $flexed->flex);
    }

    public function testWidgetClassesDeclareTheirIdsWithTheAttribute(): void
    {
        $tree = new \ReflectionClass(FileTreeWidget::class)->getAttributes(AsWidget::class);
        $this->assertCount(1, $tree);
        $this->assertSame('tree', $tree[0]->newInstance()->id);
        $this->assertSame(0, $tree[0]->newInstance()->flex, 'the tree keeps its intrinsic-width flex');

        $editor = new \ReflectionClass(CodeEditorWidget::class)->getAttributes(AsWidget::class);
        $this->assertCount(1, $editor);
        $this->assertSame('editor', $editor[0]->newInstance()->id);
    }
}
