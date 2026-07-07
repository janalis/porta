<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\LanguageCatalog;
use Porta\Support\ProjectPaths;

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

    public function testUnknownDisplayNameFallsBackToTheId(): void
    {
        $this->assertSame('klingon', LanguageCatalog::displayName('klingon'));
    }
}
