<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Onboarding\SymfonyDetector;
use Porta\Tests\Support\TempProject;

final class SymfonyDetectorTest extends TestCase
{
    public function testAFrameworkBundleRequirementIsTheSignal(): void
    {
        $project = new TempProject(['composer.json' => (string) \json_encode(['require' => ['php' => '>=8.2', 'symfony/framework-bundle' => '^7.0']], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new SymfonyDetector($project->dir)->isSymfonyProject());
    }

    public function testADevRequirementCountsToo(): void
    {
        $project = new TempProject(['composer.json' => (string) \json_encode(['require-dev' => ['symfony/framework-bundle' => '^7.0']], \JSON_THROW_ON_ERROR)]);

        $this->assertTrue(new SymfonyDetector($project->dir)->isSymfonyProject());
    }

    public function testIndividualSymfonyComponentsDoNotCount(): void
    {
        // Half the ecosystem depends on symfony/console; that is not a Symfony app.
        $project = new TempProject(['composer.json' => (string) \json_encode(['require' => ['symfony/console' => '^7.0', 'symfony/yaml' => '^7.0']], \JSON_THROW_ON_ERROR)]);

        $this->assertFalse(new SymfonyDetector($project->dir)->isSymfonyProject());
    }

    public function testMissingOrBrokenComposerJsonIsNotSymfony(): void
    {
        // Held in variables: an inline `new TempProject(...)->dir` frees the
        // object (and removes the dir) before the detector ever reads it.
        $bare = new TempProject();
        $broken = new TempProject(['composer.json' => '{not json']);
        $malformed = new TempProject(['composer.json' => (string) \json_encode(['require' => 'not a map'], \JSON_THROW_ON_ERROR)]);

        $this->assertFalse(new SymfonyDetector($bare->dir)->isSymfonyProject(), 'no composer.json at all');
        $this->assertFalse(new SymfonyDetector($broken->dir)->isSymfonyProject(), 'a broken file degrades gracefully');
        $this->assertFalse(new SymfonyDetector($malformed->dir)->isSymfonyProject(), 'a malformed require section is ignored');
    }
}
