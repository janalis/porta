<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\ThemeDefinition;
use Symfony\Component\Tui\Style\Style;

final class ThemeDefinitionTest extends TestCase
{
    public function testFillsEveryOmittedRoleSelectorWithThePlainStyle(): void
    {
        $def = new ThemeDefinition(rules: [], underlines: [], hasColor: false);

        foreach (Role::cases() as $role) {
            $this->assertArrayHasKey($role->selector(), $def->rules(), $role->name.' must have a rule');
            $this->assertTrue($def->of($role)->isPlain(), $role->name.' must fill as the plain style');
        }
    }

    public function testOfReturnsTheGivenRuleForARoleSelector(): void
    {
        $added = new Style(color: 114);
        $def = new ThemeDefinition(rules: [Role::Added->selector() => $added], underlines: [], hasColor: true);

        $this->assertSame($added, $def->of(Role::Added));
        $this->assertSame($added, $def->rules()[Role::Added->selector()]);
    }

    public function testNonRoleSelectorsPassThroughToTheRuleMap(): void
    {
        $toolbar = new Style(background: 235);
        $def = new ThemeDefinition(rules: ['.custom-chrome' => $toolbar], underlines: [], hasColor: true);

        $this->assertSame($toolbar, $def->rules()['.custom-chrome']);
    }

    public function testUnderlineFallsBackToTheEmptySequence(): void
    {
        $def = new ThemeDefinition(
            rules: [],
            underlines: [Role::DiagErrorUnderline->name => "\x1b[58;5;203m"],
            hasColor: true,
        );

        $this->assertSame("\x1b[58;5;203m", $def->underline(Role::DiagErrorUnderline));
        $this->assertSame('', $def->underline(Role::DiagWarnUnderline));
    }

    public function testHasColor(): void
    {
        $this->assertTrue(new ThemeDefinition(rules: [], underlines: [], hasColor: true)->hasColor());
        $this->assertFalse(new ThemeDefinition(rules: [], underlines: [], hasColor: false)->hasColor());
    }
}
