<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Ui\ColorPalette;
use Porta\Ui\MonochromePalette;
use Porta\Ui\Role;

final class PaletteTest extends TestCase
{
    /**
     * Locks the dark palette to the exact SGR codes the widgets hardcoded before
     * the migration — the guardrail against a visual regression.
     *
     * @return iterable<string, array{Role, string}>
     */
    public static function darkRoles(): iterable
    {
        yield 'gutter' => [Role::Gutter, "\x1b[38;5;240m"];
        yield 'added' => [Role::Added, "\x1b[38;5;114m"];
        yield 'deleted' => [Role::Deleted, "\x1b[38;5;203m"];
        yield 'hunk' => [Role::Hunk, "\x1b[38;5;75m"];
        yield 'add-emphasis-bg' => [Role::AddEmphasisBg, "\x1b[48;5;22m"];
        yield 'del-emphasis-bg' => [Role::DelEmphasisBg, "\x1b[48;5;88m"];
        yield 'selected-row' => [Role::SelectedRow, "\x1b[48;5;31m\x1b[38;5;231m\x1b[1m"];
        yield 'selected-row-unfocused' => [Role::SelectedRowUnfocused, "\x1b[48;5;237m"];
        yield 'row-highlight-bg' => [Role::RowHighlightBg, "\x1b[48;5;236m"];
        yield 'popup-row' => [Role::PopupRow, "\x1b[48;5;236m\x1b[38;5;250m"];
        yield 'header-bar' => [Role::HeaderBar, "\x1b[48;5;237m"];
        yield 'toolbar' => [Role::Toolbar, "\x1b[48;5;235m"];
        yield 'tag-off-bg' => [Role::TagOffBg, "\x1b[48;5;238m"];
        yield 'modified' => [Role::Modified, "\x1b[38;5;179m"];
        yield 'danger-bg' => [Role::DangerBg, "\x1b[48;5;88m"];
        yield 'dim' => [Role::Dim, "\x1b[38;5;245m"];
        yield 'muted' => [Role::Muted, "\x1b[38;5;250m"];
        yield 'bright' => [Role::Bright, "\x1b[38;5;231m"];
        yield 'diag-error' => [Role::DiagError, "\x1b[38;5;203m"];
        yield 'diag-warn' => [Role::DiagWarn, "\x1b[38;5;179m"];
        yield 'diag-info' => [Role::DiagInfo, "\x1b[38;5;75m"];
        yield 'diag-hint' => [Role::DiagHint, "\x1b[38;5;244m"];
        yield 'diag-error-dim' => [Role::DiagErrorDim, "\x1b[38;5;131m"];
        yield 'diag-warn-dim' => [Role::DiagWarnDim, "\x1b[38;5;101m"];
        yield 'diag-info-dim' => [Role::DiagInfoDim, "\x1b[38;5;67m"];
        yield 'diag-hint-dim' => [Role::DiagHintDim, "\x1b[38;5;240m"];
        yield 'diag-error-ul' => [Role::DiagErrorUnderline, "\x1b[58;5;203m"];
        yield 'diag-warn-ul' => [Role::DiagWarnUnderline, "\x1b[58;5;179m"];
        yield 'diag-info-ul' => [Role::DiagInfoUnderline, "\x1b[58;5;75m"];
        yield 'diag-hint-ul' => [Role::DiagHintUnderline, "\x1b[58;5;244m"];
        yield 'current-line-number' => [Role::CurrentLineNumber, "\x1b[38;5;252m"];
        yield 'placeholder' => [Role::Placeholder, "\x1b[38;5;238m"];
        yield 'inlay' => [Role::Inlay, "\x1b[38;5;243m"];
        yield 'indent-guide' => [Role::IndentGuide, "\x1b[38;5;238m"];
        yield 'git-added' => [Role::GitAdded, "\x1b[38;5;114m"];
        yield 'git-modified' => [Role::GitModified, "\x1b[38;5;75m"];
        yield 'git-deleted' => [Role::GitDeleted, "\x1b[38;5;203m"];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, "\x1b[48;5;238m"];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, "\x1b[38;5;223m"];
        yield 'completion-hint' => [Role::CompletionHint, "\x1b[38;5;244m"];
        yield 'completion-kind' => [Role::CompletionKind, "\x1b[38;5;180m"];
        yield 'selection-bg' => [Role::SelectionBg, "\x1b[48;5;24m"];
        yield 'search-bg' => [Role::SearchBg, "\x1b[48;5;58m"];
        yield 'search-current-bg' => [Role::SearchCurrentBg, "\x1b[48;5;130m"];
        yield 'occurrence-bg' => [Role::OccurrenceBg, "\x1b[48;5;23m"];
        yield 'tab-accent' => [Role::TabAccent, "\x1b[38;5;110m"];
        yield 'menu-active-bg' => [Role::MenuActiveBg, "\x1b[48;5;240m"];
        yield 'faint' => [Role::Faint, "\x1b[38;5;242m"];
        yield 'subtle' => [Role::Subtle, "\x1b[38;5;246m"];
        yield 'emphasis' => [Role::Emphasis, "\x1b[38;5;255m"];
        yield 'status-branch' => [Role::StatusBranch, "\x1b[38;5;114m"];
        yield 'status-debug' => [Role::StatusDebug, "\x1b[38;5;203m"];
        yield 'status-error' => [Role::StatusError, "\x1b[38;5;203m"];
        yield 'status-warning' => [Role::StatusWarning, "\x1b[38;5;179m"];
    }

    #[DataProvider('darkRoles')]
    public function testDarkPaletteReproducesHistoricalCodes(Role $role, string $expected): void
    {
        $this->assertSame($expected, ColorPalette::dark()->of($role));
    }

    /**
     * Locks the light palette to its chosen SGR codes — the dark-on-light
     * counterpart selected when the `theme` setting is `light`.
     *
     * @return iterable<string, array{Role, string}>
     */
    public static function lightRoles(): iterable
    {
        yield 'gutter' => [Role::Gutter, "\x1b[38;5;245m"];
        yield 'added' => [Role::Added, "\x1b[38;5;28m"];
        yield 'deleted' => [Role::Deleted, "\x1b[38;5;160m"];
        yield 'hunk' => [Role::Hunk, "\x1b[38;5;25m"];
        yield 'add-emphasis-bg' => [Role::AddEmphasisBg, "\x1b[48;5;194m"];
        yield 'del-emphasis-bg' => [Role::DelEmphasisBg, "\x1b[48;5;224m"];
        yield 'selected-row' => [Role::SelectedRow, "\x1b[48;5;111m\x1b[38;5;16m\x1b[1m"];
        yield 'selected-row-unfocused' => [Role::SelectedRowUnfocused, "\x1b[48;5;253m"];
        yield 'row-highlight-bg' => [Role::RowHighlightBg, "\x1b[48;5;253m"];
        yield 'popup-row' => [Role::PopupRow, "\x1b[48;5;253m\x1b[38;5;240m"];
        yield 'header-bar' => [Role::HeaderBar, "\x1b[48;5;253m"];
        yield 'toolbar' => [Role::Toolbar, "\x1b[48;5;254m"];
        yield 'tag-off-bg' => [Role::TagOffBg, "\x1b[48;5;252m"];
        yield 'modified' => [Role::Modified, "\x1b[38;5;130m"];
        yield 'danger-bg' => [Role::DangerBg, "\x1b[48;5;224m"];
        yield 'dim' => [Role::Dim, "\x1b[38;5;244m"];
        yield 'muted' => [Role::Muted, "\x1b[38;5;240m"];
        yield 'bright' => [Role::Bright, "\x1b[38;5;16m"];
        yield 'diag-error' => [Role::DiagError, "\x1b[38;5;160m"];
        yield 'diag-warn' => [Role::DiagWarn, "\x1b[38;5;130m"];
        yield 'diag-info' => [Role::DiagInfo, "\x1b[38;5;25m"];
        yield 'diag-hint' => [Role::DiagHint, "\x1b[38;5;245m"];
        yield 'diag-error-dim' => [Role::DiagErrorDim, "\x1b[38;5;124m"];
        yield 'diag-warn-dim' => [Role::DiagWarnDim, "\x1b[38;5;136m"];
        yield 'diag-info-dim' => [Role::DiagInfoDim, "\x1b[38;5;67m"];
        yield 'diag-hint-dim' => [Role::DiagHintDim, "\x1b[38;5;240m"];
        yield 'diag-error-ul' => [Role::DiagErrorUnderline, "\x1b[58;5;160m"];
        yield 'diag-warn-ul' => [Role::DiagWarnUnderline, "\x1b[58;5;130m"];
        yield 'diag-info-ul' => [Role::DiagInfoUnderline, "\x1b[58;5;25m"];
        yield 'diag-hint-ul' => [Role::DiagHintUnderline, "\x1b[58;5;245m"];
        yield 'current-line-number' => [Role::CurrentLineNumber, "\x1b[38;5;240m"];
        yield 'placeholder' => [Role::Placeholder, "\x1b[38;5;250m"];
        yield 'inlay' => [Role::Inlay, "\x1b[38;5;245m"];
        yield 'indent-guide' => [Role::IndentGuide, "\x1b[38;5;250m"];
        yield 'git-added' => [Role::GitAdded, "\x1b[38;5;28m"];
        yield 'git-modified' => [Role::GitModified, "\x1b[38;5;25m"];
        yield 'git-deleted' => [Role::GitDeleted, "\x1b[38;5;160m"];
        yield 'inlay-popup-bg' => [Role::InlayPopupBg, "\x1b[48;5;254m"];
        yield 'inlay-popup-fg' => [Role::InlayPopupFg, "\x1b[38;5;240m"];
        yield 'completion-hint' => [Role::CompletionHint, "\x1b[38;5;244m"];
        yield 'completion-kind' => [Role::CompletionKind, "\x1b[38;5;97m"];
        yield 'selection-bg' => [Role::SelectionBg, "\x1b[48;5;153m"];
        yield 'search-bg' => [Role::SearchBg, "\x1b[48;5;229m"];
        yield 'search-current-bg' => [Role::SearchCurrentBg, "\x1b[48;5;215m"];
        yield 'occurrence-bg' => [Role::OccurrenceBg, "\x1b[48;5;194m"];
        yield 'tab-accent' => [Role::TabAccent, "\x1b[38;5;25m"];
        yield 'menu-active-bg' => [Role::MenuActiveBg, "\x1b[48;5;252m"];
        yield 'faint' => [Role::Faint, "\x1b[38;5;245m"];
        yield 'subtle' => [Role::Subtle, "\x1b[38;5;246m"];
        yield 'emphasis' => [Role::Emphasis, "\x1b[38;5;16m"];
        yield 'status-branch' => [Role::StatusBranch, "\x1b[38;5;28m"];
        yield 'status-debug' => [Role::StatusDebug, "\x1b[38;5;160m"];
        yield 'status-error' => [Role::StatusError, "\x1b[38;5;160m"];
        yield 'status-warning' => [Role::StatusWarning, "\x1b[38;5;130m"];
    }

    #[DataProvider('lightRoles')]
    public function testLightPaletteMapsEveryRole(Role $role, string $expected): void
    {
        $this->assertSame($expected, ColorPalette::light()->of($role));
    }

    public function testLightCoversEveryRoleDarkDefines(): void
    {
        $light = ColorPalette::light();
        foreach (Role::cases() as $role) {
            $this->assertNotSame('', $light->of($role), $role->name.' must have a light color');
        }
    }

    public function testHighContrastCoversEveryRole(): void
    {
        $hc = ColorPalette::highContrast();
        foreach (Role::cases() as $role) {
            $this->assertNotSame('', $hc->of($role), $role->name.' must have a high-contrast color');
        }
    }

    public function testHighContrastKeyPairings(): void
    {
        // Spot-locks for the accessibility-critical picks; the full table is free
        // to be tuned as long as every pairing stays at WCAG-AAA contrast.
        $hc = ColorPalette::highContrast();
        $this->assertSame("\x1b[48;5;19m\x1b[38;5;231m\x1b[1m", $hc->of(Role::SelectedRow), 'white-on-deep-blue selection, bold');
        $this->assertSame("\x1b[38;5;231m", $hc->of(Role::Bright), 'near-white base text');
        $this->assertSame("\x1b[38;5;226m", $hc->of(Role::DiagWarn), 'bright yellow warnings');
        $this->assertSame($hc->of(Role::DiagError), $hc->of(Role::DiagErrorDim), 'no dim tier in high contrast');
        $this->assertSame($hc->of(Role::DiagInfo), $hc->of(Role::DiagInfoDim), 'no dim tier in high contrast');
    }

    public function testDarkResets(): void
    {
        $dark = ColorPalette::dark();
        $this->assertSame("\x1b[39m", $dark->fgReset());
        $this->assertSame("\x1b[0m", $dark->reset());
    }

    public function testMonochromeEmitsNoColorForEveryRole(): void
    {
        // The selection roles are the deliberate exception: without color, the
        // selected row would otherwise be invisible, so they map to reverse video.
        $mono = new MonochromePalette();
        foreach (Role::cases() as $role) {
            if (Role::SelectedRow === $role || Role::SelectedRowUnfocused === $role) {
                continue;
            }
            $this->assertSame('', $mono->of($role), $role->name.' must emit no color');
        }
    }

    public function testMonochromeSelectionUsesReverseVideo(): void
    {
        $mono = new MonochromePalette();
        $this->assertSame("\x1b[7m", $mono->of(Role::SelectedRow));
        $this->assertSame("\x1b[2;7m", $mono->of(Role::SelectedRowUnfocused));
    }

    public function testMonochromeResets(): void
    {
        $mono = new MonochromePalette();
        $this->assertSame('', $mono->fgReset(), 'no fg reset when nothing was colored');
        $this->assertSame("\x1b[0m", $mono->reset(), 'attribute reset stays to close bold/reverse');
    }

    public function testHasColor(): void
    {
        $this->assertTrue(ColorPalette::dark()->hasColor());
        $this->assertTrue(ColorPalette::light()->hasColor());
        $this->assertFalse(new MonochromePalette()->hasColor());
    }
}
