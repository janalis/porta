<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\ActionCatalog;
use Porta\Config\ActionRegistry;

final class ActionRegistryTest extends TestCase
{
    public function testAllServesTheCatalogLabels(): void
    {
        $this->assertSame(ActionCatalog::labels(), ActionRegistry::all());
    }

    public function testLabelLookup(): void
    {
        $this->assertSame('Save File', ActionRegistry::label('save'));
        // Unknown action falls back to a title-cased slug.
        $this->assertSame('Some Made Up', ActionRegistry::label('some_made_up'));
    }
}
