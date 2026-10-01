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
use Porta\Ui\Theme\Styles;
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
        yield 'drop-target-row' => [Role::DropTargetRow, new Style(background: 22, color: 231, bold: true)];
        yield 'field-border' => [Role::FieldBorder, new Style(color: 245)];
        yield 'field-placeholder' => [Role::FieldPlaceholder, new Style(color: 243, italic: true)];
        yield 'popup-row' => [Role::PopupRow, new Style(background: 236, color: 250)];
        yield 'header-bar' => [Role::HeaderBar, new Style(background: 237)];
        yield 'toolbar' => [Role::Toolbar, new Style(background: 235)];
        yield 'modified' => [Role::Modified, new Style(color: 179)];
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
        yield 'rule' => [Role::Rule, new Style(color: 238)];
        yield 'inlay' => [Role::Inlay, new Style(color: 243)];
        yield 'debug-value' => [Role::DebugValue, new Style(color: 149)];
        yield 'indent-guide' => [Role::IndentGuide, new Style(color: 238)];
        yield 'git-added' => [Role::VcsAdded, new Style(color: 114)];
        yield 'git-modified' => [Role::VcsModified, new Style(color: 75)];
        yield 'git-deleted' => [Role::VcsDeleted, new Style(color: 203)];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, new Style(background: 238)];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, new Style(color: 223)];
        yield 'completion-hint' => [Role::CompletionHint, new Style(color: 244)];
        yield 'completion-kind' => [Role::CompletionKind, new Style(color: 180)];
        yield 'selection-bg' => [Role::SelectionBg, new Style(background: 24)];
        yield 'search-bg' => [Role::SearchBg, new Style(background: 58)];
        yield 'search-current-bg' => [Role::SearchCurrentBg, new Style(background: 130)];
        yield 'occurrence-bg' => [Role::OccurrenceBg, new Style(background: 23)];
        yield 'occurrence-write-bg' => [Role::OccurrenceWriteBg, new Style(background: 52)];
        yield 'agent-1' => [Role::Agent1, new Style(background: 25, color: 231)];
        yield 'agent-2' => [Role::Agent2, new Style(background: 130, color: 231)];
        yield 'agent-3' => [Role::Agent3, new Style(background: 91, color: 231)];
        yield 'agent-4' => [Role::Agent4, new Style(background: 29, color: 231)];
        yield 'agent-5' => [Role::Agent5, new Style(background: 124, color: 231)];
        yield 'agent-6' => [Role::Agent6, new Style(background: 162, color: 231)];
        yield 'agent-7' => [Role::Agent7, new Style(background: 37, color: 231)];
        yield 'agent-8' => [Role::Agent8, new Style(background: 100, color: 231)];
        yield 'breakpoint' => [Role::Breakpoint, new Style(color: 203)];
        yield 'debug-line-bg' => [Role::DebugLineBg, new Style(background: 52)];
        yield 'tab-accent' => [Role::TabAccent, new Style(color: 110)];
        yield 'tab-active-bg' => [Role::TabActiveBg, new Style(background: 238)];
        yield 'bar-hover-bg' => [Role::BarHoverBg, new Style(background: 240)];
        yield 'faint' => [Role::Faint, new Style(color: 242)];
        yield 'subtle' => [Role::Subtle, new Style(color: 246)];
        yield 'emphasis' => [Role::Emphasis, new Style(color: 255)];
        yield 'status-accent' => [Role::StatusAccent, new Style(color: 114)];
        yield 'status-active' => [Role::StatusActive, new Style(color: 203)];
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
        yield 'drop-target-row' => [Role::DropTargetRow, new Style(background: 151, color: 16, bold: true)];
        yield 'field-border' => [Role::FieldBorder, new Style(color: 243)];
        yield 'field-placeholder' => [Role::FieldPlaceholder, new Style(color: 245, italic: true)];
        yield 'popup-row' => [Role::PopupRow, new Style(background: 253, color: 240)];
        yield 'header-bar' => [Role::HeaderBar, new Style(background: 253)];
        yield 'toolbar' => [Role::Toolbar, new Style(background: 254)];
        yield 'modified' => [Role::Modified, new Style(color: 130)];
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
        yield 'rule' => [Role::Rule, new Style(color: 250)];
        yield 'inlay' => [Role::Inlay, new Style(color: 245)];
        yield 'debug-value' => [Role::DebugValue, new Style(color: 28)];
        yield 'indent-guide' => [Role::IndentGuide, new Style(color: 250)];
        yield 'git-added' => [Role::VcsAdded, new Style(color: 28)];
        yield 'git-modified' => [Role::VcsModified, new Style(color: 25)];
        yield 'git-deleted' => [Role::VcsDeleted, new Style(color: 160)];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, new Style(background: 254)];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, new Style(color: 240)];
        yield 'completion-hint' => [Role::CompletionHint, new Style(color: 244)];
        yield 'completion-kind' => [Role::CompletionKind, new Style(color: 97)];
        yield 'selection-bg' => [Role::SelectionBg, new Style(background: 153)];
        yield 'search-bg' => [Role::SearchBg, new Style(background: 229)];
        yield 'search-current-bg' => [Role::SearchCurrentBg, new Style(background: 215)];
        yield 'occurrence-bg' => [Role::OccurrenceBg, new Style(background: 194)];
        yield 'occurrence-write-bg' => [Role::OccurrenceWriteBg, new Style(background: 224)];
        yield 'agent-1' => [Role::Agent1, new Style(background: 117, color: 16)];
        yield 'agent-2' => [Role::Agent2, new Style(background: 215, color: 16)];
        yield 'agent-3' => [Role::Agent3, new Style(background: 183, color: 16)];
        yield 'agent-4' => [Role::Agent4, new Style(background: 120, color: 16)];
        yield 'agent-5' => [Role::Agent5, new Style(background: 210, color: 16)];
        yield 'agent-6' => [Role::Agent6, new Style(background: 87, color: 16)];
        yield 'agent-7' => [Role::Agent7, new Style(background: 228, color: 16)];
        yield 'agent-8' => [Role::Agent8, new Style(background: 218, color: 16)];
        yield 'breakpoint' => [Role::Breakpoint, new Style(color: 160)];
        yield 'debug-line-bg' => [Role::DebugLineBg, new Style(background: 224)];
        yield 'tab-accent' => [Role::TabAccent, new Style(color: 25)];
        yield 'tab-active-bg' => [Role::TabActiveBg, new Style(background: 252)];
        yield 'bar-hover-bg' => [Role::BarHoverBg, new Style(background: 252)];
        yield 'faint' => [Role::Faint, new Style(color: 245)];
        yield 'subtle' => [Role::Subtle, new Style(color: 246)];
        yield 'emphasis' => [Role::Emphasis, new Style(color: 16)];
        yield 'status-accent' => [Role::StatusAccent, new Style(color: 28)];
        yield 'status-active' => [Role::StatusActive, new Style(color: 160)];
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

    public function testDarkStylesEveryStylableRole(): void
    {
        // Iterating Role::cases() (not a hardcoded list) so a NEW role that is
        // left unmapped resolves to plain and fails here — the guard the
        // Monochrome selection/search gaps slipped past when they were added.
        $dark = DarkTheme::definition();
        foreach (Role::cases() as $role) {
            if ('' !== $dark->underline($role)) {
                continue;
            }
            $this->assertFalse($dark->of($role)->isPlain(), $role->name.' must have a dark style');
        }
    }

    public function testLightStylesEveryStylableRole(): void
    {
        $light = LightTheme::definition();
        foreach (Role::cases() as $role) {
            if ('' !== $light->underline($role)) {
                continue;
            }
            $this->assertFalse($light->of($role)->isPlain(), $role->name.' must have a light style');
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
        $this->assertEquals(new Style(color: 118), $hc->of(Role::DebugValue), 'bright green live values');
        $this->assertEquals($hc->of(Role::DiagError), $hc->of(Role::DiagErrorDim), 'no dim tier in high contrast');
        $this->assertEquals($hc->of(Role::DiagInfo), $hc->of(Role::DiagInfoDim), 'no dim tier in high contrast');
        // A live drop target is accessibility-critical in the same way the
        // selection is: it says where files are about to go, and it has to be
        // unmistakable against BOTH the selection and the hover tint, which in
        // this theme are the two shades nearest it.
        $this->assertEquals(new Style(background: 28, color: 231, bold: true), $hc->of(Role::DropTargetRow), 'white-on-green drop target, bold');
        $this->assertNotEquals($hc->of(Role::SelectedRow), $hc->of(Role::DropTargetRow), 'and never the selection');
        $this->assertNotEquals($hc->of(Role::RowHighlightBg), $hc->of(Role::DropTargetRow), 'nor the hover tint');
    }

    public function testMonochromeStylesNoRoleExceptSelectionAndHover(): void
    {
        // The selection roles + active-tab shade (reverse) and the hover roles
        // (underline) are the deliberate exceptions: without color they would
        // otherwise be invisible. Character selection and search/occurrence
        // highlights join them (reverse / underline) for the same reason.
        $mono = MonochromeTheme::definition();
        $mapped = [
            Role::SelectedRow,
            Role::SelectedRowUnfocused,
            Role::TabActiveBg,
            Role::RowHighlightBg,
            Role::DropTargetRow,
            Role::FieldPlaceholder,
            Role::BarHoverBg,
            Role::SelectionBg,
            Role::SearchBg,
            Role::SearchCurrentBg,
            Role::OccurrenceBg,
            Role::OccurrenceWriteBg,
            Role::Agent1,
            Role::Agent2,
            Role::Agent3,
            Role::Agent4,
            Role::Agent5,
            Role::Agent6,
            Role::Agent7,
            Role::Agent8,
        ];
        foreach (Role::cases() as $role) {
            if (\in_array($role, $mapped, strict: true)) {
                continue;
            }
            $this->assertTrue($mono->of($role)->isPlain(), $role->name.' must stay plain');
            $this->assertSame('', $mono->underline($role), $role->name.' must have no underline color');
        }
    }

    public function testMonochromePlaceholderNeverReadsAsAValue(): void
    {
        // Dim is plain under monochrome, so a placeholder styled Dim would be
        // indistinguishable from typed text; its own role keeps a mark.
        $this->assertEquals(new Style(dim: true, italic: true), MonochromeTheme::definition()->of(Role::FieldPlaceholder));
    }

    public function testMonochromeSelectionUsesReverseVideo(): void
    {
        $mono = MonochromeTheme::definition();
        $this->assertEquals(new Style(reverse: true), $mono->of(Role::SelectedRow));
        $this->assertEquals(new Style(dim: true, reverse: true), $mono->of(Role::SelectedRowUnfocused));
        $this->assertEquals(new Style(reverse: true), $mono->of(Role::TabActiveBg), 'the active tab stays visible without color');
        $this->assertEquals(new Style(reverse: true), $mono->of(Role::SelectionBg), 'character selection survives --no-ansi');
    }

    public function testMonochromeSearchHighlightsUseUnderline(): void
    {
        // Search matches / occurrences would be invisible without a mark; underline
        // reads under --no-ansi (the current match adds bold at its call site).
        $mono = MonochromeTheme::definition();
        $this->assertEquals(new Style(underline: true), $mono->of(Role::SearchBg), 'search matches are visible');
        $this->assertEquals(new Style(reverse: true, bold: true), $mono->of(Role::SearchCurrentBg), 'the current hit reversed, like a selection');
        $this->assertEquals(new Style(underline: true), $mono->of(Role::OccurrenceBg));
        $this->assertEquals(new Style(underline: true, bold: true), $mono->of(Role::OccurrenceWriteBg), 'a WRITE occurrence stays distinct from a read one without color');
    }

    public function testEveryAgentGetsItsOwnColourAndMonochromeStillShowsTheCaret(): void
    {
        $this->assertSame([Role::Agent1, Role::Agent2, Role::Agent3, Role::Agent4, Role::Agent5, Role::Agent6, Role::Agent7, Role::Agent8, Role::Agent1], \array_map(Role::agent(...), [
            0,
            1,
            2,
            3,
            4,
            5,
            6,
            7,
            8,
        ]), 'a ninth agent shares the first colour');
        foreach ([DarkTheme::definition(), LightTheme::definition(), HighContrastTheme::definition()] as $theme) {
            $backgrounds = \array_map(static fn (Role $role): string => \json_encode($theme->of($role)->getAnsiRestore()), [
                Role::Agent1,
                Role::Agent2,
                Role::Agent3,
                Role::Agent4,
                Role::Agent5,
                Role::Agent6,
                Role::Agent7,
                Role::Agent8,
            ]);
            $this->assertCount(8, \array_unique($backgrounds), 'eight agents, eight colours');
        }
        $this->assertEquals(new Style(reverse: true, underline: true), MonochromeTheme::definition()->of(Role::Agent3));
    }

    public function testMonochromeHoverUsesUnderlineDistinctFromTheSelection(): void
    {
        // The tint-only hover must still read without color, and as a mark
        // distinct from the selection's reverse video (so a selected+hovered
        // row is not ambiguous — render precedence keeps the reverse).
        $mono = MonochromeTheme::definition();
        $this->assertEquals(new Style(underline: true), $mono->of(Role::RowHighlightBg));
        $this->assertEquals(new Style(underline: true), $mono->of(Role::BarHoverBg));
        $this->assertEquals(
            new Style(reverse: true, bold: true),
            $mono->of(Role::DropTargetRow),
            'a live drop target reads without colour, and not as the selection or a hover',
        );
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

    public function testCardElementRulesExistAndStaySubtleInEveryTheme(): void
    {
        // The third selection family (Role.php): the Welcome cards' tint. The
        // rules must exist in every theme — StyleSheetThemer can only
        // overwrite, so a missing selector would leave a stale tint after a
        // live switch — and must stay background-only shades: the selection
        // itself is the accent bar glyph, which is what monochrome (plain
        // here) and --no-ansi keep.
        $expected = [
            'dark' => [DarkTheme::definition(), new Style(background: 237), new Style(background: 238)],
            'light' => [LightTheme::definition(), new Style(background: 254), new Style(background: 252)],
            'high-contrast' => [HighContrastTheme::definition(), new Style(background: 236), new Style(background: 238)],
            'monochrome' => [MonochromeTheme::definition(), new Style(), new Style()],
        ];
        foreach ($expected as $name => [$def, $base, $focus]) {
            $rules = $def->rules();
            $this->assertEquals($base, $rules['.card::selected'], "{$name}: .card::selected");
            $this->assertEquals($focus, $rules['.card::selected:focus'], "{$name}: .card::selected:focus");
        }
    }

    public function testModalShadowRuleExistsInEveryTheme(): void
    {
        // The modals' drop shadow, resolved via Styles::rule() rather than a
        // Role — totality across themes for the same reason as the .card
        // rules: StyleSheetThemer (and the rule() accessor) can only read
        // what a theme defines, and a switch must never leave a stale shade.
        $expected = [
            'dark' => [DarkTheme::definition(), new Style(background: 233)],
            'light' => [LightTheme::definition(), new Style(background: 250)],
            'high-contrast' => [HighContrastTheme::definition(), new Style(background: 232)],
            'monochrome' => [MonochromeTheme::definition(), new Style()],
        ];
        foreach ($expected as $name => [$def, $style]) {
            $this->assertEquals($style, $def->rule('.modal::shadow'), "{$name}: .modal::shadow");
        }
    }

    /** An unknown selector resolves to a plain style, never an error. */
    public function testAnUnknownRuleResolvesPlain(): void
    {
        $this->assertEquals(new Style(), DarkTheme::definition()->rule('.no-such::rule'));
        $this->assertEquals(new Style(background: 233), Styles::dark()->rule('.modal::shadow'), 'Styles delegates to the active definition');
    }

    public function testHasColor(): void
    {
        $this->assertTrue(DarkTheme::definition()->hasColor());
        $this->assertTrue(LightTheme::definition()->hasColor());
        $this->assertTrue(HighContrastTheme::definition()->hasColor());
        $this->assertFalse(MonochromeTheme::definition()->hasColor());
    }
}
