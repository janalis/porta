<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\Probe;
use Porta\Ui\Role;
use Porta\Workbench\StatusBarProvider;
use Porta\Workbench\StatusTone;

/**
 * A contributor asks for prominence; core picks the colour.
 *
 * That split is what lets a plugin contribute a status cell without being able
 * to invent a colour — the thing the theme lock exists to prevent. It only
 * holds if the mapping is actually applied, and a mapping that quietly sends
 * every tone to the same role would look exactly like a working one on a
 * screen nobody is comparing against a reference.
 *
 * Exhaustive over the enum on purpose: a new tone with no mapping is a runtime
 * UnhandledMatchError the moment that contributor renders, which is a crash in
 * the status bar rather than a wrong colour.
 */
final class StatusToneTest extends TestCase
{
    public function testEveryToneMapsToItsOwnRole(): void
    {
        $expected = [
            StatusTone::Subtle->name => Role::Subtle,
            StatusTone::Accent->name => Role::StatusAccent,
            StatusTone::Active->name => Role::StatusActive,
        ];

        $actual = [];
        foreach (StatusTone::cases() as $tone) {
            $actual[$tone->name] = Probe::invokeStatic(StatusBarProvider::class, 'role', $tone);
        }

        $this->assertSame($expected, $actual);
        $this->assertCount(\count(StatusTone::cases()), $expected, 'every case is mapped, and only cases are');
    }

    public function testTheThreeTonesAreThreeDistinctRoles(): void
    {
        // The failure this catches: a mapping collapsed onto one role. The
        // debugger's segment and the branch would still paint, in the same
        // colour, and only a golden render would notice.
        $roles = \array_map(
            static fn (StatusTone $tone): Role => Probe::invokeStatic(StatusBarProvider::class, 'role', $tone),
            StatusTone::cases(),
        );

        $this->assertCount(\count($roles), \array_unique(\array_map(static fn (Role $role): string => $role->name, $roles)));
    }
}
