# How Porta looks

The rules every widget's chrome follows: form controls, toasts, rules, tab strips,
the status bar, workspace edges, list selection, and how a floating box measures
itself. Read it before changing how anything is drawn. Themes and the `Role`
vocabulary are in [Subsystems](subsystems.md); how overlays are composited is its
"Floating surfaces" section.

## Form controls: one renderer, the state in the border's shape

Every control is drawn by `Widget/Render/FieldBox` the way its HTML
counterpart looks — a text field (`render()`, the legend in the top border:
`╭ Find ───╮`), a push button (`button()`, caption centred), a select
(`select()`, the value then `▾`), and any other content through `control()`.
Each comes boxed (`FieldBox::ROWS`) or in the one-row form (`Find ┃ foo
┃`). `TextField` stays the value-and-caret model; label, placeholder and hint
are presentation and live in `FieldSpec`. A checkbox where the form is boxed
(settings Bool rows, the commit box's amend) is `FieldBox::checkboxBox()`, a box
of its own like its neighbours: `╭───╮ │ ✓ │ ╰───╯`, its mark padded a column
either side as every box pads its content, five columns wide
(`FieldBox::CHECKBOX_BOX_WIDTH`) and `FieldBox::ROWS` tall, the state's frame (heavy while it takes the keys, dashed
disabled), its label on the middle row. The commit box's amend follows the one
rule below, with its summary and button, so the changes list keeps room. The one-row `FieldBox::checkbox()` is
the fallback where the form is not boxed, and the form list rows use (git
staging, the Find-in-Files preview, the debugger checklist): `[✓]` / `[ ]` /
`[–]` at rest, the state's own rails otherwise (`FieldState::checkboxRails()`),
heavy `┃✓┃` or dashed `┆ ┆`. In a list row its focus is the row's selection bar
instead. A checked mark takes the accent (`$tint`, `Role::TabAccent`), a
browser's filled box, but only off the selected row: accent on the selection's
shade is too faint to read, so the callers pass the tint only there. A settings
checkbox answers Space and Enter; the arrows and brackets that step its
neighbours are inert on it. The Welcome steps are not checkboxes: they are
run, not ticked, so they wear plain status marks (`✓` / `○` / `→`). A list
overlay whose items carry `checked` is a checklist (the debugger's exception
filters): `PickerLayout` draws the box before the label, the filter matches
the label alone, and Space picks, which toggles, instead of typing into the
filter. A command registered with a `checked` state is a toggle, and its menu
item is a checkbox item: `MenuPanel` gives every row of that menu a two-column
gutter and draws `✓` in it while the state is on. The state is read live, so
nothing moves as a toggle flips. Where a box is its own click target apart from
its row (git staging, the Find-in-Files preview), it underlines under the
pointer. The git changes list heads its rows with a select-all box whose mixed
state stages (`GitStageAllRequested`). A checkbox acts on the first click, on its label as on its box. Where
the row's text is a link of its own (the git changes list, the Find preview)
only the box and a column either side of it toggle.

- **The shape is the state** (`FieldState`): rounded idle, heavy while it takes
  the keys, double for a destructive answer, dashed (`╭┄╮`) disabled. Colour
  only repeats it, so every state reads under the monochrome theme and
  `--no-ansi`. Borders are never bold — a bold rail's SGR 22 would unbold the
  selected row a control sits in. Hover underlines a button's caption and never
  touches its border: hovering is not a state change.
- **One focused field = one caret marker.** A field with the caret shown must
  take keys; `FieldBox` throws otherwise, so a second marker in a frame fails
  loudly instead of mangling ScreenWriter's cursor conversion. The list filter
  is the one focused field drawn without a caret (the search modal owns it).
- **A select's list is the context menu** in its listbox mode, opened by
  `Workbench/Menu/ListboxOpener` under the control (`openUnder()` for a widget,
  `openAt()` in screen space for a floating modal): modal keys, click-to-pick,
  outside-dismiss, hover, wheel, the flip above a control near the bottom edge
  and right-to-left layout all come with it, and the heavy frame says it holds
  the keys. A pick runs the host's own change path, so it and a ←/→ step are
  one write.
