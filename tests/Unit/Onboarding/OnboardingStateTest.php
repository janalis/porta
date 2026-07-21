<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Onboarding;

use PHPUnit\Framework\TestCase;
use Porta\Onboarding\OnboardingState;
use Porta\Tests\Support\TempProject;

/**
 * The first-run marker: a positive record, not an inference from absence.
 */
final class OnboardingStateTest extends TestCase
{
    public function testAFreshProjectHasNotSeenTheWelcomeScreen(): void
    {
        $project = new TempProject();

        $this->assertFalse(new OnboardingState($project->dir)->hasSeenWelcome());
    }

    public function testMarkingItSeenPersistsAcrossInstances(): void
    {
        $project = new TempProject();

        $this->assertTrue(new OnboardingState($project->dir)->markWelcomeSeen());
        $this->assertTrue(new OnboardingState($project->dir)->hasSeenWelcome());
    }

    /** A malformed or unrelated marker file reads as "not seen", never as an error. */
    public function testAMarkerWithoutTheFlagReadsAsUnseen(): void
    {
        $project = new TempProject(['.porta/onboarding.json' => '{"somethingElse":true}']);

        $this->assertFalse(new OnboardingState($project->dir)->hasSeenWelcome());
    }

    public function testInvalidJsonReadsAsUnseen(): void
    {
        $project = new TempProject(['.porta/onboarding.json' => '{not json']);

        $this->assertFalse(new OnboardingState($project->dir)->hasSeenWelcome());
    }

    /**
     * An unwritable project must not break the boot: the marker is lost, so the
     * screen shows again next launch, which is the acceptable outcome.
     */
    public function testAnUnwritableProjectReportsFailureInsteadOfThrowing(): void
    {
        // A file where the `.porta` directory should be: dumpFile cannot create
        // the parent, so the write fails.
        $project = new TempProject(['.porta' => 'not a directory']);

        $this->assertFalse(new OnboardingState($project->dir)->markWelcomeSeen());
        $this->assertFalse(new OnboardingState($project->dir)->hasSeenWelcome());
    }
}
