<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Behat;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Behat\Catalog\Coverage;

/**
 * The catalog gate: every action, command and setting is driven by a feature,
 * or exempted with a reason.
 */
final class CoverageTest extends TestCase
{
    private const array CATALOG = ['action' => ['save', 'undo'], 'command' => ['plugins_store'], 'setting' => ['theme']];

    public function testTagsAndUsedActionsCoverTheCatalog(): void
    {
        $coverage = new Coverage(
            self::CATALOG,
            [
                'a.feature' => "@setting:theme\nFeature: A\n\n  @action:save\n  Scenario: s\n    When I use the action \"undo\"\n    And I use the action \"plugins_store\"\n",
            ],
            [],
        );

        $this->assertTrue($coverage->passes());
        $this->assertSame([], $coverage->missing);
    }

    public function testWhatNoFeatureDrivesIsMissing(): void
    {
        $coverage = new Coverage(self::CATALOG, ['a.feature' => "Feature: A\n  @action:save\n  Scenario: s\n"], []);

        $this->assertFalse($coverage->passes());
        $this->assertSame(['action:undo', 'command:plugins_store', 'setting:theme'], $coverage->missing);
    }

    public function testATagOrAnActionTheCatalogDoesNotHaveIsUnknown(): void
    {
        $coverage = new Coverage(
            self::CATALOG,
            [
                'a.feature' => "@action:save @action:undo @command:plugins_store @setting:theme @action:typo\nFeature: A\n    When I use the action \"nope\"\n",
            ],
            [],
        );

        $this->assertFalse($coverage->passes());
        $this->assertSame(['a.feature: action:typo', 'a.feature: use:nope'], $coverage->unknown);
        $this->assertSame([], $coverage->missing);
    }

    public function testATagNeedsItsKind(): void
    {
        // A command is not covered by an action tag of the same id: the kinds are what the gate counts
        $coverage = new Coverage(self::CATALOG, ['a.feature' => '@action:save @action:undo @setting:theme @action:plugins_store'], []);

        $this->assertSame(['command:plugins_store'], $coverage->missing);
        $this->assertSame(['a.feature: action:plugins_store'], $coverage->unknown);
    }

    public function testAnExemptionWithAReasonStandsInForAFeature(): void
    {
        $coverage = new Coverage(self::CATALOG, ['a.feature' => '@action:save @action:undo @setting:theme'], ['command:plugins_store' => 'needs the network']);

        $this->assertTrue($coverage->passes());
    }

    public function testAnExemptionThatIsCoveredUnknownOrUnreasonedIsStale(): void
    {
        $coverage = new Coverage(
            self::CATALOG,
            ['a.feature' => '@action:save @action:undo @command:plugins_store'],
            [
                'action:save' => 'covered after all',
                'action:gone' => 'no longer in the catalog',
                // Neither covered nor gone: only its blank reason makes it stale
                'setting:theme' => ' ',
            ],
        );

        $this->assertFalse($coverage->passes());
        $this->assertSame(['action:save', 'action:gone', 'setting:theme'], $coverage->staleExemptions);
    }

    public function testATagMustStandAlone(): void
    {
        // An email-ish "x@action:save" in a doc string is not a tag
        $coverage = new Coverage(self::CATALOG, ['a.feature' => "x@action:save\n  When I use the action \"undo\" later"], []);

        $this->assertContains('action:save', $coverage->missing);
        $this->assertNotContains('action:undo', $coverage->missing);
    }
}