- **Items taller than a row need a row map.** The settings form (fieldset
  legends, three-row controls), the git changes list (an armed discard is a
  block) and the Welcome cards record which item each drawn row belongs to and
  page by `ListSelection::followHeights()` / `countFitting()`; every click,
  hover and page goes through that map, never list arithmetic.
- **The one-row form is the fallback, not a style, and it has one rule**:
  `FieldBox::rowsFor($density, $width)` — boxed where the screen is
  `Density::Roomy` (30 terminal rows and up, `Ui/Density`) and the box has
  `FieldBox::MIN_BOXED` columns, one row otherwise. No host keeps a threshold
  of its own: the settings form, Find in Files, the find bar (whose compact
  form keeps Find and Replace side by side on its one row), the pickers'
  fields, the Results and keybindings filters and the commit form all ask it.
  The Welcome cards' buttons too: a box beside the title and detail on a roomy
  screen, the card growing to its rows, and one row on a compact one, where a
  box on each card would halve the steps the modal shows.
- **An option toggle is a control too** — `.*`, `Aa`, the diff's `S split`:
  `FieldBox::toggle()` at the height the host drew its fields. Boxed, its
  frame is always the idle one (a toggle never takes the keys; its shortcut
  flips it) and the caption carries the state, on in the selection's shade and
  off faint; one row, it is the tag, `[.*]` on and ` .* ` off. Either way it is
  its whole box to the pointer: the find bar, Find in Files' options band and
  the diff toolbar hit-test the band, not a row. The find bar's ↑ ↓ × are boxed
  `FieldBox::button()`s beside boxed toggles; a pane too narrow for both keeps
  boxed fields with one-row tags on their value row. Its key hints centre on
  the boxes past the buttons: one line on the middle row, two on the top and
  bottom, three on all of them; beside one-row tags the middle row is theirs,
  so the hints keep above and below. The diff toolbar's title rides the middle
  row of its boxed toggles, and every body row counts the header's height.
- The screen's density is the terminal's, never a pane's: `Ui/ScreenDensity`
  is the live holder, updated before each frame by `Layout/ChromeHeight`, which
  clears every chrome cache on a flip. A contributed view gets it through
  `ViewServices::$density`, since its widgets see only their region's height.
- A box adds `FieldBox::ROWS - 1` rows to its host; every host derives its
  header rows and click bands from the height it drew, never a literal.

## Inlay hints and the problem card

An inlay hint drawn into the code is a pill: its label on `Role::InlayParameter`
or `Role::InlayType`, a shade no row, selection, search or occurrence wears
(`ThemeLockTest` holds that). Its margins stay outside the shade, so the pill
sits beside the code rather than swallowing the space next to it. Under the
monochrome theme it is faint and slanted. A selection or underline running
through a hint stops at it and resumes after it.

The problem card is a tooltip card with a `TooltipBody`. Each problem is its
severity glyph in the severity's colour, then the message wrapped under its
own first word, then where it came from, faint. The `Fix:` and
`More actions…` lines come last, keys at the line's end. The card may widen
once, when the fix arrives. The same body heads the documentation popup,
parted from the docs by a tee.

## Toasts: severity by shape, held while read

A toast's frame says its severity by shape: double (`FieldState::Danger`) for
an error, heavy for a warning, rounded for the rest, so a failure never looks
like a success in monochrome. A hovered `×` is `TabCloseGlyph::hovered()`,
underlined as well as bright, on a toast as on a tab. A window's close button
is its own (below).

Pointing at a card stops its timer (`ToastCenter::hoverAt`, measured on an
injectable clock) and moving away restarts it with the time it had left, never
less than a second. `Alt+D` (`ide_dismiss_toast`) removes the top card, as its
`×` would.

