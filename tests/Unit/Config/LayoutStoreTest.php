<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\LayoutPrefs;
use Porta\Config\LayoutStore;
use Porta\Tests\Support\TempProject;

final class LayoutStoreTest extends TestCase
{
    public function testSaveAndLoadRoundTrip(): void
    {
        $project = new TempProject();
        $store = new LayoutStore($project->dir);

        $this->assertTrue($store->save(new LayoutPrefs(
            sidebarWidthPercent: 22,
            bottomPanelHeightPercent: 35,
            viewSidebarWidthPercent: ['git' => 28, 'settings' => 19],
            previewSplitPercent: 62,
        )));

        $loaded = $store->load();
        $this->assertInstanceOf(LayoutPrefs::class, $loaded);
        $this->assertSame(22, $loaded->sidebarWidthPercent);
        $this->assertSame(35, $loaded->bottomPanelHeightPercent);
        $this->assertSame(['git' => 28, 'settings' => 19], $loaded->viewSidebarWidthPercent);
        $this->assertSame(62, $loaded->previewSplitPercent);
    }

    public function testLoadWhenNoFileReturnsNull(): void
    {
        $project = new TempProject();
        $this->assertNull(new LayoutStore($project->dir)->load());
    }

    public function testMissingKeysAndNonNumericBecomeNull(): void
    {
        $project = new TempProject();
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents($project->path('.porta/layout.json'), \json_encode(['sidebarWidthPercent' => 'nope'], \JSON_THROW_ON_ERROR));

        $loaded = new LayoutStore($project->dir)->load();
        $this->assertInstanceOf(LayoutPrefs::class, $loaded);
        $this->assertNull($loaded->sidebarWidthPercent, 'non-numeric value is ignored');
        $this->assertNull($loaded->bottomPanelHeightPercent, 'absent key is null');
        $this->assertSame([], $loaded->viewSidebarWidthPercent, 'no view has been resized');
        $this->assertNull($loaded->previewSplitPercent, 'no preview split has been dragged');
    }

    public function testHandEditedNumericStringIsAccepted(): void
    {
        $project = new TempProject();
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents($project->path('.porta/layout.json'), \json_encode(['sidebarWidthPercent' => '18'], \JSON_THROW_ON_ERROR));

        $this->assertSame(18, new LayoutStore($project->dir)->load()?->sidebarWidthPercent);
    }

    public function testSaveOmitsNullAxes(): void
    {
        $project = new TempProject();
        $store = new LayoutStore($project->dir);
        $store->save(new LayoutPrefs(sidebarWidthPercent: 20));

        $raw = (string) \file_get_contents($project->path('.porta/layout.json'));
        $this->assertStringContainsString('sidebarWidthPercent', $raw);
        $this->assertStringNotContainsString('bottomPanelHeightPercent', $raw);
        $this->assertStringNotContainsString('viewSidebarWidthPercent', $raw, 'no view resized, no key');
        $this->assertSame(20, $store->load()?->sidebarWidthPercent);
        $this->assertNull($store->load()?->bottomPanelHeightPercent);
    }

    public function testSaveFailsWhenDirCannotBeCreated(): void
    {
        $project = new TempProject();
        \file_put_contents($project->path('.porta'), data: 'not a dir'); // block mkdir of .porta/

        $this->assertFalse(new LayoutStore($project->dir)->save(new LayoutPrefs(sidebarWidthPercent: 20, bottomPanelHeightPercent: 30)));
    }

    public function testAFileWrittenBeforeViewsWereContributedIsStillRead(): void
    {
        // The two named keys this map replaced. They were core's while core
        // knew both views; a contributed view has an id core learns at
        // runtime, so a field per view cannot describe one. Adopted rather than
        // migrated in place, because this file is hand-editable — the old
        // spelling keeps working until the next drag rewrites the map.
        $project = new TempProject();
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents(
            $project->path('.porta/layout.json'),
            \json_encode(
                [
                    'gitSidebarWidthPercent' => 28,
                    'settingsSidebarWidthPercent' => 19,
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        $this->assertSame(['git' => 28, 'settings' => 19], new LayoutStore($project->dir)->load()?->viewSidebarWidthPercent);
    }

    public function testTheMapWinsOverTheKeyItReplaced(): void
    {
        // A file mid-migration holds both: the old key from before the move and
        // the map from the first drag after it. The map is the one the user's
        // last drag wrote.
        $project = new TempProject();
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents(
            $project->path('.porta/layout.json'),
            \json_encode(
                [
                    'gitSidebarWidthPercent' => 28,
                    'viewSidebarWidthPercent' => ['git' => 44, 'lsp-explorer' => 12],
                ],
                \JSON_THROW_ON_ERROR,
            ),
        );

        $this->assertSame(
            ['git' => 44, 'lsp-explorer' => 12],
            new LayoutStore($project->dir)->load()?->viewSidebarWidthPercent,
            'and a view core has never heard of is read like any other',
        );
    }

    public function testANumericViewIdIsExcludedAndAnInvalidPercentIsExcluded(): void
    {
        // Real view ids are always non-numeric strings; json_decode turns a
        // purely-numeric object key into an int array key, which must not
        // leak into the width map. Likewise a non-numeric percent must not
        // leak in under a valid string id.
        $project = new TempProject();
        if (!\is_dir($project->path('.porta'))) {
            \mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        \file_put_contents(
            $project->path('.porta/layout.json'),
            \json_encode(['viewSidebarWidthPercent' => ['5' => 50, 'git' => 'not-a-number']], \JSON_THROW_ON_ERROR),
        );

        $this->assertSame([], new LayoutStore($project->dir)->load()?->viewSidebarWidthPercent);
    }

    public function testAnUnencodablePayloadDegradesToFalseInsteadOfThrowing(): void
    {
        // An invalid-UTF-8 view id makes json_encode(\JSON_THROW_ON_ERROR)
        // throw; the widened catch degrades that to the IO-error false.
        $project = new TempProject();

        $saved = new LayoutStore($project->dir)->save(new LayoutPrefs(viewSidebarWidthPercent: ["v\xB1" => 40]));

        $this->assertFalse($saved);
        $this->assertFalse(\is_file($project->path('.porta/layout.json')), 'nothing half-written');
    }
}
