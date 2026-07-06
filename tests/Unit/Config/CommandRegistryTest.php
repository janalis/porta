<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;

final class CommandRegistryTest extends TestCase
{
    public function testLabelReturnsRegisteredLabelIncludingCustom(): void
    {
        $registry = new CommandRegistry();
        $registry
            ->add('save', static function (): void {})
            ->add('toggle_inline_diagnostics', static function (): void {}, label: 'Toggle Inline Diagnostics');

        $this->assertSame('Save File', $registry->label('save'), 'falls back to ActionRegistry label');
        $this->assertSame('Toggle Inline Diagnostics', $registry->label('toggle_inline_diagnostics'), 'custom label');
    }

    public function testLabelReturnsNullForUnknownId(): void
    {
        $this->assertNull(new CommandRegistry()->label('nope'));
    }
}