Every hint set into a bottom border (a field's trailing hint, a clipped list's
`3/40`, a card's) is `BorderHint::bottom()`: faint, a stroke on each side, left
out whole when it cannot keep both. `ToastPresenter` writes a card's from the
live keymap:
- an error card gets `Alt+9 log`;
- when the stack does not fit, the last card that does gets `+N more · Alt+9`.

Every entry in the notification log carries its glyph, Info's `◦` included.

## Windows: one frame, the platform's close button

Every floating window — a picker, Find in Files, Welcome, a view window —
wears `ModalFrame`'s frame and moves and resizes through `WindowFrame`:

- **The title bar is the platform's.** On macOS a red `⊗` leads it and the
  title sits in the middle (`╭⊗─── Title ───╮`); elsewhere the title leads and
  a `☒` ends it (`╭─ Title ────☒╮`). The rule runs unbroken into both corners
  and up to the button — a space beside either reads as a break. Both glyphs
  are East Asian Neutral, one column in every terminal (`GlyphsWidthTest`); no
  window spells Esc out, every one closes on it.
- **The button darkens under the pointer**, to the theme's deep red with no
  fill; a theme with one red, and monochrome, dim it instead.
- **Every edge but the title bar's is a handle.** At rest it is the frame;
  under the pointer, or dragged, it draws heavy and bright (`MODAL_GRAB_*`).
- **Sizes.** A view window opens centred at 80% of the workspace; every other
  window at its content's size, a third of the way down. A move or resize
  lasts until the window closes.

## Rules: one tint, one helper

A plain line that separates is `Widget/Render/Rule` in `Role::Rule`: the pane
divider, the tab and dock baselines, a resting tab's outline, the status bar's
` │ `, a menu's separator row, a modal's section tee, and a group's legend
(`─ Editor ────`). One tint, so a line reads as a line wherever it runs. A
line that is a grab or a focus keeps its own face: the hovered divider's `┃`
and the dock grab's `━` in the accent, a focused frame's heavy rails.

A box with zones parts them with a tee (`ModalFrame::wrapSections`): Find in
Files' fields and preview, a picker's filter or query and its list. The Welcome
cards are parted by a rule, the last one by a blank.

`Rule::horizontal($width, $styles, $legend, $tees)` is exactly `$width`
columns. A legend sits after the first stroke only while a stroke still follows
it, and `$tees` puts `├` `┤` in that role at the ends, joining the rule to the
rails it cuts across; the legend yields before a tee does. A heavy menu frame
keeps its own `┣━┫` (`PopupFrame::tee`): a light run cannot meet a heavy tee.

A popup is one solid panel: `PopupFrame` draws its borders, tees and the pad
past a short row on its panel's shade, the accent over it, so a menu, the
completion list or a tooltip never shows the terminal's background between its
border and its rows. The panel is `PopupRow`, except a menu's: the app menu's
dropdown and the context menu wear `HeaderBar` (`MenuPanel::PANEL`), so a
dropdown reads as the bar it drops from.

## Tab strips: pills from 30 rows

Every horizontal strip is `TabStripRenderer::render()`. At
`TabStripRenderer::ROWS` (2) rows each tab is a pill: its label row shaded as a
file tree row is (`TabPillStyle` — the shown tab in the list selection, blue
while its pane has the keys and grey otherwise, the hovered one on the rows'
hover tint, the rest on nothing), standing on the `─` baseline, which holds
the pill's lower half (`RowBleed`) and runs on beside it — mirrored at a bottom
edge (`StripEdge`), where the baseline is on top. The pill's other half is the
frame's: `Overlay/Presenter/RuleHalves`, a pass after `FrameJoins`, turns each
`─` of the workspace rule touching a shaded cell of the strip's edge row into
that cell's `▄` or `▀` — or a blank on nothing, such as the padding over a view
in a window, on the same surface as the row. Where the edge row holds a bar's
half instead (see the workspace edges), the cell keeps the bar's half and takes
the pill's as the other, on the bar's colour. The dock's strip is its own top edge (its resize grab
is a rule), so it is `EDGED_ROWS` (3) tall and holds both halves itself.
Pills stand side by side with no margin, so two shaded ones are one run of
shade. Beside a shaded tab, a pane divider opens (`RuleHalves::openings`): its
cell on the tab's row becomes a blank of the tab's style and the join over and
under it the tab's halves — the tree's divider beside the first tab of a top
strip, or beside any row of a side strip; between two shaded tabs the cell
takes both halves, as the strip's own gap does. A tab's tooltip anchors on its
whole pill, the rows of its halves included, and asks for the side away from
the screen edge the strip sits on (`Tooltip::$side`): under a top strip, over a
bottom one, beside a side one.
`TabStripTail` runs the baseline on past the last tab. On a `Density::Compact`
screen (below 30 terminal rows) a strip is one row on the toolbar shade, each
cell closed by a divider.

