<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Api;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Api\LegacyCoreSettings;
use Porta\Tests\Support\TempProject;

/**
 * The generic half of the git LegacySettings precedent: the per-key adoption
 * semantics every core-to-plugin settings move shares. The maps are each
 * plugin's own; what is pinned here is what "adopt" means.
 */
final class LegacyCoreSettingsTest extends TestCase
{
    /** @param array<string, mixed> $porta */
    private function legacy(array $porta): LegacyCoreSettings
    {
        $project = new TempProject(['.porta.json' => json_encode($porta, JSON_THROW_ON_ERROR)]);
        $legacy = new LegacyCoreSettings($project->dir, 'demo', ['toggle' => 'coreToggle', 'path' => 'corePath']);
        // The decode happens in the constructor, so the TempProject may go.
        unset($project);

        return $legacy;
    }

    private function defaults(): \Closure
    {
        return static fn (string $key): bool|int|string => 'toggle' === $key ? true : '';
    }

    public function testAPreMoveValueIsAdoptedForAKeyNobodyHasWritten(): void
    {
        $legacy = $this->legacy(['settings' => ['coreToggle' => false]]);

        $seed = $legacy->seed($this->defaults());
        $this->assertFalse($seed['toggle'], 'the old value carried over');
        $this->assertSame('', $seed['path'], 'an untouched key keeps its current default');
        $this->assertSame(['toggle' => false], $legacy->adoptions(), 'only what was adopted is written through');
    }

    public function testTheNewKeyWinsWhereBothExist(): void
    {
        // A file mid-migration: adopting the old key here would undo whatever
        // the user did most recently under the new one.
        $legacy = $this->legacy([
            'settings' => ['coreToggle' => false],
            'plugins' => ['demo' => ['toggle' => true]],
        ]);

        $this->assertTrue($legacy->seed($this->defaults())['toggle']);
        $this->assertSame([], $legacy->adoptions(), 'nothing to write through');
    }

    public function testAKeyExplicitlySetToTheDefaultIsStillAdopted(): void
    {
        // An absent key and a key set to its default are the same VALUE; only
        // the file distinguishes them, which is why this reads the file.
        $this->assertSame(['toggle' => true], $this->legacy(['settings' => ['coreToggle' => true]])->adoptions());
    }

    public function testNoFileAndMalformedAndTopLevelValuesAdoptNothing(): void
    {
        $this->assertSame([], $this->legacy([])->adoptions(), 'a fresh project has nothing to adopt');
        $this->assertSame([], $this->legacy(['settings' => ['coreToggle' => ['yes']]])->adoptions(), 'a hand-edited array is ignored, not coerced');
        $this->assertSame([], $this->legacy(['coreToggle' => false])->adoptions(), 'the top level is not where the pre-move writer wrote');
        $this->assertTrue($this->legacy([])->seed($this->defaults())['toggle'], 'the registered default stands');
    }

    public function testAnotherPluginsNewKeyDoesNotBlockAdoption(): void
    {
        // The written-under-the-new-name check is scoped to THIS plugin's
        // block — a namesake key in someone else's says nothing about ours.
        $legacy = $this->legacy([
            'settings' => ['coreToggle' => false],
            'plugins' => ['other' => ['toggle' => true]],
        ]);

        $this->assertSame(['toggle' => false], $legacy->adoptions());
    }
}
