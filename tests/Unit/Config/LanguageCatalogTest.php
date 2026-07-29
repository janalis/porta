<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\LanguageCatalog;
use Porta\Support\LanguageMap;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\Probe;

final class LanguageCatalogTest extends TestCase
{
    public function testIdsAndDisplayNames(): void
    {
        $ids = LanguageCatalog::ids();
        $this->assertContains('php', $ids);
        $this->assertContains('typescript', $ids);
        $this->assertCount(11, $ids);
        $this->assertSame('PHP', LanguageCatalog::displayName('php'));
        $this->assertSame('SCSS', LanguageCatalog::displayName('scss'));
    }

    public function testKnownMatchesProjectPathsIds(): void
    {
        // Every catalog id is one ProjectPaths resolves a real file to.
        foreach (['a.php', 'b.js', 'c.ts', 'd.py', 'e.scss', 'f.twig'] as $file) {
            $this->assertTrue(LanguageCatalog::isKnown(ProjectPaths::languageId($file)));
        }
        $this->assertFalse(LanguageCatalog::isKnown('plaintext'), 'the catch-all is not configurable');
        $this->assertFalse(LanguageCatalog::isKnown('klingon'));
    }

    /**
     * The class docblock claims the catalog ids match what {@see LanguageMap}
     * resolves files to. Assert it exhaustively rather than by sample, so a
     * language added to one side can't silently miss its settings row.
     */
    public function testCatalogCoversExactlyTheResolvableLanguageIds(): void
    {
        $catalog = LanguageCatalog::ids();
        $resolvable = array_values(array_unique(array_filter(array_map(static fn (array $entry): ?string => $entry[1], Probe::getStatic(LanguageMap::class, 'EXTENSIONS')))));

        sort($catalog);
        sort($resolvable);

        $this->assertSame($resolvable, $catalog);
    }

    public function testUnknownDisplayNameFallsBackToTheId(): void
    {
        $this->assertSame('klingon', LanguageCatalog::displayName('klingon'));
    }
}