The editor strip at the side (`TabBarWidget::renderVertical`) is spaced like
the file tree on a roomy screen: a tab is one row, a blank gap between every
two holding the halves of the shaded tabs beside it, the pill out to the outer
edge; the inner edge is a rule down the strip, which `RuleHalves` joins into
the workspace rules as `┬` `┴` — or ends at a bar's half, which joins nothing —
and the first and last tabs' outer halves land in those edge rows too. Beside a shaded tab — the shown one, the hovered one — the
rule opens: its pill and its halves run across the rule's column, a blank of
the pill's, so the tab and the editor read as one surface while its name and
`×` stay in their columns. A gap owns no tab. The inner edge is a logical end: under
RTL the row reverses, so `WorkspaceLayout::setMirrored` tells the strip its
reading direction along with its seat. A compact screen keeps the one-row list
with its selection bar.

A strip's render context carries its parent's height, not the terminal's, so
`Layout/ChromeHeight` (eager, a frame hook) reads the terminal's rows into the
`ScreenDensity` and pushes the height into the editor and dock strips.
`LayoutController`'s frame arithmetic (`maximisedRows`, `clampPanel`) applies
the same rule to the terminal rows it already has. The git mode tabs are a
plugin widget: they read the density `ViewServices` hands the view.

Hit-testing: a tab is its whole shape (`tabIndexAt` ignores the row on a
horizontal strip, and matches a side tab's three rows), and a `×` is on the
label row alone (`closeIndexAt`, and the renderer lights a `×` only for a
pointer on that row). `‹`/`›` page from any row. The dock's top-row tail is
the resize grab (the dock's top edge, the strip's height above the panel,
which is what `armPanelResize` arms); its status sits level with the labels,
and the baseline under them. The strips' focus
probes: the editor area's widget for the editor strip, the visible panel for
the dock, the sidebar's lists and commit box for git. Each strip repaints in
`beforeRender` when the probed focus differs from what it last drew.

## The status bar: cells, then the file

`StatusBarProvider` returns the contributed cells (each styled by its tone, the
Active tone bold, and a cell with a menu ending in `▾`), the file part as
`left`, and the right segment with its click spans. `StatusBarWidget` lays the
cells out itself, joined by ` │ `: only it knows which fit. It drops cells
whole from the end and elides only the file part, so an elided path moves no
cell, and every cell drawn whole keeps its span, even during a flash (which
takes the left, marked `◦`). The clickable cell under the pointer underlines
(`hoverAt`, from `HoverRouter`). A left click with no action on a cell that
offers a menu opens it: `StatusBarClickRouter::route()` takes the right-click
router's `openStatusMenu` as a callable, so no branch lands in `MouseRouter`,
whose complexity pin is the lint threshold.

A pane holding the keys owns the whole right segment, its spans included:
`EditorPaneWidget::statusClicks()` names them, and the router offers the
action to `runStatusAction()` before the registry. The zoom panes find their
`+`, `-` and fit hints by the key leading each two-space-separated hint, so a
translation moves them without breaking them.

The file part is a span too, and it stops at the path: the provider sends
`leftPath` and `leftPathWidth`, so the `●` and a caret diagnostic after the
path name no file. `fileAt()` answers for it. `StatusBarClickRouter` counts
presses there on `MouseRouter`'s own `ClickCounter` (passed in, never a second
counter), and a double-click calls `FileTreeWidget::revealPath()`.
`ContextMenuRouter` gives it its own menu: `tree_select_opened_file`, then the
tree's `copyPathItems()`. The tree's project-name row has one too:
`ProjectSwitcher::siblingItems()`, every folder beside the project (the current
one ticked and greyed), then Open Project…; a pick is `openPath()`'s. That branch stays out of `statusMenuItems()`, which
also serves LEFT clicks.

