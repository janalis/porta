<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutStore;
use Porta\Tests\Support\TempProject;

final class LayoutStoreTest extends TestCase
{
    public function testSaveAndLoadRoundTrip(): void
    {
        $project = new TempProject();
        $store = new LayoutStore($project->dir);

        $this->assertTrue($store->save(22, 35, 28, true, true));

        $loaded = $store->load();
        $this->assertSame(22, $loaded['sidebarWidthPercent']);
        $this->assertSame(35, $loaded['bottomPanelHeightPercent']);
        $this->assertSame(28, $loaded['gitSidebarWidthPercent']);
        $this->assertTrue($loaded['gitDiffSplit']);
        $this->assertTrue($loaded['gitDiffHideWhitespace']);
    }

    public function testLoadWhenNoFileReturnsNull(): void
    {
        $project = new TempProject();
        $this->assertNull(new LayoutStore($project->dir)->load());
    }

    public function testMissingKeysAndNonNumericBecomeNull(): void
    {
        $project = new TempProject();
        @mkdir($project->path('.porta'), 0777, true);
        file_put_contents($project->path('.porta/layout.json'), json_encode(['sidebarWidthPercent' => 'nope', 'gitDiffSplit' => 'yes']));

        $loaded = new LayoutStore($project->dir)->load();
        $this->assertNull($loaded['sidebarWidthPercent'], 'non-numeric value is ignored');
        $this->assertNull($loaded['gitDiffSplit'], 'a non-bool toggle is ignored');
        $this->assertNull($loaded['bottomPanelHeightPercent'], 'absent key is null');
        $this->assertNull($loaded['gitSidebarWidthPercent'], 'absent git key is null');
        $this->assertNull($loaded['gitDiffHideWhitespace'], 'absent bool key is null');
    }

    public function testHandEditedNumericStringIsAccepted(): void
    {
        $project = new TempProject();
        @mkdir($project->path('.porta'), 0777, true);
        file_put_contents($project->path('.porta/layout.json'), json_encode(['sidebarWidthPercent' => '18']));

        $this->assertSame(18, new LayoutStore($project->dir)->load()['sidebarWidthPercent']);
    }

    public function testSaveOmitsNullAxes(): void
    {
        $project = new TempProject();
        $store = new LayoutStore($project->dir);
        $store->save(20, null);

        $raw = (string) file_get_contents($project->path('.porta/layout.json'));
        $this->assertStringContainsString('sidebarWidthPercent', $raw);
        $this->assertStringNotContainsString('bottomPanelHeightPercent', $raw);
        $this->assertStringNotContainsString('gitSidebarWidthPercent', $raw);
        $this->assertStringNotContainsString('gitDiffSplit', $raw, 'false/omitted toggles leave no key');
        $this->assertSame(20, $store->load()['sidebarWidthPercent']);
        $this->assertNull($store->load()['bottomPanelHeightPercent']);
    }

    public function testSaveFailsWhenDirCannotBeCreated(): void
    {
        $project = new TempProject();
        file_put_contents($project->path('.porta'), 'not a dir'); // block mkdir of .porta/

        $this->assertFalse(new LayoutStore($project->dir)->save(20, 30));
    }
}
