<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\HighContrastTheme;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;

/**
 * Locks the theme-swap contract: the tui StyleSheet can only add rules, never
 * remove them, so a live theme switch overwrites the previous theme exactly
 * when every theme defines the same selector set.
 */
final class SelectorParityTest extends TestCase
{
    public function testEveryThemeDefinesTheSameSelectorSet(): void
    {
        $reference = \array_keys(DarkTheme::definition()->rules());
        \sort($reference);

        foreach (['light' => LightTheme::definition(), 'high-contrast' => HighContrastTheme::definition(), 'monochrome' => MonochromeTheme::definition()] as $name => $def) {
            $selectors = \array_keys($def->rules());
            \sort($selectors);
            $this->assertSame($reference, $selectors, "the {$name} theme must cover the same selectors as dark");
        }
    }

    public function testEveryColoredThemeDefinesTheFourUnderlineColors(): void
    {
        $underlineRoles = [Role::DiagErrorUnderline, Role::DiagWarnUnderline, Role::DiagInfoUnderline, Role::DiagHintUnderline];

        foreach (['dark' => DarkTheme::definition(), 'light' => LightTheme::definition(), 'high-contrast' => HighContrastTheme::definition()] as $name => $def) {
            foreach ($underlineRoles as $role) {
                $this->assertStringStartsWith("\x1b[58;5;", $def->underline($role), "{$name} must color {$role->name}");
            }
        }
    }

    public function testRoleSelectorsAreKebabCasedAndUnique(): void
    {
        $this->assertSame('.role-added', Role::Added->selector());
        $this->assertSame('.role-add-emphasis-bg', Role::AddEmphasisBg->selector());
        $this->assertSame('.role-diag-error-underline', Role::DiagErrorUnderline->selector());

        $selectors = \array_map(static fn (Role $role): string => $role->selector(), Role::cases());
        $this->assertSame($selectors, \array_unique($selectors), 'no two roles may share a selector');
    }
}