Where a rule meets a sidebar's divider, the rule turns into it:
`Overlay/Presenter/FrameJoins` runs over the finished frame (installed with
`BidiTui::setFrameJoin()`, after the RTL reorder and before the frame sink) and
turns a `─` above a divider's top into `┬`, one below its end into `┴`, and one
beside it into `├` `┤`, or `┼` from both sides. That covers the edge rules, the
settings groups' legends and the git history's Files heading. Only an idle `│`
joins (the hovered `┃` is a grab), a cell under a floating surface is left
alone, and a mirrored divider is found where it is drawn.

## Workspace edges: the bars' halves

On a roomy screen `Widget/EdgeRuleWidget` gives the workspace an edge row under
the menu bar and one above the status bar, holding that bar's half
(`RowBleed`): `▀` in the `HeaderBar` shade under the menu, `▄` over the status
bar, so each bar reads half a row taller and the panes start where its shade
stops. A theme whose bars have no shade (monochrome) gets a `─` rule there
instead. A divider ends at a bar's half (no `┬`/`┴` join), and a shaded strip
cell beside it takes its own half on the bar's colour (`RuleHalves`), as does
the file tree's last row while it is shaded — the tree's project name is a
bold title on the pane, no bar (`HeaderRenderer::title`, every panel's). On a
compact screen the edge rows draw nothing and take no row. Each is its own widget (`WorkspaceLayout::$topRule`, `$bottomRule`), so
the bars keep their one row, and `MenuOverlayPanes`' chrome list makes them
overlay hosts under every view: the app menu's dropdown hangs from its bar
across the top edge row. They take rows `main` had, so the pane union the modals
lay out against is unchanged. `LayoutController::chromeRows()` counts them with
the strips' lids for the panel ceilings.

## Selection in lists: a bar in the first column

A list row's first column is `Widget/Render/SelectionBar`: `▌` for the cursor
in the list holding the keys, `▎` for the cursor in one that does not, `│` for a
row marked without being the cursor (the tree's second pick), a space
otherwise. The column is the space every row already started with, so no
content moves. The bar is a bare glyph that the row's shading paints: a colour
of its own would reset the foreground the rest of the row needs. Shading still
repeats the state, but the shape is the state, so the monochrome theme and
`--no-ansi` read it (`NavigationReadabilityTest`). Panels using `PanelRowStyle`
call `barred()` before shading; git's history (two sub-lists, one focus) and
the vertical tab strip call `SelectionBar::of()` directly. The two sidebars,
the tree and the settings nav, call `SelectionBar::sidebar()`: their cursor
draws no bar, focused or not — its shade, and on a roomy screen the shade's
halves, mark it — and only the tree's second pick keeps its `│`. Under
`--no-ansi` a sidebar's cursor does not show. The
settings form has none: column 0 is its modified marker, and its selection is
the heavy control. The Welcome cards wear the full bar down every row of the
selected card.

The settings nav is a tree in the file tree's shape: Plugins and Languages are
folders, their rows drawn by `Widget/Render/TreeRow` (shared with the tree, so
a folder looks and opens the same in both) and tinted `TabAccent`. On a roomy
screen both leave a transparent gap — a blank line, not a drawn one — between
every two rows, the file tree under its project name too. A
shaded row (selected, hovered, marked, a drop target) reaches halfway into the
blank line on each side: `Widget/Render/RowBleed` draws `▄` above it and `▀`
below in the row's background as a foreground, one `▀` of both colours between
two shaded rows, and nothing where the theme shades without a background (the
monochrome hover's underline). Only a blank line bleeds; the `▲`/`▼` rows are
controls. Where the first or last row meets a workspace rule instead — the
settings nav's General, under the menu bar's — `RuleHalves` draws the half into
the rule (see the tab strips). The gaps own no row, so a click or hover
on one does nothing, and a window holds half the entries. Its `▲`/`▼` edges
(`EdgeIndicator`, shared with the vertical tab strip) are controls: a click
selects the first entry hidden past that edge. The `▲` row is drawn only once
something is above: at the top of the list its row is the first entry's, never
a blank.

## Trails: the navigation bar and the settings headers

The editor's navigation bar (`BreadcrumbsWidget`) and every settings page's
title row (`Widget/Settings/CrumbHeader`) are one control,
`Widget/Render/CrumbBar`: crumbs as ` label ` pills joined by a dim `›`, shaded
by `BarChromeStyle` — the current crumb lit like the active tab, the hovered one
on the tab hover shade with its label underlined. The pill's padding is part of
its hit zone, as a tab's whole shape is. A trail is laid out from the right:
crumbs drop from the root end behind one ` … ›`, and the current crumb is
middle-elided only when it is alone and still too wide. A right-hand suffix (the
preview toggles, a page's count or key hints) shares the row; in the settings
headers the hint is what gives way, never the trail. A row follows the bar:
under the editor's bar only on a roomy screen (counted by
`LayoutController::chromeRows()`), under a settings header always, in place of
the blank row it had. Under the editor's bar and a markdown page's header
(`MarkdownPageWidget`, the preview and the help viewer) it is the bar's lower
half (`Rule::under`; a rule where the theme has no shade), and each reaches
up into a bar's half over it in its bar's shade, a lit toggle no taller:
the navigation bar under the menu bar's, the preview's header under the
navigation bar's, one cell of both (`RuleHalves::headWidgets`). A head meets
only a bar's half, in a row a bar draws one (`RuleHalves::barRows`): a tab
strip's baseline over it stays the strip's, its pill halves included, and
under an open view's window no head reaches at all. In Split
the divider beside the preview's header keeps its `│` on the header's shade
(`RuleHalves::dividerOnShade`).

## Sizing one: measure every row you draw

A floating box computes its width from its content, and the recurring defect is
that "its content" quietly means *the varying* content — the title, the query,
the item labels — while the box also draws rows that vary with none of it. A row
nothing measured is a row that truncates, and a row-wide truncate spends the
overflow on the TAIL, so what disappears is whatever the row ends with.

Four of these landed on 2026-09-09, all in surfaces that had been correct at
every width anyone had looked at:

| row | sized from | what was lost |
| --- | --- | --- |
| Find modal's option tags | title, query, result rows | `(Alt+S)` → `(Al…` |
| Find modal's footer | as above | latent; French was 5 columns from it |
| Picker's "no matches" filler | title, item labels | `keine Treffer` → `keine Tref` |
| Picker's type-to-filter row | item labels | the end of what you typed |

Three rules, in the order they matter:

1. **A fixed row goes into the width, unconditionally.** If a row's content is
   decided by anything other than the list the box measures, add it to that
   `max()` — including a row that only appears sometimes, like an empty-state
   filler. Counting one *only when it is about to be drawn* looks like the
   frugal choice and is not: the box then resizes on the keystroke that makes
   the row appear, and `PickerLayout::layoutList()` measures the UNFILTERED
   items precisely so it cannot do that. Measured at 15 columns, then 27 on the
   keystroke that emptied the list. A width that is stable and slightly generous
   beats one that moves under the reader's eye.
2. **When something must give, give up the value, never the affordance.** A key
   hint, a glyph, a count is unreconstructable; a label is usually visible
   elsewhere on screen. `MenuPanel::renderItem()` is the reference — it elides
   the label and keeps the shortcut. Truncating with `ellipsis: ''` is worse
   than truncating badly: it hides that anything was cut at all.
3. **A row holding LIVE input scrolls; only fixed text elides.** An
   end-truncated input goes still as you type past the width, and stillness
   reads as "nothing happened". Use `AnsiText::scrollLineToCaret()` when the
   field renders its caret marker, or `caretWindow()` at the text's end when it
   does not (the frame permits one caret and the search modal owns it).

The gate for rule 1 is an invariant, not a number: a row's declared width must
equal the width it draws, over several locales, since the widest row changes
with the translation. See `FindInFilesModalGeometryTest`. Pinning the constants
instead goes stale the first time a glyph changes.

What does NOT gate layout is a **presence** assertion — "the screen contains
this text" — because a box a column too wide still contains it. Nine mutants
escaped every test in `FindInFilesModalTest` for that reason. An **exact**
assertion does gate it: `MarkdownTableTest` pins whole rendered rows, and an
exact row moves the moment the arithmetic does, so the same class of mutant
dies there without any invariant being written. The distinction is presence
versus exactness, not content versus geometry — measured both ways, a day
apart, and the first reading of it was too broad.
