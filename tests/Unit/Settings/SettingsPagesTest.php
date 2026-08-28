<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsPages;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsSection;

/**
 * The page catalogue every section contributes to.
 *
 * Its whole job is that there is ONE list. A page reachable in the nav and
 * missing from the catalogue — or the reverse — is what makes a search over
 * settings untrustworthy, and it is exactly what a second hardcoded list would
 * produce.
 */
final class SettingsPagesTest extends TestCase
{
    public function testEverySectionsPagesAppearInOrder(): void
    {
        $pages = new SettingsPages([
            $this->section(['a' => 'Alpha'], claims: 'a'),
            $this->section(['b' => 'Beta', 'b:one' => 'One'], claims: 'b'),
        ])->all();

        $this->assertSame(['a', 'b', 'b:one'], array_column($pages, 'id'));
        $this->assertSame(['Alpha', 'Beta', 'One'], array_column($pages, 'label'));
    }

    public function testEveryPageIdIsClaimedBySomeSection(): void
    {
        // The property that matters: you can navigate to everything listed.
        $pages = new SettingsPages(
            $sections = [
                $this->section(['a' => 'Alpha'], claims: 'a'),
                $this->section(['b' => 'Beta'], claims: 'b'),
            ],
        );

        foreach ($pages->all() as $page) {
            $this->assertNotNull($pages->sectionFor($page->id), $page->id.' is listed but unreachable');
        }
        $this->assertCount(2, $sections);
    }

    public function testPageIdsAreUnique(): void
    {
        // Two sections claiming one id means first-match-wins decides which
        // page you actually land on — a coin toss dressed as a nav.
        $pages = new SettingsPages([
            $this->section(['a' => 'Alpha'], claims: 'a'),
            $this->section(['b' => 'Beta'], claims: 'b'),
        ])->all();

        $ids = array_column($pages, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)));
    }

    public function testTheFirstClaimingSectionWins(): void
    {
        $first = $this->section(['x' => 'First'], claims: 'x');
        $pages = new SettingsPages([$first, $this->section(['x2' => 'Second'], claims: 'x')]);

        $this->assertSame($first, $pages->sectionFor('x'));
    }

    public function testAnUnclaimedIdIsARefusalRatherThanASilentFallback(): void
    {
        $pages = new SettingsPages([$this->section(['a' => 'Alpha'], claims: 'a')]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/No settings section claimed ghost/');
        $pages->sectionFor('ghost');
    }

    /** @param array<string, string> $pages id => label */
    private function section(array $pages, string $claims): SettingsSection
    {
        return new class($pages, $claims) implements SettingsSection {
            /** @param array<string, string> $pages */
            public function __construct(
                private readonly array $pages,
                private readonly string $claims,
            ) {}

            public function handles(string $section): bool
            {
                return $this->claims === $section;
            }

            public function pages(): array
            {
                $out = [];
                foreach ($this->pages as $id => $label) {
                    $out[] = new SettingsPage($id, $label);
                }

                return $out;
            }

            public function rows(string $section): array
            {
                return [];
            }

            public function change(string $section, string $key, bool|int|string $value): bool
            {
                return true;
            }

            public function reset(string $section, string $key): void {}
        };
    }
}
