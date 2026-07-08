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
        yield 'selected-row' => [Role::SelectedRow, "\x1b[48;5;31m\x1b[38;5;231m"];
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
    }

    #[DataProvider('darkRoles')]
    public function testDarkPaletteReproducesHistoricalCodes(Role $role, string $expected): void
    {
        $this->assertSame($expected, ColorPalette::dark()->of($role));
    }

    public function testDarkResets(): void
    {
        $dark = ColorPalette::dark();
        $this->assertSame("\x1b[39m", $dark->fgReset());
        $this->assertSame("\x1b[0m", $dark->reset());
    }

    public function testMonochromeEmitsNoColorForEveryRole(): void
    {
        $mono = new MonochromePalette();
        foreach (Role::cases() as $role) {
            $this->assertSame('', $mono->of($role), $role->name.' must emit no color');
        }
    }

    public function testMonochromeResets(): void
    {
        $mono = new MonochromePalette();
        $this->assertSame('', $mono->fgReset(), 'no fg reset when nothing was colored');
        $this->assertSame("\x1b[0m", $mono->reset(), 'attribute reset stays to close bold/reverse');
    }
}
