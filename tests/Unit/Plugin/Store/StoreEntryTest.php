<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Plugin\Store;

use PHPUnit\Framework\TestCase;
use Porta\Plugin\Store\StoreAction;
use Porta\Plugin\Store\StoreCategory;
use Porta\Plugin\Store\StoreEntry;

/** The store's plain data: what survives the cache, what a query finds, what a button says. */
final class StoreEntryTest extends TestCase
{
    private static function entry(): StoreEntry
    {
        return new StoreEntry(
            'acme/porta-rector',
            'rector',
            'Rector',
            'Ada',
            'Refactorings on the current file',
            StoreCategory::Linters,
            'v1.2.0',
            1_800_000_000,
            40,
            ['https://example.com/a.png'],
            'https://raw.example/CHANGELOG.md',
            'https://raw.example/README.md',
            'https://github.com/acme/porta-rector',
            'MIT',
            verified: true,
            featured: true,
            replaces: 'rector',
            compatible: false,
            history: [
                ['v1.2.0', 1_800_000_000],
                ['v1.1.0', 0],
            ],
        );
    }

    public function testAnEntryRoundTripsThroughTheCache(): void
    {
        $entry = self::entry();
        $this->assertEquals($entry, StoreEntry::fromArray($entry->toArray()));
    }

    public function testACacheEntryIsReadAsFarAsItGoes(): void
    {
        $entry = StoreEntry::fromArray(['package' => 'a/porta-b', 'name' => 'b', 'version' => '1.0', 'time' => 'soon', 'history' => [['1.0', 5], ['2.0'], 'x', [1, 2]], 'screenshots' => 'one']);

        $this->assertInstanceOf(StoreEntry::class, $entry);
        $this->assertSame(StoreCategory::Other, $entry->category);
        $this->assertSame(0, $entry->time);
        $this->assertSame([], $entry->screenshots);
        $this->assertSame([['1.0', 5]], $entry->history);
        $this->assertNull($entry->changelog);
        $this->assertTrue($entry->compatible, 'not said otherwise: it runs');
        $this->assertFalse($entry->verified);

        $this->assertNull(StoreEntry::fromArray(['name' => 'b', 'version' => '1.0']), 'no package');
        $this->assertNull(StoreEntry::fromArray(['package' => 'a/b', 'version' => '1.0']), 'no name');
        $this->assertNull(StoreEntry::fromArray(['package' => 'a/b', 'name' => 'b']), 'no version');
    }

    public function testAQueryFindsTitlePackageDescriptionOrAuthor(): void
    {
        $entry = self::entry();
        foreach (['', 'rector', 'acme/', 'refactorings', 'ada'] as $query) {
            $this->assertTrue($entry->matches($query), $query);
        }
        $this->assertFalse($entry->matches('phpstan'));
    }

    public function testCategoriesReadAnythingAndNameEveryShelf(): void
    {
        $this->assertSame(StoreCategory::Ai, StoreCategory::read('ai'));
        $this->assertSame(StoreCategory::Other, StoreCategory::read('cooking'));
        $this->assertSame(StoreCategory::Other, StoreCategory::read(3));
        $labels = \array_map(static fn (StoreCategory $category): string => $category->label(), StoreCategory::cases());
        $this->assertCount(\count(StoreCategory::cases()), \array_unique($labels), 'each shelf its own name');
        $this->assertSame('Linters & formatters', StoreCategory::Linters->label());
    }

    public function testEveryButtonSaysWhatItDoesAndOnlyTwoDoNothing(): void
    {
        $captions = \array_map(static fn (StoreAction $action): string => $action->caption(), StoreAction::cases());
        $this->assertSame(['Get', 'Open', 'Update', 'Built-in', 'Enable', 'Working…', 'Incompatible'], $captions);
        $inert = \array_values(\array_filter(StoreAction::cases(), static fn (StoreAction $action): bool => !$action->pressable()));
        $this->assertSame([StoreAction::Working, StoreAction::Incompatible], $inert);
    }
}
