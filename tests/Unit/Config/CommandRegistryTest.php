<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;

final class CommandRegistryTest extends TestCase
{
    public function testLabelReturnsRegisteredLabelIncludingCustom(): void
    {
        $registry = new CommandRegistry();
        $registry
            ->add('save', static function (): void {})
            ->add('toggle_inline_diagnostics', static function (): void {}, label: 'Toggle Inline Diagnostics');

        $this->assertSame('Save File', $registry->label('save'), 'falls back to ActionCatalog label');
        $this->assertSame('Toggle Inline Diagnostics', $registry->label('toggle_inline_diagnostics'), 'custom label');
    }

    public function testLabelReturnsNullForUnknownId(): void
    {
        $this->assertNull(new CommandRegistry()->label('nope'));
    }

    public function testWhenGuardGatesKeyDispatchEnablement(): void
    {
        $enabled = false;
        $registry = new CommandRegistry();
        $registry
            ->add('save', static function (): void {})
            ->add('debug_step_over', static function (): void {}, keyBound: KeyDispatch::Bound, when: static function () use (&$enabled): bool {
                return $enabled;
            });

        $this->assertTrue($registry->isEnabled('save'), 'no guard → always enabled');
        $this->assertFalse($registry->isEnabled('debug_step_over'), 'guard says no');
        $enabled = true;
        $this->assertTrue($registry->isEnabled('debug_step_over'), 'guard is re-evaluated live');
        $this->assertFalse($registry->isEnabled('nope'), 'unknown ids are never enabled');
    }
}
