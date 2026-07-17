<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui\Theme;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\Role;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\HighContrastTheme;
use Porta\Ui\Theme\LightTheme;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\ThemeDefinition;
use Symfony\Component\Tui\Style\Style;

/**
 * Locks every theme's per-role Style — the guardrail against a visual
 * regression, successor to the palette's SGR lock: the dark table reproduces
 * the historical hardcoded colors exactly.
 */
final class ThemeLockTest extends TestCase
{
    /** @return iterable<string, array{Role, Style}> */
    public static function darkRoles(): iterable
    {
        yield 'gutter' => [Role::Gutter, new Style(color: 240)];
        yield 'added' => [Role::Added, new Style(color: 114)];
        yield 'deleted' => [Role::Deleted, new Style(color: 203)];
        yield 'hunk' => [Role::Hunk, new Style(color: 75)];
        yield 'add-emphasis-bg' => [Role::AddEmphasisBg, new Style(background: 22)];
        yield 'del-emphasis-bg' => [Role::DelEmphasisBg, new Style(background: 88)];
        yield 'selected-row' => [Role::SelectedRow, new Style(background: 31, color: 231, bold: true)];
        yield 'selected-row-unfocused' => [Role::SelectedRowUnfocused, new Style(background: 237)];
        yield 'row-highlight-bg' => [Role::RowHighlightBg, new Style(background: 236)];
        yield 'popup-row' => [Role::PopupRow, new Style(background: 236, color: 250)];
        yield 'header-bar' => [Role::HeaderBar, new Style(background: 237)];
        yield 'toolbar' => [Role::Toolbar, new Style(background: 235)];
        yield 'tag-off-bg' => [Role::TagOffBg, new Style(background: 238)];
        yield 'modified' => [Role::Modified, new Style(color: 179)];
        yield 'danger-bg' => [Role::DangerBg, new Style(background: 88)];
        yield 'dim' => [Role::Dim, new Style(color: 245)];
        yield 'muted' => [Role::Muted, new Style(color: 250)];
        yield 'bright' => [Role::Bright, new Style(color: 231)];
        yield 'diag-error' => [Role::DiagError, new Style(color: 203)];
        yield 'diag-warn' => [Role::DiagWarn, new Style(color: 179)];
        yield 'diag-info' => [Role::DiagInfo, new Style(color: 75)];
        yield 'diag-hint' => [Role::DiagHint, new Style(color: 244)];
        yield 'diag-error-dim' => [Role::DiagErrorDim, new Style(color: 131)];
        yield 'diag-warn-dim' => [Role::DiagWarnDim, new Style(color: 101)];
        yield 'diag-info-dim' => [Role::DiagInfoDim, new Style(color: 67)];
        yield 'diag-hint-dim' => [Role::DiagHintDim, new Style(color: 240)];
        yield 'current-line-number' => [Role::CurrentLineNumber, new Style(color: 252)];
        yield 'placeholder' => [Role::Placeholder, new Style(color: 238)];
        yield 'inlay' => [Role::Inlay, new Style(color: 243)];
        yield 'indent-guide' => [Role::IndentGuide, new Style(color: 238)];
        yield 'git-added' => [Role::GitAdded, new Style(color: 114)];
        yield 'git-modified' => [Role::GitModified, new Style(color: 75)];
        yield 'git-deleted' => [Role::GitDeleted, new Style(color: 203)];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, new Style(background: 238)];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, new Style(color: 223)];
        yield 'completion-hint' => [Role::CompletionHint, new Style(color: 244)];
        yield 'completion-kind' => [Role::CompletionKind, new Style(color: 180)];
        yield 'selection-bg' => [Role::SelectionBg, new Style(background: 24)];
        yield 'search-bg' => [Role::SearchBg, new Style(background: 58)];
        yield 'search-current-bg' => [Role::SearchCurrentBg, new Style(background: 130)];
        yield 'occurrence-bg' => [Role::OccurrenceBg, new Style(background: 23)];
        yield 'breakpoint' => [Role::Breakpoint, new Style(color: 203)];
        yield 'debug-line-bg' => [Role::DebugLineBg, new Style(background: 52)];
        yield 'tab-accent' => [Role::TabAccent, new Style(color: 110)];
        yield 'bar-hover-bg' => [Role::BarHoverBg, new Style(background: 240)];
        yield 'faint' => [Role::Faint, new Style(color: 242)];
        yield 'subtle' => [Role::Subtle, new Style(color: 246)];
        yield 'emphasis' => [Role::Emphasis, new Style(color: 255)];
        yield 'status-branch' => [Role::StatusBranch, new Style(color: 114)];
        yield 'status-debug' => [Role::StatusDebug, new Style(color: 203)];
        yield 'status-error' => [Role::StatusError, new Style(color: 203)];
        yield 'status-warning' => [Role::StatusWarning, new Style(color: 179)];
    }

    #[DataProvider('darkRoles')]
    public function testDarkThemeReproducesTheHistoricalColors(Role $role, Style $expected): void
    {
        $this->assertEquals($expected, DarkTheme::definition()->of($role));
    }

    /** @return iterable<string, array{Role, Style}> */
    public static function lightRoles(): iterable
    {
        yield 'gutter' => [Role::Gutter, new Style(color: 245)];
        yield 'added' => [Role::Added, new Style(color: 28)];
        yield 'deleted' => [Role::Deleted, new Style(color: 160)];
        yield 'hunk' => [Role::Hunk, new Style(color: 25)];
        yield 'add-emphasis-bg' => [Role::AddEmphasisBg, new Style(background: 194)];
        yield 'del-emphasis-bg' => [Role::DelEmphasisBg, new Style(background: 224)];
        yield 'selected-row' => [Role::SelectedRow, new Style(background: 111, color: 16, bold: true)];
        yield 'selected-row-unfocused' => [Role::SelectedRowUnfocused, new Style(background: 253)];
        yield 'row-highlight-bg' => [Role::RowHighlightBg, new Style(background: 253)];
        yield 'popup-row' => [Role::PopupRow, new Style(background: 253, color: 240)];
        yield 'header-bar' => [Role::HeaderBar, new Style(background: 253)];
        yield 'toolbar' => [Role::Toolbar, new Style(background: 254)];
        yield 'tag-off-bg' => [Role::TagOffBg, new Style(background: 252)];
        yield 'modified' => [Role::Modified, new Style(color: 130)];
        yield 'danger-bg' => [Role::DangerBg, new Style(background: 224)];
        yield 'dim' => [Role::Dim, new Style(color: 244)];
        yield 'muted' => [Role::Muted, new Style(color: 240)];
        yield 'bright' => [Role::Bright, new Style(color: 16)];
        yield 'diag-error' => [Role::DiagError, new Style(color: 160)];
        yield 'diag-warn' => [Role::DiagWarn, new Style(color: 130)];
        yield 'diag-info' => [Role::DiagInfo, new Style(color: 25)];
        yield 'diag-hint' => [Role::DiagHint, new Style(color: 245)];
        yield 'diag-error-dim' => [Role::DiagErrorDim, new Style(color: 124)];
        yield 'diag-warn-dim' => [Role::DiagWarnDim, new Style(color: 136)];
        yield 'diag-info-dim' => [Role::DiagInfoDim, new Style(color: 67)];
        yield 'diag-hint-dim' => [Role::DiagHintDim, new Style(color: 240)];
        yield 'current-line-number' => [Role::CurrentLineNumber, new Style(color: 240)];
        yield 'placeholder' => [Role::Placeholder, new Style(color: 250)];
        yield 'inlay' => [Role::Inlay, new Style(color: 245)];
        yield 'indent-guide' => [Role::IndentGuide, new Style(color: 250)];
        yield 'git-added' => [Role::GitAdded, new Style(color: 28)];
        yield 'git-modified' => [Role::GitModified, new Style(color: 25)];
        yield 'git-deleted' => [Role::GitDeleted, new Style(color: 160)];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, new Style(background: 254)];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, new Style(color: 240)];
        yield 'completion-hint' => [Role::CompletionHint, new Style(color: 244)];
        yield 'completion-kind' => [Role::CompletionKind, new Style(color: 97)];
        yield 'selection-bg' => [Role::SelectionBg, new Style(background: 153)];
        yield 'search-bg' => [Role::SearchBg, new Style(background: 229)];
        yield 'search-current-bg' => [Role::SearchCurrentBg, new Style(background: 215)];
        yield 'occurrence-bg' => [Role::OccurrenceBg, new Style(background: 194)];
        yield 'breakpoint' => [Role::Breakpoint, new Style(color: 160)];
        yield 'debug-line-bg' => [Role::DebugLineBg, new Style(background: 224)];
        yield 'tab-accent' => [Role::TabAccent, new Style(color: 25)];
        yield 'bar-hover-bg' => [Role::BarHoverBg, new Style(background: 252)];
        yield 'faint' => [Role::Faint, new Style(color: 245)];
        yield 'subtle' => [Role::Subtle, new Style(color: 246)];
        yield 'emphasis' => [Role::Emphasis, new Style(color: 16)];
        yield 'status-branch' => [Role::StatusBranch, new Style(color: 28)];
        yield 'status-debug' => [Role::StatusDebug, new Style(color: 160)];
        yield 'status-error' => [Role::StatusError, new Style(color: 160)];
        yield 'status-warning' => [Role::StatusWarning, new Style(color: 130)];
    }

    #[DataProvider('lightRoles')]
    public function testLightThemeMapsEveryRole(Role $role, Style $expected): void
    {
        $this->assertEquals($expected, LightTheme::definition()->of($role));
    }

    /**
     * The four SGR-58 curly-underline colors per theme — kept as raw sequences
     * because tui's Style has no underline-color property.
     *
     * @return iterable<string, array{ThemeDefinition, Role, string}>
     */
    public static function underlines(): iterable
    {
        yield 'dark error' => [DarkTheme::definition(), Role::DiagErrorUnderline, "\x1b[58;5;203m"];
        yield 'dark warn' => [DarkTheme::definition(), Role::DiagWarnUnderline, "\x1b[58;5;179m"];
        yield 'dark info' => [DarkTheme::definition(), Role::DiagInfoUnderline, "\x1b[58;5;75m"];
        yield 'dark hint' => [DarkTheme::definition(), Role::DiagHintUnderline, "\x1b[58;5;244m"];
        yield 'light error' => [LightTheme::definition(), Role::DiagErrorUnderline, "\x1b[58;5;160m"];
        yield 'light warn' => [LightTheme::definition(), Role::DiagWarnUnderline, "\x1b[58;5;130m"];
        yield 'light info' => [LightTheme::definition(), Role::DiagInfoUnderline, "\x1b[58;5;25m"];
        yield 'light hint' => [LightTheme::definition(), Role::DiagHintUnderline, "\x1b[58;5;245m"];
        yield 'high-contrast error' => [HighContrastTheme::definition(), Role::DiagErrorUnderline, "\x1b[58;5;203m"];
        yield 'high-contrast warn' => [HighContrastTheme::definition(), Role::DiagWarnUnderline, "\x1b[58;5;226m"];
        yield 'high-contrast info' => [HighContrastTheme::definition(), Role::DiagInfoUnderline, "\x1b[58;5;51m"];
        yield 'high-contrast hint' => [HighContrastTheme::definition(), Role::DiagHintUnderline, "\x1b[58;5;250m"];
    }

    #[DataProvider('underlines')]
    public function testUnderlineColors(ThemeDefinition $def, Role $role, string $expected): void
    {
        $this->assertSame($expected, $def->underline($role));
    }

    public function testHighContrastStylesEveryStylableRole(): void
    {
        $hc = HighContrastTheme::definition();
        foreach (Role::cases() as $role) {
            if ('' !== $hc->underline($role)) {
                continue;
            }
            $this->assertFalse($hc->of($role)->isPlain(), $role->name.' must have a high-contrast style');
        }
    }

    public function testHighContrastKeyPairings(): void
    {
        // Spot-locks for the accessibility-critical picks; the full table is free
        // to be tuned as long as every pairing stays at WCAG-AAA contrast.
        $hc = HighContrastTheme::definition();
        $this->assertEquals(new Style(background: 19, color: 231, bold: true), $hc->of(Role::SelectedRow), 'white-on-deep-blue selection, bold');
        $this->assertEquals(new Style(color: 231), $hc->of(Role::Bright), 'near-white base text');
        $this->assertEquals(new Style(color: 226), $hc->of(Role::DiagWarn), 'bright yellow warnings');
        $this->assertEquals($hc->of(Role::DiagError), $hc->of(Role::DiagErrorDim), 'no dim tier in high contrast');
        $this->assertEquals($hc->of(Role::DiagInfo), $hc->of(Role::DiagInfoDim), 'no dim tier in high contrast');
    }

    public function testMonochromeStylesNoRoleExceptTheSelection(): void
    {
        // The selection roles are the deliberate exception: without color, the
        // selected row would otherwise be invisible, so they map to reverse video.
        $mono = MonochromeTheme::definition();
        foreach (Role::cases() as $role) {
            if (Role::SelectedRow === $role || Role::SelectedRowUnfocused === $role) {
                continue;
            }
            $this->assertTrue($mono->of($role)->isPlain(), $role->name.' must stay plain');
            $this->assertSame('', $mono->underline($role), $role->name.' must have no underline color');
        }
    }

    public function testMonochromeSelectionUsesReverseVideo(): void
    {
        $mono = MonochromeTheme::definition();
        $this->assertEquals(new Style(reverse: true), $mono->of(Role::SelectedRow));
        $this->assertEquals(new Style(dim: true, reverse: true), $mono->of(Role::SelectedRowUnfocused));
    }

    public function testListElementRulesMirrorTheSelectionRolesInEveryTheme(): void
    {
        // The selection convention (Role.php): always-focused modal surfaces
        // apply SelectedRow directly while focus-tracking panes resolve the
        // .list rules — the two families must render identically per theme.
        $themes = ['dark' => DarkTheme::definition(), 'light' => LightTheme::definition(), 'high-contrast' => HighContrastTheme::definition(), 'monochrome' => MonochromeTheme::definition()];
        foreach ($themes as $name => $def) {
            $rules = $def->rules();
            $this->assertEquals($def->of(Role::SelectedRowUnfocused), $rules['.list::selected'], "{$name}: .list::selected must equal SelectedRowUnfocused");
            $this->assertEquals($def->of(Role::SelectedRow), $rules['.list::selected:focus'], "{$name}: .list::selected:focus must equal SelectedRow");
        }
    }

    public function testHasColor(): void
    {
        $this->assertTrue(DarkTheme::definition()->hasColor());
        $this->assertTrue(LightTheme::definition()->hasColor());
        $this->assertTrue(HighContrastTheme::definition()->hasColor());
        $this->assertFalse(MonochromeTheme::definition()->hasColor());
    }
}
