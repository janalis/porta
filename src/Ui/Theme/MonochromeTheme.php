<?php

declare(strict_types=1);

namespace Porta\Ui\Theme;

use Porta\Ui\Role;
use Symfony\Component\Tui\Style\Style;

/**
 * The `--no-ansi` theme: every role resolves to the plain {@see Style}
 * (whose apply() is the identity), so the IDE renders without any SGR color
 * while text, layout and structural ANSI stay intact. Selection is the one
 * state that must survive without color, so the selected row maps to reverse
 * video (dimmed while its pane is unfocused) — and the active tab of a strip
 * gets the same reverse treatment, or it would be indistinguishable.
 *
 * Character-level selection and search follow the same logic one layer down:
 * text {@see Role::SelectionBg} is reverse (like the row selection), and the
 * search/occurrence highlights are underline (the current match adds bold at
 * its call site). Their spans close through {@see Sgr::resetFor()}, which
 * cancels reverse/underline here as it cancels the background elsewhere.
 */
final class MonochromeTheme
{
    public static function definition(): ThemeDefinition
    {
        return new ThemeDefinition(
            rules: [
                Role::SelectedRow->selector() => new Style(reverse: true),
                Role::SelectedRowUnfocused->selector() => new Style(dim: true, reverse: true),
                Role::TabActiveBg->selector() => new Style(reverse: true),
                // Selection is reverse; hover is underline — a distinct mark, so
                // the tint-only hover the widgets build still reads with no
                // colour (where these roles would otherwise resolve to plain).
                // Selection wins where a row is both: render precedence puts the
                // reverse-video selection ahead of the underline.
                Role::RowHighlightBg->selector() => new Style(underline: true),
                Role::DropTargetRow->selector() => new Style(reverse: true, bold: true),
                // A placeholder must not read as a typed value; Dim is plain
                // here, so the placeholder carries its own dim + italic.
                Role::FieldPlaceholder->selector() => new Style(dim: true, italic: true),
                Role::BarHoverBg->selector() => new Style(underline: true),
                // Character selection is reverse (like the row selection); search
                // and occurrence highlights are underline. Without these, selecting
                // text or finding matches would show nothing under --no-ansi.
                Role::SelectionBg->selector() => new Style(reverse: true),
                Role::SearchBg->selector() => new Style(underline: true),
                // The current hit is reversed, as a selection is: the one Enter
                // and F3 are about stands out from the underlined rest.
                Role::SearchCurrentBg->selector() => new Style(reverse: true, bold: true),
                Role::OccurrenceBg->selector() => new Style(underline: true),
                Role::OccurrenceWriteBg->selector() => new Style(underline: true, bold: true),
                // Agents: no colours to tell them apart, so every one is the reversed,
                // underlined cell — still a caret that is not the user's.
                Role::Agent1->selector() => new Style(reverse: true, underline: true),
                Role::Agent2->selector() => new Style(reverse: true, underline: true),
                Role::Agent3->selector() => new Style(reverse: true, underline: true),
                Role::Agent4->selector() => new Style(reverse: true, underline: true),
                Role::Agent5->selector() => new Style(reverse: true, underline: true),
                Role::Agent6->selector() => new Style(reverse: true, underline: true),
                Role::Agent7->selector() => new Style(reverse: true, underline: true),
                Role::Agent8->selector() => new Style(reverse: true, underline: true),
                // The :focus variant merges over the base rule, so it must
                // cancel the unfocused dim explicitly.
                '.list::selected' => new Style(dim: true, reverse: true),
                '.list::selected:focus' => new Style(dim: false, reverse: true),
                // Card selection carries no attributes here: the accent bar
                // glyph (plus its bold) is the selection, per the rule that no
                // meaning may rest on colour alone. The rules still exist so a
                // live switch from a colour theme overwrites its tint.
                '.card::selected' => new Style(),
                '.card::selected:focus' => new Style(),
                // Modal drop shadow: plain — the shadow cells still overwrite
                // (hide) the content beneath, which is the depth cue that
                // survives without colour.
                '.modal::shadow' => new Style(),
            ],
            underlines: [],
            hasColor: false,
        );
    }
}
