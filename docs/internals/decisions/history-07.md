# Decision history, part 7

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## Every control is boxed; the settings form pages by rendered rows (2026-09-23)

The text-field round left settings text rows one row tall until a form "pages
by rendered rows"; this round built exactly that, and put every control in the
box: buttons (`FieldBox::button()`), selects with a real dropdown
(`FieldBox::select()` + the context menu's listbox mode), steppers with
clickable arrows, and `[✓]` / `[–]` checkboxes. Settings pages group their rows
under fieldset legends (`SettingsRow::$fieldset`).

**The dropdown is the context menu, not a new popup.** It already floats over
every pane, routes keys modally, dismisses on an outside click, hovers, wheels,
flips above a control near the bottom and lays out right-to-left. A popup drawn
inside the host widget (`MenuOverlayHostTrait`) is clipped to its pane and
would have needed all of that again. Re-open only if a list must stay open
while the host keeps its keys. Under a right-to-left frame two mirrors meet:
the control is reordered with its row (a left-to-right control inside a
mirrored pane keeps its own order), while the floating list is its own run,
mirrored across the whole row — so the list is anchored where the control was
DRAWN (`BidiTui::floatingColumn()`: `logicalColumn()` inverted, mirrored back).
Right-click menus had the same defect from before this round — a click on the
tree at the right edge opened the menu at the left — and share the fix. A Hebrew
select value mirrors its whole box as one run, arrow at the left, as a
right-to-left browser draws it; fencing the value with marks split the box,
since `Bidi` has no isolates.

**A label column beside the box, not a legend in its border, on settings.** One
control column for every kind lines the page up; a legend would be cut by the
box's 32-column cap in German and French; and the right-to-left marks are
proven on this row shape. Checkboxes stay one row — `[✓]` aligns with the rail
column, and a box per toggle would cost ten rows on Interface.

**The side column in Find in Files** (Find window button, scope select) takes
at most half the box: a long scope name elides inside its select, keeping its
`▾`, rather than squeezing the query. Alt+S opens the scope list; with no host
to show it — or before the modal is drawn — it steps, so the key never dies.

**The Welcome card's button is one row.** A card is a row of a list; a three-row
box on each would halve the steps the modal shows.

**Disabled gained its callers** (the Built-in plugin entry, the commit button
with nothing to commit) and uses the dashed set the text-field entry recorded.
`Role::DangerBg` went: its two users are double-bordered `FieldState::Danger`
now, and the enum cap is back to 61.

Two right-to-left defects surfaced on the way and are fixed: an Int's `· 1-8`
range joined the control's left-to-right run and was drawn between box and
label, and a label ending in Latin plus punctuation lost its closing
parenthesis to the far side. The heavy and double corners were missing from the
L4 mirror table, so a focused box in a mirrored pane drew its corners facing
out.

## Menus: the frame holds the keys, a disabled item is struck (2026-09-23)

The app dropdown wore the idle frame while it took every key; it and the
context menu are heavy now, the listbox rule applied to both. A disabled item
was drawn with `RowHighlightBg`, which the monochrome theme renders as an
underline, so it read as hovered; it now keeps the popup's background and says
disabled three ways — faint, dim, and a strikethrough on the label alone, the
shortcut column untouched. The open title's `┃File┃` costs no column: the rails
replace its padding, so opening a menu never shifts the titles after it.

## Selection is a bar, not a shade (2026-09-23)

The tree drew its unfocused cursor and its second pick with the same
`SelectedRowUnfocused` shade, so a pair and a parked cursor were a guess. Every
list now says its selection in its first column (`SelectionBar`). The unfocused
cursor is `▎` rather than the planned `▏`: `▎`/`▕` is already a mirror pair,
and `▏`→`▕` would have broken the table's involution. The bar has no colour of
its own — `Style::apply()` re-arms only a background, so a coloured bar reset
every later foreground in the row. The settings form keeps no bar (its column 0
is the modified `●`; the heavy control is its selection).

The keybindings list's overridden shortcut gained the settings rows' `●`. Its
comment used to refuse a marker because the column "would come out of the
label"; it does, and the label is what truncates first anyway, while the
colour alone vanished under the monochrome theme.

The settings nav's `▼` click used to be pinned as selecting nothing, the fix
for a hit test that mistook it for an entry. It is a control now and selects
the first hidden entry on purpose, and the window follows. A step of a few rows
like the wheel's would not move the window from a cursor mid-list.

## A checkbox acts on the first click (2026-09-23)

The settings form's rule is that a first click selects and only a second
actuates. It was written because a stray click toggled a setting or armed a
plugin removal. A settings Bool is now exempt, on its label as on its box, the
way a browser's `<label>` is. A toggle's whole effect is on screen and the next
click undoes it, while the rows the rule protects open an editor or arm
something irreversible. Waiting for a second click made the checkbox read as
dead. Selects and steppers already answered the first click, for the same kind
of reason.

The commit box's amend checkbox became a Tab stop, the third after summary and
description. Space toggles it, and it is disabled (dashed, inert, skipped by
Tab) on a branch with no commit. `GitInfo::hasHead()` runs on each eager refresh
only, not on the poll: a branch gains its first commit through a commit, and a
commit refreshes eagerly.

The debugger's exception filters toggled by reopening their own list and lost
the filter and the row each time. `OverlayHost` remembers, for the duration of
a pick, the list it closed. A pick that reopens a list with the same title gets
the filter back and the picked value selected.

## One horizontal tab strip (2026-09-23)

The editor bar, the dock and the git mode tabs each laid out their own strip.
They share `TabStripRenderer::render()` now. A tab may say `closable: false`,
which draws no `×` and yields a close zone of width 0. Git's modes use it, and
so does a pinned editor tab: pinning is what keeps a tab open, while Ctrl+W and
the tab menu still close it. The git strip had measured with `mb_strwidth` and
truncated after layout, so a click past the pane's edge still switched to a tab
nobody could see; hit zones now exist only for drawn cells. Hover underlines
the name, or the `×` alone when the pointer is on it, so a click's effect shows
before the click. The vertical strip stays hand-laid: it is a list of rows, and
it wears the selection bar.

## The status bar keeps its cells whole (2026-09-23)

The left used to be one string: the cells baked into the path, and one middle
elision over the whole. Any cut moved every span, so the widget dropped all
click zones as soon as anything was elided, and a flash dropped the right
side's zones too. The provider now hands over the cells as a list and the
widget lays them out: cells are dropped whole, only the path elides, and a zone
exists for exactly the cells drawn. A flash takes only the left, so the right
cells keep answering at their new columns. A cut right side keeps only the
spans that end before its ellipsis; the old filter let a span include the
ellipsis column.

## Folder tabs, and where their height comes from (2026-09-23)

A shown tab was told apart by colour alone, and no strip showed whether its
pane had the keys. Folder tabs make both shapes: the lid, and heavy rails for
focus. They cost a row per strip, so they switch on at 30 terminal rows: the
24-row E2e rigs, and anyone on a short terminal, keep the one-row strip and
every line of code. Each tab owns both rails, so a tab is one column wider than
the compact cell's divider.

The height is the TERMINAL's, not the strip's context: a non-flex child is
handed its parent's rows, and the editor column shrinks whenever the dock
opens, so a context-based rule would flip the strip with the dock. Core pushes
the height from a frame hook. The git plugin cannot reach that service (a
plugin class is never a container service), and its sidebar's height is the
terminal's less the menu and status bars. That is the one strip whose rule is
derived.

Deviations from the plan: the lid row's tail on the dock is not a resize grab
(only the label row, one row above the panel, arms one; lighting the lid row
would promise a drag it does not start), and the chrome check is direct
assertions rather than a golden, since the shapes are few and each is asserted
with SGR stripped.

## Access letters are computed, and F10 arms (2026-09-23)

Hand-kept `&File`-style markers in seven catalogues would drift with every
translation. The letters are computed from the labels as rendered instead.
Dealt greedily they ran out in the long Edit menus, where early items took
letters a later one needed, so the deal is followed by a bipartite matching for
whoever it left out. Italian's Edit menu has 21 items spelled with 20 distinct
letters, so one item has none. `AccessLetterCoverageTest` allows exactly that
pigeonhole shortfall.

F10 used to open File's dropdown. It arms the bar now, as on a desktop, and
presses a second time let it go. Global keys run before the focused widget, so
the toggle lives in `WorkbenchActions::openMenu()`, not the bar. The `…` that
marks cut titles used to be inert, pinned by a test ("not a click target"): it
opens the first hidden menu now, which was otherwise reachable only by walking
the arrows past the edge.

### Post-round joins (2026-09-23)

A combinations pass after the chrome round, per the practice that the defects
live in the middle. Three joins had no test, and one hid a defect:

- **A dock drag jumped by the filter box.** A panel is sized by its content
  rows while the drag measured its whole rect, so once the results panel's
  filter became a three-row box, the first motion of any drag grew the panel
  by three. `armPanelResize` now records the panel's chrome rows and the drag
  subtracts them. Found by driving a drag on a folder-tab strip at 40 rows.
- **The vertical tab strip always drew the focused bar.** It now reads the
  same pane-focus probe as the folder lids.
- **Access letters through the RTL reorder** keep their underline on the one
  letter, and the row's shade is re-armed around it (pinned in
  `MenuPanelTest`).
- **The panel ceilings did not count the filter box either.** Maximising
  Problems left the editor one row, not the two its docblock promises, and a
  drag to the top did the same. Both ceilings now take the panel's chrome off:
  measured from the last frame for a maximise, a nudge or a restored
  proportion, and fixed at the press for a drag. A mid-drag measurement read a
  drawn rect that lagged the content rows, and came out negative.

## Checkboxes, round two (2026-09-24)

An audit of every checkbox against a browser `<input type=checkbox>` +
`<label>`:

- **Colour.** A checked mark takes the accent now. The tint goes on the mark, not
  the rails, because the heavy rails already mean focus and a coloured rail would
  blur the two. It is never used on the selected row, where accent on the
  selection shade (Dark 110 on 31, Light 25 on 111) is too faint to read. Under
  the monochrome theme the accent is
  plain, so the shape is still the whole state.
- **Settings keys.** `←`/`→`/`[`/`]` flipped a checkbox, since `actuate()` toggles
  regardless of direction. They are inert on a checkbox now, and Space and Enter
  toggle, as in a browser. Enter stays because the form has no submit it could
  shadow. The footer names Space on a checkbox row.
- **Welcome.** Its `[ ]` read as a box to click, but a step is run, not ticked.
  The marks are plain now: `✓` done (tinted `Added` off the selection), `○` to
  do, `→` a link. They are centred in the three columns the box took, so no wrap
  width moved.
- **The debugger's checklist.** Its `[✓]` was baked into each label, so Space
  typed a filter that matched every row, and `✓` matched the enabled ones. The
  box is a field of the item now (`checked`), drawn by the list, and outside
  what the filter reads. Space is the box's key.
- **Menu toggles showed no state.** Soft-Wrap and its siblings sat in the View
  menu with nothing to say whether they were on. A command can now declare its
  state (`CommandRegistry::add(checked:)`), which the model carries to its menu
  item and the panel draws as `✓`. That is the menu's checkbox item, not a
  `[✓]` box, because a menu row is already the control.
- **Stage all.** The changes list gained the select-all box a browser's
  checkbox table has. A mix stages everything and only a full box unstages, as
  an indeterminate box checks. `git reset --quiet` covers the unborn branch too
  (it empties the index), so the `rm --cached` fallback first written for it
  was dead and went.
- **A box that is its own target answers the pointer.** In the git list and
  the Find-in-Files preview a click on the box toggles, and anywhere else on
  the row selects or jumps, but only the row tinted. The box now underlines,
  the form controls' hover. It gets no Bright, because a foreground reset
  inside a shaded row would cut the row's colour.
- **Diff toggles.** `split` and `hide-ws` said "on" by the selection shade
  alone. On, their padding becomes brackets, the find bar's chip shape, in the
  same width, so no click zone moved.

## Checkboxes are boxes too (2026-09-24), reversing "checkboxes stay one row"

The boxed-controls round kept checkboxes at one row, `[✓]`, on the grounds that
"a box per toggle would cost ten rows on Interface". The user asked for them to
be boxed like every other control, so the form reads as one family of boxes.
Where the form is boxed, a checkbox is now `FieldBox::checkboxBox()`,
`╭─╮ │✓│ ╰─╯`. It is three columns wide, the width `[✓]` had, so the control
column still lines up. The settings pages and the commit box's amend use it.
The cost is the one recorded before: two rows per toggle, which the drawn-row
paging already absorbs. A narrow settings pane still falls back to `[✓]`, as
every control falls back to one row. List rows (git staging, the Find-in-Files
preview, the debugger checklist) keep `[✓]`, because a box per row would triple
each list. At rest a boxed checkbox has rails (`│`) where the one-row form has
brackets: the brackets were standing in for a box, and the box is drawn now.
Hover underlines the amend label and no longer touches the box, the form
controls' rule, so the mark keeps its accent under the pointer.
- **The amend box on a short terminal.** Boxed at every height, it left the
  git changes list two entries at 20 rows, where the one-row form left four.
  It follows Find in Files' rule now: boxed from 30 terminal rows, one row
  below. The commit box sees only its sidebar's height, so the threshold is 28,
  the terminal's 30 less the menu and status bars (the git tabs' reckoning).
- **The boxed checkbox is padded.** First drawn compact (`╭─╮ │✓│ ╰─╯`, the
  width `[✓]` had), it is `╭───╮ │ ✓ │ ╰───╯` now at the user's request. Every
  other box pads its content a column either side, and the compact box read
  cramped beside them. The control column still starts at the same column;
  only the one-row `[✓]` fallback and list rows keep three columns.

## UI round, phase 1: feedback that says what happened (2026-09-24)

An audit of every toast found three defects:
- **Git's "Fetching…" lingered for ten minutes.** It was shown for 600 s so that it
  outlived the operation, and the outcome was stacked beside it.
- **About twenty failures were sent as Info.** Among them were "Cannot read",
  "Internal error", a failed rename, a refused stage and a tool that would not start.
- **Project search wrote to the status bar directly,** so its messages missed the
  notification log.

The fix for the first is a toast **slot**: a named card that a later notify
replaces in place, keeping its id and position with a fresh timer. It is used by
git's remote operations (including a cancel, which never calls back, and a
follow-up choice, which answers the progress) and by the Settings tool install.
It was chosen over a progress-token object because it is one optional trailing
argument on the notify path that already exists: `PluginApi::notify()` gains it,
and a plugin needs nothing new to hold. "No stashes" and the other "nothing to
do" messages stay Info, because they are answers, not failures. The status bar's
caret readout now shows the worst diagnostic on the line, led by its glyph and
coloured by its severity, where before it showed the first one in the error
colour, whatever its severity.

## UI round, phase 2: pickers behave like dialogs (2026-09-24)

What the audit found:
- **A click inside a picker closed it.** It closed on a header, on the query
  field or on the frame, where only a click outside should.
- **The fields had no caret keys.** They were append-and-backspace only, so a
  rename's typo mid-word meant retyping the word.
- **The command palette and Go to Symbol stopped at five rows.** They showed five
  rows and a "more" row in a box that seats twelve.
- **Go to File stopped at twenty.** It fetched twenty results, exactly its first
  page, so its "more" row never appeared.
- **A slow source moved the selection.** When its answer arrived, the selected
  row index stayed while the rows moved under it, so Enter opened another row.
- **A slow category showed nothing** until it answered.

Changes:
- **Keys.** `TextField::editKey()` holds the caret keys the settings form had
  kept to itself, and every field and prompt now uses it. PgUp and PgDn page the
  list, stopping at the ends. Tab walks the categories.
- **Caps.** The single-category pickers take `SOLO_CAP`, and the file source
  fetches 500 results.
- **Selection.** It follows the hit, identified by its category, label and
  detail, while the query stays the same.
- **Pending state.** A pending category says `◌ Searching…`.
- **Disabled commands.** The palette greys a command whose `when` is false, as
  the menus do.
- **Rows.** A row keeps at least half its width for the label, and the detail is
  cut in the middle, so a path keeps both ends. Recent Files shows the directory
  as its hint, instead of the file name a second time.

**The list filter is shown from the start, which reverses "the filter row
appears only once something is typed".** That rule kept unfiltered lists
unchanged, but its cost was the one this round removes: on the first keystroke
the box grew three rows and re-centred under the reader. A combobox shows its
input, and the placeholder ("Type to filter") says what typing does. The box
height is now a property of the whole list, like its width: narrowing pads the
rows it hides rather than shrinking the box.

**Home and End stay with the field** and do not jump the list. A combobox's
input has the focus, and the caret needs them.

**Deferred, then done:** "No language server" in place of "no results" for Go
to Symbol without a server. The controller did not need to ask the pool. A
source's `$emit` may now carry a note with an empty answer. The symbol sources
answer "No language server" without asking when the pool has no initialized
server, and the note shows under the category header, where "Searching…"
stood.

## UI round, phase 3: pickers say it with shapes (2026-09-24)

A picker's state used to be drawn in colour: the selected row's shade, a dim
category word, and no sign that a list was clipped or why a row had matched.
Each now has a shape, reusing the vocabulary the other lists already speak:
- **The selected row** gets the `▌` bar.
- **A category** is a fieldset legend.
- **A clipped list** shows the tab strip's `▲`/`▼`. The cues sit in the frame's
  existing padding rows, so the box does not grow, and a click on one pages.
  The selected row's place (`3/40`) is set into the bottom border.
- **Matched characters** are bold and underlined. They are computed where the
  row is drawn: a whole-run match first, else the matcher's greedy subsequence.
  That means no source had to start carrying positions. A Files hit that
  matched in its directory rather than its name shows no marks, which is the
  truth about its label.

**The pickers mirror under RTL, which reverses "they stay verbatim".** That
rule treated a picker as code, because it carries paths. But a path is a Latin
run and keeps its order inside a mirrored row. What did not mirror was
everything else: the title, the categories, the row layout. The one trap is a
row that is all Latin (label, gap, key). It resolves into one left-to-right
run and never mirrors. The fix is the settings form's: a right-to-left mark in
the gap, spent for a space. The Hebrew E2e checks both the layout and that a
click still picks the row it lands on.

## UI round, phase 4a: the completion popup speaks without colour (2026-09-24)

- **Frame and rows.** The completion list holds the keys, so it wears the heavy
  frame, like an open select. It gets the `▌` bar, a struck-through row for a
  deprecated item (the client now declares `tagSupport` and
  `deprecatedSupport`), and `▲`/`▼` set into the border when clipped. An enum's
  member is `case`, no longer the same word as the enum.
- **Placement.** The old rule flipped the list above whenever it did not fit
  below, even when above had less room. It then clamped the list to row 0,
  which could lay its border over the line being typed. Now the list takes the
  roomier side, cut to fit, and the caret row stays clear. The docblock pane
  draws nothing rather than an empty frame when it has no row for a line.
- **Ctrl+Space notes.** Ctrl+Space shows a note in place of a missing list:
  "Loading…", "No suggestions" or "No language server". It needs to know
  whether anyone was asked, so `requestAll()` returns how many servers it asked,
  and `EditorCompletionHandler::requestCompletion()` returns whether any were.
  The automatic trigger is deliberately left silent.
- **Signature help.** The active argument is bold and underlined, and a
  signature too long for the popup is cut around that argument. The server's
  other overloads travel with the hint, each with its own active range, so
  Alt+↑/↓ walk them with no second request. Those keys are the declaration
  keys; the hint takes them only while it shows more than one signature.
- **Doc pane.** It scrolls with Ctrl+↓/↑. The wheel still moves the list,
  because a wheel event reaches the popup without a position.
- **Deferred, then done (the combinations audit):** the signature hint and the
  completion list shared a side of the caret, and the list drew over the hint.
  The caret popups are now drawn first and report the side they took
  (`CaretSide`). The hint is drawn last and takes the other side, framed or
  as a bare row, and is left out when there is no free side. The
  documentation popup and the Ctrl+Space note hold their side the same way.

## UI round, phase 4b: documentation at the caret (2026-09-24)

`Shift+F1` used to open the list overlay titled "Documentation", one docblock
line per row. That is a picker with nothing to pick: it was centred on the
screen, far from the symbol, and it had a filter and a selection that meant
nothing. Now it is `DocPopup`, a read-only popup anchored under the caret and
placed by the completion list's rule, so it never covers the caret row. It
scrolls with ↑/↓, PgUp/PgDn and the wheel. Any other key closes it and then
does its own work, as a browser tooltip would. Markdown becomes text
(`HoverMarkdown`, which `hoverToText()` now uses): headings, emphasis, inline
code and link targets lose their marks, and code blocks keep their lines,
indented.

The signature hint and the doc popup share one holder, `CaretPopups`. The
editor widget pins `too-many-properties` at 31, and both are transient
read-outs about the code at the caret.

Quick fixes: the server's `isPreferred` action already sorted first. It now
carries a `★`. An action with `disabled` (declared through `disabledSupport`)
is greyed and struck through, with its reason as the hint. A list item gains
`disabled?: bool` for this, and picking a disabled item leaves the list open.

## UI round, phase 5: toasts you can read and dismiss (2026-09-24)

Cards now differ by shape: double, heavy, then rounded. A card under the pointer
holds still and resumes with the time it had left, so a message no longer
vanishes mid-sentence. A key dismisses the newest card. It is **Alt+D, not
Alt+N**: a terminal's ESC n is the legacy spelling of Alt+↓ (and ESC p, b, f
of the other arrows), so Alt+N never arrives as a letter. For the same reason
the signature hint's overload keys accept both the declaration keymap's
Alt+↑/↓ and the modified-arrow CSI.

The plan's clickable "Open log" link on error cards became a **hint in the
card's border**, `Alt+9 log`, with the key read from the keymap. A clickable
link needs either a new branch in `MouseRouter` (its cyclomatic complexity is
the lint threshold) or a `ToastCenter` dependency on the workbench, which
cycles back through `Notifier`. The log key already opens the log, and the
border says so.

A stack taller than the pane now says `+N more` on its last card, where before
it dropped the rest in silence.

## UI round, phase 6a: the editor without colour (2026-09-24)

The monochrome theme used to underline the whole caret line. That gave every
character being typed an underline and made the line read as a link or an
error. The user chose to mark the caret line in the gutter instead:
- The caret line's number is bold in every theme.
- In monochrome, `›` stands in the free marker column. It is not `▸`, which the
  fold column beside it already uses.
- The shaded band is drawn only where there is colour (`Styles::hasColor()`).

The current search hit is now reverse and bold in monochrome, like a selection,
and stands out from the underlined other hits. Other changes that let state
read without colour:
- a number whose diagnostic glyph a breakpoint or bookmark hid is underlined;
- the last sticky row is underlined;
- the stripe gains the stopped line `▶`, the current hit `━` and bookmarks `◈`;
- the thumb draws `▐` on rows with no mark.

**Deferred, then done:** a matching bracket inside a selection. The span
system needed no rework, because the selection already reopens itself after
an inner close. Only the monochrome case was wrong: there the selection is
reverse video, and the bracket's reverse over it left the pair distinguished
by weight alone. A bracket inside the primary selection is now bold and
underlined; outside a selection it is reversed as before.

## UI round, phase 6b: the find bar shows failure by shape (2026-09-24)

The find bar reported "bad regex", "pattern too complex" and "0/0" only as the
counter's words in the box's border. The Find box now takes the double danger
frame for all three. The bar has a browser find bar's buttons (↑ ↓ ×) and a
match-case tag, `Aa` on `Alt+C` (the key Find in Files uses; column mode's
`Alt+C` is an editor key, so the focused bar takes it first), which overrides
smart case. The query field takes `TextField::editKey()`, like every other field.

The key hints stay the catalogue's fixed strings with `Alt+C Aa` appended. The
bar's keys are its own defaults, not a user-rebindable map, so the fixed text
is accurate, and rebuilding the hints from the keymap would retire seven
translations of each string for no change a user could see.

## UI round, phase 6c: changes walked and reverted from the gutter (2026-09-24)

Next/previous change and show-change were first all adoptable git verbs.
The combinations audit below moved the walk into core. Show-change
(`git_show_change`) stays adoptable, and the git plugin implements it from a
`-U0` diff against HEAD. The keys are PhpStorm's,
Ctrl+Alt+Shift+↓/↑. `matches()` names those modified arrows even with kitty
off, where `parse()` does not.

A click on a change mark used to toggle a breakpoint, as every non-fold
gutter column does. It now moves the caret to the line and runs
`git_show_change`, but only when some plugin provides that verb; otherwise it
is still a breakpoint. The decision lives in a new `Input/GutterClickRouter`
and not in `MouseRouter`, whose complexity is the lint threshold. Moving the
old fold-or-breakpoint `if` out paid for the new target.

The change shows in the list overlay rather than an anchored diff popup. A
plugin cannot draw a popup at the caret, and the list already renders its
`disabled` rows struck through, which is exactly how removed lines should
read. The revert is done in the buffer (one undo step, through
`replaceDocument()`) and not with `git apply -R` on disk. It checks first that
the buffer still holds the added lines, because the hunks describe the file
on disk.

## UI round, phase 6d: a gutter mark says what it is (2026-09-24)

The gutter's marks were glyphs and colour, and a click on them did different
things by column. Now the mark under the pointer is underlined and flashes its
meaning on the status bar (`GutterHint`), using the marker column's own
precedence. The hover state lives in `DecorationStore::$gutterHover`, because
the editor widget pins `too-many-properties`. The store reports a change only
when the pointed mark changes, so motion events do not re-flash it.

**Deferred, then done:** hover on the marker stripe. The row's winner is now
computed once (`ErrorStripe::winners`, using `StripeMark`'s case order as the
precedence). The cells draw it, and `markAt()` tells the pointer its kind and
the first line of that kind in the row's slice: "Error · line 12". Only a
mark underlines, not a blank row or the thumb.

## The combinations audit after the UI round (2026-09-24)

These were found by running the round's new pieces against each other:
- **Stale change marks.** Change navigation walked the git plugin's copy of
  the marks, which is the disk's. Core moves the marks with every edit, so
  after typing new lines the walk landed that many lines off. The walk is now
  core's: `next_change`/`prev_change` go through `Editor\ChangeNavigation`
  over the store's marks, whatever paints them. Reading them back through
  `PluginApi` would have widened a role (`EditorViewOptions`) for one caller.
  The plugin keeps `git_show_change` (`ChangeView`), which needs git.
- **The find bar's one-row form.** A narrow pane collapses the bar to one row,
  and that form ignored the failure state. It now shows the double rails too.
- **A stray underline in the gutter.** After a click removed a breakpoint, the
  hover underline stayed on the now-blank cell and read as an underscore.
  Only a real mark is underlined now.
- **The signature hint under the completion list.** See phase 4a: the hint
  takes the side the other caret popups left free.
- **A replaced toast under the pointer.** A slotted card replaced while the
  pointer rested on it ("Fetching…" becoming "Fetched") got a running timer,
  and no motion event would come to pause it again, so it could expire while
  being read. It now stays held.
- **The change view's selection (found driving the real IDE).** The list
  opened with the selection on the removed line, a disabled row where Enter
  did nothing. One Down reached the added line, where Enter only closed the
  list, so in practice the change was never reverted. The list overlay's
  selection now skips disabled rows, as a menu's does: arrows and the wheel
  wrap past them, paging and the frame cues stop at the nearest live row.
  The change view puts "Revert this change" first, where the selection
  starts. A revert is one undo step.
- **Blank rows in a narrowed picker (found driving the real IDE).** Padding a
  narrowed picker to its old height left "no results" above a dozen blank
  rows, which looked broken. The goal was never the height; it was not moving
  under the reader. The box is now placed as if it had its tallest height, so
  the top does not move, and it shrinks from the bottom.
- **The docblock hard against the rail.** Both documentation panes, the hover
  popup and the completion list's, now pad their text a column either side,
  as every other box does.
- **The palette opened on a greyed command (found driving the real IDE).** With
  no debug session, Find Action's first row was the greyed Step Over, where
  Enter did nothing. The search modal's selection now skips greyed hits too
  (`SearchRow::isSelectable()`), and the actions source lists runnable
  commands before greyed ones, score order within each group.

## Styled text is Symfony Console markup (2026-09-24)

`Styles::format()` reads Console's formatter markup against the live theme
(`Ui/Markup`, see subsystems.md). What was decided, and what would re-open each
decision:

- **Flat runs, not nested applies.** Each run of text gets the merged style of
  every tag around it, which is how Console paints. A single span is byte for
  byte the old `$style->apply($text)`. `StylesFormatTest` pins that against raw
  escapes, and it is what let the three migrated sites keep their bytes. The
  nested form (`$outer->apply('a'.$inner->apply('b').'c')`) was tried first
  and dropped. An inner span's `39m` resets the foreground to the terminal
  default, not to the outer colour, so a `<info>` word inside the toast's
  Bright body turned the rest of the sentence grey.
  *Re-opens on:* tui's `Style::apply` restoring the parent foreground the way
  it already restores the background.
- **Nothing cached.** Parsing is one `preg_match_all` over a short string, and
  a byte cache would have to be keyed by theme to survive `switchTo()`.
  *Re-opens on:* a `perf.log` showing `format()` in a frame's cost.
- **A bare string is literal.** `Notifier`, `PluginApi::flash/notify` and
  `ToastCenter` take `string|Markup`, and a string is escaped. Reading every
  message as markup would let a file name, a commit subject or a server message
  open a tag. *Re-opens on:* never, short of every caller escaping by
  construction.
- **An unparseable tag prints; it does not throw.** Console throws on an
  unknown colour or a stray close tag. A render path has nowhere to throw to,
  and a toast body can come from a translator.
- **`comment` is StatusWarning, not Muted.** Console's `comment` is yellow,
  and it is used to point at a value, not to recede. `info`, `question` and
  `error` map by the same rule, to Added, Emphasis and StatusError.
- **Only multi-span sites migrated.** About 250 `->apply()` calls are single
  spans around computed chrome, a `Style` passed in (a severity accent), or
  byte-level seams (open-only diff runs, decorator open/close pairs, SGR-58).
  Markup names roles, not `Style` objects, so it cannot express those. The
  three sites that concatenated whole role spans were converted.
  *Re-opens on:* a new site that concatenates two or more role spans. Write it
  as markup.
- **No `PluginApi::styled()`, no test helper.** Views and panes already
  receive `Styles` (`ViewServices`, `PaneServices`), and a test writes
  `Styles::dark()->format(…)`. Either wrapper would have been a second
  spelling with no caller of its own.

## Separation lines and boxed controls (2026-09-24)

- **One tint for a separating line.** `Role::Rule` and `Widget/Render/Rule`
  replace four roles and a dozen hand-rolled runs. `Role` stayed at its pinned
  61 cases: `TagOffBg` went, its one user (the diff toolbar's off tag) now
  faint like the find tags'.
- **One rule for when a control drops to one row.** `Ui/Density`, from the
  terminal's rows, never a pane's: Find in Files compared its bounds, the
  commit box its sidebar, and they disagreed about the same screen by the menu
  and status rows. A plugin's widgets get it through `ViewServices`, since
  they see only their region.
- **The edge rules are widgets of their own**, not a row inside the menu or
  status bar: the bars keep one row for hit-testing, and a dropdown composites
  over the rule like over any pane. They take rows `main` had, so the pane
  union — the modals' bounds — did not move.

- **Joins are a pass over the finished frame** (`FrameJoins`), not widgets
  asking their neighbours: neither the edge rule nor a legend knows where the
  divider stands, and a column pushed into them before the frame is one frame
  stale during a sidebar drag. It reads plain glyphs, so a content `─` right
  beside a divider would join too; no surface draws one there today.

*Re-opens on:* a surface that needs a density of its own (a pane-local rule
would bring back the disagreement above), or a pane that draws `─` against a
divider (then the join has to check the cell's tint as well).

## Three-row chrome (2026-09-25)

The find bar's fields and buttons were three-row boxes while everything around
them was not: the key hints sat on the top row with the middle one empty, the
tab strips were two rows and the side tabs one. On a roomy screen every strip
is now three rows, the rhythm the boxes set.

- **A strip stands on a line that opens at the shown tab.** The horizontal
  strips gain a foot row (`TabShape::foot`); the side tabs turn it on its side
  (`TabShape::side`), a rule down the edge facing the editor. The selection bar
  the side list wore is dropped where the shape already says it; the compact
  list keeps it.
- **The dock's grab moves to its top row.** This reverses the deviation in
  "Folder tabs, and where their height comes from": the lid row was refused as
  a grab because a press there did not start a drag. Now it does
  (`MouseRouter` arms the strip's height above the panel, `HoverRouter` lights
  that row), and the status sits level with the labels, between the grab and
  the baseline.
- **The side rule is a logical end.** A chrome row reverses under RTL, so the
  strip is told the reading direction (`TabBarWidget::setTextDirection`, from
  `WorkspaceLayout::setMirrored`) rather than reading its inner edge off its
  physical seat.

A third row costs each strip one more line of code on a roomy screen; the
compact rule is untouched, so the 24-row rigs and short terminals see no
change. `LayoutController::chromeRows()` counts the editor's strip only while
it is horizontal: at the side it takes columns, and counting it anyway had
left a maximised panel one row short, two once strips were three rows. A
wheel notch over the side list moves it three rows either way
(`TabBarWidget::scrollByRows`): three one-row tabs, or one folder tab.

*Re-opens on:* a roomy screen where the third row is missed — measured, not
felt — or a surface whose strip cannot open under its shown tab.

## The find bar's Select All Occurrences (2026-09-25)

PhpStorm's find bar turns every match into a selecting caret (`Alt+Enter`).
Porta's editor already had `select_all_occurrences` (`Ctrl+Alt+Shift+J`), but
it takes the word under the caret and drops point carets, so it cannot follow
a regex, whole-word or match-case query. The bar's version selects the bar's
own matches instead: `FindTarget::selectMatches()` hands them over, then the
bar closes so that typing reaches the editor. The docs viewer refuses, and the
bar draws no `∀` button there.

- **The current match is the primary caret**, so the view stays on the match
  being looked at rather than jumping to the first.
- **The target holds the `MultiCaret` of the document it searches**, the same
  way it holds that document for replace. The widget exposes the caret set as
  `public private(set)` and gained no method. It sits at the `too-many-methods`
  ceiling, and "The `too-many-methods` threshold moved" above says to route
  around that ceiling rather than raise it. A buffer switch leaves the old
  caret set orphaned, so no guard is needed.
- The `Alt+⏎ ∀` hint is appended untranslated, like `Alt+C Aa`.

*Re-opens on:* a request to keep the bar open after selecting, which PhpStorm
does not do.

## Tooltips (2026-09-25)

Much of the chrome is a glyph, an abbreviation or a name cut to fit: `Pc`,
`i"`, `∀`, `⌾`, git's `R`, `hide-ws`, a tree row's change mark. One place
explained itself on hover: the gutter and the marker stripe flashed their
meaning on the status bar (phase 6d). That is replaced by a floating card that
every surface can use, and the gutter moved onto it. The status bar is far
from the pointer and cuts the text on a narrow terminal.

- **The dwell is keyed on the element, not the pointer.** A move over the
  same element changes nothing, for the reason the spring-load dwell gives: a
  resting hand jitters. Once a card is up, the next element's shows at once,
  and within 0.6 s of one leaving. A key, a press or the wheel dismisses it
  and resets that grace.
- **The card has no arrow.** A `▲` set into the border, then a stem joined by
  a tee, were both tried; the owner asked for the arrow to go. The card sits
  flush against its element and casts no shadow, which would fall on the
  element.
- **A resize asks again at the pointer's last cell,** one frame late, against
  the resized layout's rects. An element still under the pointer keeps its
  card; one carried away takes it with it.
- **Views are walked, not asked.** Widening `ViewPointer` for the one caller
  would break every plugin view and is the "widen a role for one caller" the
  container rules forbid. The router walks the open view's region for
  `TooltipProvider` widgets instead.
- **The editor gained no method.** `GutterPointer` (built from the widget's
  public geometry) answers for both the hover tint and the tooltip, and the
  completion popup is read through `public private(set)`.
- **What a card says is recorded at render**, where the row knows what it cut,
  so the card and the memoized rows cannot disagree.

**Not done, on purpose:** menu items (a disabled item's reason and a cut label
need an action id threaded through `MenuItem`/`MenuModel::item()`); the help
viewer's `‹ ›` (the arrows are universal); the Welcome step marks and the
menu bar's `…`.

*Re-opens on:* a request for keyboard-focus tooltips, or a menu item whose
disabled state confuses users.

## A TUI rationalization pass, and what it left alone (2026-09-28)

An audit of the whole TUI (chrome, interaction, layout) proposed about thirty
changes. The ones that landed: `BorderHint` and `TabCloseGlyph::hovered()` as
the one hint-in-border and the one × hover; `PopupFrame` under the tooltip
card; the title inset inside `HeaderRenderer`; `KeyHints` for every legend that
names a rebindable action; `SearchPattern::compiles()`; Esc clearing the
results filter first; PgUp/PgDn in the results and debug panels; a results or
frame row's right-click; `PopupMetrics::centered()`; `FieldBox::MAX_WIDTH`.

**Declined, each for a reason that would have to change first:**

- **A modal section tee in the accent throughout.** It is `Role::Rule` between
  accent tees on purpose: one tint for every separating line (above).
- **Toast severity by tint, not shape.** The shape is what reads in monochrome.
- **`Rule::vertical()` and a grab helper.** Each hand-drawn line is one glyph in
  a three-way style switch; a helper removes no duplication.
- **Renaming `Role::TabAccent`.** `<tab-accent>` is plugin markup API.
- **One legend style.** Modal title bold, group legend plain, field legend by
  its state: a hierarchy, not drift.
- **The diff cursor as `SelectedRow` with a selection bar.** The bar shifts the
  diff geometry the hit tests share, and `SelectedRow`'s foreground would
  override the added/removed and syntax colours. The grey band is the editor's
  current-line idiom. *Re-opens on:* a visual pass with the colours in view.
- **Scroll cues, Home/End and empty-state text in the docked lists.** Settled
  above (counts in the headers; Home/End belong to a list's text field).
- **Renaming the widget-local action ids** (`close`, `exit_view`, `jump`…).
  None is in `ActionCatalog`, so no user can rebind one: pure churn.
- **Hints for widget-local keys from the keymap** (find bar, settings, Welcome).
  They cannot go stale; nothing rebinds them.
- **The graph viewer's `Ctrl+Q quit` from the keymap.** A plugin has no binding
  lookup, and adding one widens the plugin API for one hint.
- **A terminal right-click menu.** The terminal has no selection to copy.

*Re-opens on:* evidence against any single reason above; the list is not a
blanket refusal.

## symfony/tui reuse audit (2026-09-28)

A sweep of `src/` and `plugins/*/src` against `vendor/symfony/tui` for logic
the vendor already has. Most of the text layer already delegates (`AnsiText`
wraps `AnsiUtils`, `TextField` wraps `Widget\Util\Line`, `TextInput` uses
`StringUtils`). Four things were adopted:

- **Text-field keys go through the shared `KeyParser`.** `TextField::editKey()`
  and `MultilineInput::handleKey()` matched raw legacy bytes. With the kitty
  keyboard protocol on, Ctrl+W arrives as `CSI 119;5u` and Alt+Backspace as
  `CSI 127;3u`, so word-delete was dead in every text field on a kitty-capable
  terminal. Both now take the caller's `Keybindings` and match fixed key ids on
  its parser, which holds the kitty state. Kitty LF names Shift+Enter, so the
  multi-line input binds both to its line split.
- **`NonBlockingTerminal` uses `TerminalEscapeTrait`.** Its seven escape helpers
  were a byte-for-byte copy; the trait writes through the using class's
  `write()`, so the queue is kept. The trait is `@internal`: re-read it on a
  vendor upgrade, as with `BidiTui`.
- **`GraphCellGrid::colorKey()` uses `Color::hex()->toForegroundCode()`.**
  `DotColor::normalize()` only ever hands it `#rrggbb`, so the vendor's throw
  is unreachable.
- **`AnsiText::stripCursorMarkers()`** builds its pattern from
  `AnsiUtils::CURSOR_MARKER_PREFIX`, replacing three hard-coded `pi:c;`
  literals (`OverlayCompositor`, `SpanSlicer`, `PaneReorder`).

**Measured and kept, each for its reason:**

- `AnsiText::wrap()` vs `TextWrapper`: `MarkupNode::lines()` relies on its
  one-space-per-break byte accounting; the vendor rtrims and re-emits ANSI.
- `AnsiText::columnsFrom()` vs `sliceToWidth()`: the vendor pads (above).
- `Document` word motion vs `WordNavigator`: `$` is a word character in PHP.
- `ClipboardRing` vs `KillRing`: promote-on-recopy and a byte budget, no yank-pop.
- `TextMetrics` / `mb_strwidth` vs `visibleWidth()`: settled (2026-08-14); tab
  stops and UTF-16 columns are not vendor concerns either way.
- `TabBarWidget` family vs `TabsWidget`, key hints vs `KeyBindingWidget`: settled.
- `SettingsFormWidget` vs `SettingsListWidget`: text rows, a choice popup and
  paging; the vendor only cycles values.
- `UndoHistory` vs `EditorDocument`: multi-caret snapshots, compound edits,
  coalescing and a line budget.
- `OverlayHost` picker and `MenuPanel` vs `SelectListWidget`: floating overlay,
  mouse hover, disabled rows and mnemonics; `ListSelection` has variable row
  heights and drag autoscroll the vendor keeps private.
- `FocusRing` vs `FocusManager`: members change per call; about 15 lines.
- `ShortcutFormatter` vs `Key::label()`: different glyphs and a Cmd modifier,
  pinned by `HintDialectTest`.
- `OverlayCompositor` vs `Compositor`/`Layer`: one bounded splice per row
  against a whole-canvas cell grid, and the vendor pair is `@internal`.
- `BidiTui::processRender()`: the deliberate mirror (above).
- `TerminalReplay` / `TerminalBuffer` vs `ScreenBuffer`: the vendor grid does
  not auto-wrap, and the terminal panel is line-based scrollback.
- Raw `EventLoop::repeat` timers vs `TickScheduler`: `@internal`, tied to the
  TUI tick, and the watchdog must run while the TUI is stalled.
- Theme `38;5;N` literals and `HalfBlockRasterizer`'s merged SGR vs `Color`:
  constants, and a per-pixel hot loop.
- `CodeEditorWidget::hasControlBytes()` vs `StringUtils::hasControlChars()`:
  it allows tab and LF.

**Open, not done here:** `I18n/Bidi.php` tokenises only SGR and APC, so any
other CSI or OSC counts as visible text and a tab as one column;
`AnsiUtils::walkCells()` would count both right.

*Re-opens on:* a vendor release that makes one of the `@internal` pieces public
API, or evidence against a single reason above.

## Pixel graphics: kitty placeholders, cut per view, sixel not done (2026-09-28)

The image and graph viewers paint real pixels where the terminal can
(`Ui/Graphics/TerminalGraphics`, `ImageViewer/PixelPlacement`), half-blocks
everywhere else. Each choice below was driven in cmux (Ghostty 1.3.2, 16×34
cells) before it was kept.

**Unicode placeholders, not cursor-placed images.** A frame here is a
line-diffed text grid with overlays spliced in, a bidi reorder and a join pass;
an image escape inside a line would be replayed by every slice that crosses it,
and a cursor-placed image is erased by the writer's `\e[2K`. Placeholder cells
are text, so a menu, a toast, the focus frame and the graph's selection box
overwrite them like any cell. Measured: a dropdown over the picture draws whole.

**Panes paint stand-ins; `BidiTui` expands them last.** A real placeholder is
U+10EEEE plus two combining marks, and the vendor's `slicePrefix` /
`truncateToWidth` fast paths size a run with `mb_strwidth()`, which counts each
mark as a column: a slice from column 0 read eleven cells as 33 and cut the
dropdown 22 columns short, padded blank. One plane-16 codepoint per cell is one
column to every width function; the cap that keeps them below U+10EEEE is 239
cells a side, and a bigger pane falls back to half-blocks. The vendor bug also
mis-measures decomposed accents in ordinary text; that half is fixed for all
text by `Support/AnsiSlice` (2026-09-29), and a report is drafted for upstream.

**The view is cut here and sent, not cropped by the terminal.** The first
design sent each picture once and re-placed a source crop per zoom (`x,y,w,h`
on the virtual placement). Ghostty draws the whole image into a placeholder
placement whatever the rectangle says — confirmed step by step: re-cropping,
repainting the cells, and a new placement named in the underline colour all
left the picture unchanged. So `PixelPlacement` crops and scales the visible
part to the cells' pixels (capped at 1 MP) and alternates two image ids: a new
view is new cell TEXT, which the differential writer must rewrite. A zoom step
paints in ~90 ms, the PNG encode.

**Zoom counts screen pixels in pixel mode.** A column is a whole cell (16 px
here), so the ladder's 12.5% was already twice life size and `-` stopped at
fit. `ZoomPanModel::setPixelsPerColumn()` divides the ladder, `1` and the label
by it; the geometry itself stays the half-block grid's, which is what keeps the
selection box and hit-testing on the node. The ~6% aspect error of a non-1:2
cell is the half-blocks' too, and stays.

**An environment override, not a setting.** `PORTA_GRAPHICS=kitty|off`, the
`PORTA_TERMINAL_BIDI` shape: the viewer settings live on their plugins, and a
core choice setting would carry Settings, i18n and config plumbing for an
escape hatch. WezTerm, Konsole and tmux are refused unasked: they answer the
query but cannot show a placeholder (tmux would need passthrough wrapping).

**Sixel and iTerm2 OSC 1337: not done.** Both paint at the cursor, so they need
the inline design above — re-sent on every change and erased by row rewrites.

*Re-opens on:* a Ghostty release that honours the placement rectangle (the
per-view cut could become one transmit per picture), a vendor fix to the
`mb_strwidth` fast paths (the stand-ins, and `AnsiSlice`, could go), or a user on a sixel-only
terminal.

## `porta` as git's difftool and mergetool, and as a phar (2026-09-29)

`porta --diff L R` / `porta --merge L R B M` are git's two tools; `porta
--git-setup` writes them into the global git config; `composer build:phar`
builds `build/porta.phar`.

**Core raises facts, the git plugin answers.** Core keeps no git-named symbol,
so it cannot open the git view. `Ide` dispatches `DiffToolLaunched` /
`MergeToolLaunched` (PluginObservable, scalars) after `IdeReady`; the plugin
opens its view. They are facts — the launch happened — not `…Requested`
commands, because a command must have exactly one COMPILED core listener
(`EventListenerWiringTest`) and these are answered at runtime. Ending a difftool
run goes the other way: the plugin `emit()`s `QuitRequested`, a command whose one
listener is `WorkbenchActions`. With no listener at all (plugin disabled) core
opens the file in the editor and flashes why.

**The merge verdict is core's.** At quit `$MERGED` is read from disk; no
`ConflictMarkers` block means exit 0. It does not depend on which UI resolved
it, and `trustExitCode` then makes git believe it — measured: a quit with
markers left gave `merge of a.php failed` and `UU`, a resolved one staged it.

**A tool run is ephemeral.** Git calls the tool once per file, from the work-tree
top, on temp blobs. Before this a launch recorded a recent project, showed the
Welcome on a new root, and at quit OVERWROTE the project's `session.json` with
one file's tabs (save without restore). None of that happens for a tool run.

**Two things only a real `git difftool` showed.** Git runs the tool from inside
`git diff` with `GIT_EXTERNAL_DIFF` set, inherited by every git Porta spawns, so
`git diff --no-index` handed the diff to git's helper and the view read
"identical" — every diff-producing command now passes `--no-ext-diff`. And a
plain `git diff` of an unmerged path is a combined `diff --cc` the pane cannot
parse, so a conflicted row diffs `--ours`.

**No 3-pane merge view.** The user chose the existing git view plus the editor's
conflict resolution; a PhpStorm-style view (ours | result | theirs) remains the
obvious next step.

**The phar.** Box, fetched by the build script into `build/` rather than a dev
dependency. `glob()` cannot list inside a phar, so the phar first shipped with
no bundled plugin at all; plugin discovery now walks directories
(`Files::childFiles`). The container compiles from inside the phar and dumps to
the user cache dir as usual. Not yet published as a release asset.

**`--dir-diff` compares the trees in PHP.** Git hands over two temp directories
of the changed files, the working-tree side as SYMLINKS into the repository, and
`git diff --no-index` compares a symlink by its target text — every file would
read as a type change. `DirectoryComparison` walks both trees through the links
and compares contents; each selected file is then diffed as a pair. The changes
list holds git's copies there, so stage, stage-all and discard are refused
(mutation-checked one by one), and commit and line staging are off because the
comparison clears the repository state they read.

*Re-opens on:* a request for the 3-pane view, or Packagist publication (the
install docs would lose the VCS-repository step).

## `porta --self-update` (2026-09-29)

The phar replaces itself with the latest GitHub release of the (private)
repository. **Credentials:** the `gh` CLI's own login first, else `GH_TOKEN` /
`GITHUB_TOKEN` through the REST API with `curl`, the token handed to curl as a
config on stdin so it never shows in a process list. **Versions** are release
tags stamped at build time (`bin/build-phar`, because Box's own `git` setting
fails in a repository with no tag); a build from commits after a tag counts as
that tag, and a bare-commit build as `0`. The dot matters: `1d2bb88b` starts with
a digit and, read as version 1, was offered nothing until a test said so.
**The swap** checks the checksum, runs the download once (`--version`), writes
it beside the running phar and renames it over — atomic on one filesystem, so an
interrupted update leaves the old phar. Only a phar updates itself; a composer
or checkout install is told how to update instead.

*Re-opens on:* the repository going public (the credentials could go), or a
signed-release requirement (a checksum from the same release proves integrity,
not origin).

## Settings → Command line (2026-09-29)

The `porta` command, git's tools and the self-update are installed, updated and
removed from a settings page, beside the shell's `--git-setup` /
`--self-update`. **The logic is core's `Install/`**, shared by `Console/` and
the page: the git config it writes is Porta's own, not the git plugin's (whose
views it opens), which is why `GitToolSetup` was already core, and a plugin
page could not host it anyway — plugin settings are values, never buttons with
a state. **Only a symlink is managed.** A regular `porta` on `PATH` — a copied
phar, Composer's `vendor/bin` proxy — was put there some other way and is shown
as such, never replaced or removed; a link can only have come from an `ln -s`,
and one pointing elsewhere (a moved checkout, another phar, nothing) is exactly
what Update is for. **Removal keeps a tool the user picked since:** the two
`porta` sections go, `diff.tool` / `merge.tool` only while they still say
`porta`. **State is read, not remembered**: each row rebuild stats the link and
runs one `git config --get-regexp`, milliseconds, in line, so a change made from
a shell meanwhile is what the page shows. **The update runs `porta
--self-update` as a child process** (GitHub is not something the loop waits on)
and tells "updated" from "up to date" by the phar's hash, not by parsing what it
printed. Driven for real (a phar stamped v0.0.1 updated to v0.1.0 from the
page): the running IDE carried on — other pages, menus — on the code it had
started with, so the page says to restart rather than pretending the new code is
live.

*Re-opens on:* a Windows port (no symlinks without privilege), or a request to
manage a copied phar as well.

## Code review conversations — the `review` plugin (2026-09-29)

GitHub pull requests, GitLab merge requests and local reviews an agent writes
are read and answered from a view, a diff and the editor. **A plugin of its
own, not part of git**: it requires git for classes (the process runner, the
diff model) and shares none of git's live objects. Its view is its own, not a
tab of git's: a tab would need a cross-plugin seam into git's view
controller, and the Review menu and the palette reach it without one.

**Sources.** One `ReviewSource` interface, three implementations. The forges go
through their CLI's own login (`gh api`, `glab api`), else `curl` with a token
from the environment, passed as a config on stdin with the body, so the token
never shows in a process list. There is **no token setting**, because settings
have no secret kind and `.porta.json` is often committed. GitHub is read over
GraphQL, which alone says whether a thread is resolved; a new line comment goes
over REST (`pulls/{n}/comments` publishes at once, where the GraphQL mutation
leaves a pending review). GitLab is REST, in three reads, because the merge
request list leaves out the `diff_refs` a new diff note must quote back. The
fixtures are real API answers with names and words replaced; the probe found
GitHub's `line: null` on an outdated thread, which is now what "outdated" means.
**Driven for real** against private scratch repositories on both forges —
every write (line comment on each side, reply, resolve, reopen, review
comment), through the CLI and through curl. That found what no fake could:
`glab api --input -` sends no content type and GitLab refuses the body, so
the CLI is now always told `Content-Type: application/json`.

**Local reviews are append-only directories**, not one JSON file: nobody ever
rewrites another writer's file, so an agent and the IDE write at once with plain
file tools. A 2 s fingerprint poll notices an agent's write. **`porta review`**
writes the same files from a shell, and it is the PLUGIN's: a bundled plugin
names console commands in `extra.porta.commands`, `bin/porta` hands `porta
<name> …` to one before reading a path, and core names no command. The class
must extend `Plugin\Api\PluginCommand` (the project root, the error stream) —
anything else a manifest names is refused, a plain Symfony command included. Only
bundled plugins are asked, so a project's `.porta/plugins/` never runs code from
a shell prompt. Thread and review names typed on the command line are refused
unless they are one directory under the reviews directory.

**Core grew three plugin decorations**, and each is plugin-scoped:
- **Line annotations**, per owner. They used to be one map that each publish
  replaced, so blame and a review summary would have erased each other. A line
  two plugins annotate now shows both.
- **`setCommentMarks`**: a `✎` in the marker column (under the bookmark, over
  the diagnostic, for the bookmark's reason) and on the stripe. The pointer
  reads the summary, which is what "gutter mark, hover shows the thread" needed
  without a hover API.
- **`setLineBlocks`**: rows under a line, the mirror of code lenses above one.
  `EditorScroll` learnt `rowsBelowLine`. Counting a block as rows above the
  caret, the way lenses are counted, scrolled for nothing.

The comment marks live on `Buffer`, and `BufferService` delegates to it: adding
the merge to `BufferService` itself tripped its `kan-defect` ratchet.

**Two overtaken answers are dropped by generation**: the editor's line
translation after a buffer switch, and the view's file load after another
selection. Each guard was mutation-checked, with the plugin's own process lane
as the E2e barrier.

**Every pane of the view is a `MenuOverlayHost`.** A view's panes are where the
floating layers — the app menu's dropdown, toasts, tooltips — are composited,
and panes that are not hosts paint over them: the File menu opened over the
view showed its top edge and nothing else. The column is a `ResizableSidebar`
(the list and the reply box as one), so the separator drag and its persisted
width come from core like the git view's.

**A send clears the reply box only if it still holds what was sent.** The E2e
found this: a reply typed while the previous send was in flight was wiped by
that send's success.

*Re-opens on:* a request for the review as a tab of the git view (it needs a
cross-plugin view seam), for pagination past 100 threads or comments, or for a
forge beyond GitHub and GitLab.

## Plugins carry their own translations (2026-09-30)

Every plugin string lived in core's seven catalogs: 223 of 979 units, 121 of
them git's. A third-party plugin had no way to ship a catalog at all, so its
strings were English in every locale.

A plugin now ships `translations/messages.<locale>.xlf`, and the loader
registers the dir after the plugin commits. It is found **by convention, not by
a `PluginApi` verb**: every plugin that has strings wants them loaded, so a
verb would be a call each plugin must remember and none would ever skip.

The units moved where exactly one plugin's runtime code uses the string: git
121, graph-viewer 29, review 28, symfony-lsp 15, image-viewer 14, local-history
13, phpantom 2, mago 1. Those shared with core or between plugins stay in core
(`Cancel`, `Run`, `on`/`off`, `Not a UTF-8 text file`, `Languages`). One
domain, merged, because core translates plugin-supplied labels itself.

**The translator rebuilds on a new dir, rather than keying its own cache
path.** Adding a resource to symfony's translator kept serving the old dump,
because its file name hashes only `cacheVary`. Putting the dir set into
`cacheVary` fixes that at the source, and a rebuild costs nothing since loading
is lazy. A per-plugin-set cache subdirectory would have worked too, and would
have left the dump name lying about what it holds. Core loads last so a plugin
cannot retranslate core.

Every catalog gate now iterates the sets (`tests/Support/Catalogs`, which also
replaced three copies of the source-reading XPath). `CatalogCoverageTest` scans
`plugins/*/plugin.php` too, whose ~32 literal `trans()` calls it could not see
before. A literal in core must resolve in core's catalog: core cannot lean on a
plugin that may not be loaded. `GitCatalogTest` boots the IDE in French,
because every plugin test runs in English, where a missing catalog is
invisible.

*Re-opens on:* a plugin that needs its own domain (a collision core's-wins
cannot settle), or live-disable having to drop strings before a restart.

## Agent-speed round 2: one change landed, three measured away (2026-09-30)

A survey of what slows an agent's loop proposed five changes. Each was measured
before building; one survived.

**`composer mutate` builds its coverage with paratest now.** It ran the Unit suite
serially under pcov before Infection even started. Two serial/parallel pairs on a
loaded box: **242s → 68s** and **426s → 56s**. Both reports carry the same
**23,924 of 25,753** executed lines; 797 of 799 files attribute each line to the
same tests, and the two that differ (`PluginAutoloader`, `ProcessReaper`) hold
process-global state a serial run is equally exposed to. Infection's verdicts
over `SearchPattern` matched mutant for mutant (22/22 killed both ways).
`pcov.directory=src` keeps plugin files out of the report, which is what made the
parallel merge unsafe for the plugins pass.

**Memoising the container freshness check — not worth it.** The survey read
"re-hashes ~480 classes per boot" as 15–25s of suite CPU. Measured in-process: a
repeat tracked boot costs **~1ms** (0.7–1.1ms), untracked 0.02ms; the first boot
in a process 10–18ms, mostly autoloading a memo cannot skip. Across ~458 boots
that is under a second of CPU.

**Sharing one built container across `ContainerTestCase` tests — not worth it.**
All nine container classes run in ~10s serial CPU (EventListenerWiringTest 1.7s,
IdeContainerFactoryTest 4.4s); the 2.2s/test figure came from a loaded run.
Spread over the suite's workers it is under a second of wall.

**Dropping the Plugins suite from the `src` coverage pass — blocked on core
tests.** `--testsuite=Unit,Integration,E2e` leaves **~100 `src` lines in 22
files** covered only by plugin tests: the plugin-facing seams (`PluginApi`,
`PluginLifecycle`, `MouseRouter`/`WheelRouter`/`GutterClickRouter`,
`FiberWait`, `SingleSlotProcess`, `BufferService`). The pass would lose ~20–30s
only after core tests those seams itself.

**A coverage-map `test:changed` — not built.** The only per-test map is
`composer mutate`'s, which is Unit-only, and the whole Unit suite is ~22s at 24
processes; the command would save seconds and add a 100%-covered console command.

*Re-opens on:* the Unit suite passing ~60s (then `test:changed` pays), or core
growing its own tests for the 22 plugin-only files (then drop Plugins from the
`src` pass).

## Agent-speed round 3: what repeated editing was worth a tool (2026-09-30)

**Built: `bin/porta-dev i18n:add` and `composer mutate:clean`.** The catalogs are
the most-edited files after this history (~150 commits each), and a string was
seven hand edits with an id picked by eye — where a duplicate id empties the
whole locale without an error. `mutate:clean` exists because every `mutate` run
leaves ~300MB and hand runs had left 1.5GB.

**Declined, each because the double entry IS the guard:**

- **`ThemeLockTest` against a golden file.** Restating every theme value is what
  makes a colour change deliberate. A regenerate flag turns that into one
  command anyone can run without looking — a lock with the key in it.
- **The arch allowlist as a data file with `arch --write`.** The drift message
  already names the edge and the fix, so adding one is a one-line edit; most
  lines carry a comment saying why the coupling is allowed. `--write` would add
  edges with no reason attached, which is the ratchet's whole point.

**Declined as churn without payoff:**

- **An `IdeE2eTestCase` for the `RunsIde` + `PreservesPath` + `ClearsPortaLsp`
  trio.** Three one-line `use`s that compose as `#[After]` hooks, the house
  shape; ~45 files of churn to save nothing per test.
- **A `#[NoPath]` attribute.** `putenv('PATH=/nonexistent')` plus `PreservesPath`
  is the idiom, not a missing rig. The two hand-rolled saves
  (`ContainerSmokeTest`, `PluginCommandsTest`) are scoped `try/finally` blocks.
- **Splitting `tests/Unit/IdeHelpersTest.php` (1,240 lines).** A grab-bag, but its
  shared setUp copied into six files trips the zero-clone gate, and every
  section is found by grep.
- **A doc test that `docs/events.md` lists every event.** The page explains the
  mechanism; it is not a catalogue, and its only stale fact was a count — now
  removed rather than guarded.

*Re-opens on:* a theme or allowlist edit landing wrong because the second entry
was mis-typed rather than skipped; E2e boilerplate causing a real defect.

## The `src` coverage pass is graded by core's own suites (2026-09-30)

**Landed: `coverage:src` runs `--testsuite=Unit,Integration,E2e`.** Round 2
left it blocked on ~100 `src` lines in 22 files that only plugin tests reached.
Core now tests those seams itself: `FiberWait::within()`, `SingleSlotProcess`,
`Text`, `Outcome`, `Runs::start()`, the header's narrow-suffix branch, a plugin
save through `BufferSaveRequested`, a listener answering a difftool launch and
asking to quit, a left click on a plugin status cell with a menu, a gutter
change mark with a change viewer, a view's own context menu on both chains, a
replaced server binary relaunching only a provider, and — through
`tests/Support/TestPane` served by a project plugin — the navigable pane's
wheel, sideways wheel, ctrl+wheel ladder and glide, drag-pan, click-select,
disk refresh, loader refusal, "Open as Text" and the architecture title.
The glide is made deterministic by stamping `ZoomGesture::$lastTime` ahead of
the clock, not by two quick inputs a loaded box can space past its 100ms.

It also makes the rule CLAUDE.md states true for `src/` — 100% **by its own
tests** — which a pass that ran plugin tests could not check. The Plugins
suite still runs once in `verify`, in `coverage:plugins`.

Measured at 8 processes, alternated: all four suites **169s / 175s**, core's
three **107s / 122s** — 1,044 plugin tests no longer run twice. 100.00%
(25831/25831) either way.

*Re-opens on:* nothing here; a plugin-only `src` line is now a red gate.

## Agent-speed round 4: what the transcripts say agents lose time on (2026-09-30)

Rounds 1–3 worked from the docs and the git history. This one read the session
transcripts: the 150 newest, of which 125 were automated security reviews, leaving
**24 real work sessions** (2026-09-14 → 09-30). Counts below are out of those 24.

**Built: `composer fix` and `composer check`.** The quick gates failed 208 times
(lint 88 in 20 sessions, analyze 57, rector:check 38, format:check 25), and `verify`
was re-run three or more times in 15 sessions. The loop was one gate at a time:
`verify:quick` stops at the first red, and fixing it often reddens the next one
(rector's output needs formatting), so agents chained `rector … && mago format` by
hand 57 times. `fix` runs the two fixers in the order that leaves both clean.
`check` (`bin/keep-going`) runs every step of `verify:quick` to the end and then
names every red gate in one line. It reads the steps from composer.json, so the
chain is still defined once, and `verify` still stops at the first failure.
Measured on a scratch edit that broke format and lint together: `verify:quick`
reported format only (0.7s), and `check` reported both (8s).

**Built: a paint barrier that gives up prints the screen.** 164 E2e mismatches
across 21 sessions, and a timeout said only `waiting for X to paint`, so the next
step was always a re-run to look. `awaitPainted` and `awaitPaintedRaw` now append
the grid they had in hand. `PaintBarrierTimeoutTest` pins it, and dropping the grid
fails that test.

**Built: every golden mismatch names its regeneration variable**, and
`testing.md` lists all five. None of the five `UPDATE_*_GOLDEN` names appeared in
any doc or skill.

**Built: the arch gate names the import behind a new edge** (`Made by: A uses B`).
7 arch failures in 6 sessions were each followed by a grep for the `use` line.
All 15 mutants over the change were killed.

**Built: a lint crib in the `gates` skill.** The same rules came back in session
after session: `literal-named-argument` 68 times in 19 sessions,
`possibly-invalid-argument` 30, `mixed-assignment` 18, `no-boolean-flag-parameter`
17, and `prefer-static-closure` 15. Each has its passing shape, taken from the
tree. It went in the skill, not CLAUDE.md, which is at its budget.

**Built: an `add-setting` skill.** `IdeConfig` changed in 18 commits since August.
Each commit touched the same places, and only some of them have a guard: the
choice pin in `IdeConfigTest`, `ConfigurationDocTest`, the settings-pages golden,
and the catalogs.

**Removed: the fixture home's install debris and its excludes.** Dangling
`plugins/{demo,cloned}` symlinks, from an older plugin-install test that installed
into the shared `PORTA_HOME`, made every mago run print `Failed to walk`. That
warning showed in 49 outputs in 22 sessions, and `grep -v WARN` was typed 136
times to hide it. Current tests install elsewhere, and a full `verify` recreated
none of it. The `mago.toml` excludes and `.gitignore` lines went with the debris,
so a test that installs there again shows up as an untracked file and a mago
warning, not as silence.

**Fixed: instructions that disagreed.** `porta-explorer` told the agent to write a
log to `/tmp` and, ten lines later, never to create a file there. CLAUDE.md listed
rector beside format, but it runs fourth. It left out `test:integration` and
`test:serial`. The same-path test ratio was re-counted at 401 of 821 (still 49%).
CLAUDE.md gains one rule: never end a turn while your own background work is
pending. "continue" was typed 71 times in 19 of the 24 sessions, usually after a
status recap that offered to go on.

**Declined:**

- **Truncating `.phpunit.cache/leaks.log` per run** (55.8MB). The bootstrap runs
  once per paratest worker, so no process can know it is first, and `flake:scan`
  reads by offset. No session in the sample lost time to it.
- **A PostToolUse `mago format` hook.** It is redundant with `composer fix`, and a
  project hook runs for every contributor's session, not only the ones that want it.
- **Path-scoped lint/analyze scripts.** The six static gates take ~8s together;
  `composer lint -- path` already passes through.

*Re-opens on:* a session sample where one gate's failures dominate even after `check`
(then that gate's own messages are the problem), or E2e timeouts still followed by a
re-run to look (then the grid is not enough — capture the raw frame too).

## `AnsiSlice` removed: the vendor slices combining marks right (2026-09-30)

The 8.2.x-dev bump to symfony/tui d0adb8b fixed the `mb_strwidth()` fast paths
in `sliceByColumn`, `sliceWithWidth` and `truncateToWidth`, which is the
re-open condition on the kitty-graphics entry above. Measured before removing
the wrapper: seven marked lines (decomposed accents, niqqud, a ZWJ family, an OSC
link), every start and length, strict and not — zero differences between
`AnsiSlice` and the vendor. So the 15 callers call the vendor again, and
`AnsiSlice` and its adoption tripwire are gone. Its cases moved to `AnsiTextTest`
(through `columnsFrom` and `truncate`) and `OverlayCompositorTest`, and each one
fails against the old `AnsiUtils.php`.

**Kept: the `KittyPlaceholders` stand-ins.** The new vendor measures real
placeholders right too (eleven cells slice to 11 columns, where the old vendor
counted 33), so the stand-ins and the `MAX_CELLS` cap could go. But that changes
what the frame writer sends to the terminal, and only a real kitty-protocol
terminal can confirm the result.

*Re-opens on:* a vendor regression the moved cases catch (bring the wrapper
back), or a round with a kitty-protocol terminal to hand (drop the stand-ins).

## `mb_strwidth` vs `visibleWidth`, re-opened and converted (2026-09-30)

The 2026-08-14 "leave it alone" decision would re-open once width collapsed
ZWJ/modifier clusters. symfony/tui d0adb8b did that in `graphemeWidth()`. The
four probes, re-run: 👩‍💻 5 vs 2, a skin tone 4 vs 2, a decomposed accent 2 vs 1,
a flag 2 vs 2. The difference is now observable. With the caret just before the `x` in
`é xyz`, the editor painted it after the `x`: one column late per mark, six
after a ZWJ family. The cause was `TextMetrics`, which summed `mb_strwidth()` per
code point, while the row is sliced by the vendor. The old vendor already drew
these at 1, 2 and 2 columns, so the bug predates the bump.

**Changed:** `TextMetrics::displayWidth()`, `expandTabs()` and
`byteColForDisplayCol()` walk graphemes and take `AnsiUtils::graphemeWidth()`,
with `mb_strwidth()` kept as the fast path for text holding none of the
characters it mismeasures. The walk now lands on grapheme ends by itself, so
`graphemeEndAtOrAfter()` is gone. Every other `\mb_strwidth()` in `src` and the
plugins (popups, pickers, menus, the results panel, breadcrumbs, search ranges,
bidi tokens) calls `AnsiUtils::visibleWidth()`, the measure the vendor slices
and pads by. That also counts a tab as the vendor's three columns in search
ranges, which the painter already did. Guards: `TextMetricsTest` (width, tab
stops, byte column and the painted caret, over five cluster classes),
`ProjectSearchTest` for both backends, and `BreadcrumbsWidgetTest`. Each one fails
on the old measure.

**Left:** the git diff view (`DiffViewRenderer`, `DiffViewWidget`). Its docblock
says a tab counts as one column across its scroll, truncation and click mapping,
but it slices through the vendor, which counts three. Whether that holds needs a
diff-view test of its own before its widths change.

*Re-opens on:* a vendor `graphemeWidth()` change (re-run the four probes against
`TextMetricsTest::clusters()`).

## The diff view paints tabs at the editor's stops (2026-09-30)

The follow-up left open above. The diff view's docblock said a tab was one
column "everywhere". But the row went to the terminal with the raw tab in
it: the vendor counted three columns and the terminal jumped to its own
eighth-column stop, so any tab-indented diff could run past its pane width.
The emphasis was placed by `mb_strwidth()` as well. Probed on `xx NEW tail`
behind each prefix: behind a tab it covered `x N`, behind a decomposed accent
`EW `, behind a ZWJ family `il`.

**Changed:** `DiffViewRenderer::shown()` expands tabs with
`TextMetrics::expandTabs()` at `Document::TAB_SIZE`. Everything painted goes
through it: the highlighter's input, deletions and the no-newline marker.
`columns()` measures that same text. Emphasis ends are both measured from
column 0, because a tab's width depends on where it starts. The scroll limit and
the selection tint's end use `columns()`, and a column maps back to a byte with
`TextMetrics::byteColForDisplayCol()`, which replaces the widget's private
copy. `DiffLine::text` keeps the tab, so copies and staged patches are
byte-exact. This adds a new namespace edge, `GitPlugin>Editor`.

Guards in `DiffViewWidgetTest`, each failing on the old view or on a hand
mutation back to `mb_strwidth()`: no row carries a raw tab and every row is the
pane's width; the emphasis lands on `NEW` behind each prefix; a drag across a
tab copies the raw tab and tints the row through its painted end; the scroll
reaches the end of a line indented with twelve tabs.

## No raw tab reaches the terminal (2026-09-30)

The diff view was not the only row carrying a raw tab. A search hit is
trimmed at its ends, but a tab inside the line survived into the results
panel's row, the same way. Rather than chase each widget, the frame write in
`BidiTui` now turns every tab into `AnsiUtils::TAB_WIDTH` spaces, the width
every vendor measure had already counted it as. This happens after every
splice and measurement, next to the kitty placeholder expansion. So what the
terminal draws is what the layout measured, whatever widget painted the tab.
The diff view still expands at the editor's stops first, because its columns
map to bytes. `BidiTuiTest` checks the written bytes; without the replacement,
the raw tab reaches them.

## The kitty stand-ins stay, for speed (2026-09-30)

The `AnsiSlice` entry above left the `KittyPlaceholders` stand-ins waiting for
a kitty-protocol terminal, because the vendor now measures real placeholders
right. They do not need one: the measured cost decides it. The two marks per
cell put every slice of an image row on the vendor's per-grapheme walk. A
200-cell row inside a styled line, sliced twice and measured once: 55 µs with
stand-ins, 2.0 ms with real placeholders. A pane of such rows under an overlay
would spend over 100 ms per frame. The bytes double as well (820 vs 1712).

*Re-opens on:* a vendor fast path for runs of U+10EEEE placeholders, or a
`MAX_CELLS` cap (239 cells a side) that a real pane outgrows.

## The live preview split (2026-09-30)

Markdown, SVG, DOT and PlantUML files open as TEXT, with a live preview beside
them. Before, SVG and the diagrams opened in a read-only viewer, and "Open as
Text" was the only way back to the source. Markdown had no rendered view at all
outside the help browser. The toggles at the breadcrumbs bar's right end and
`Ctrl+Alt+V` switch between Editor, Split and Preview. The divider drags, and
Shift+F11/F12 nudges it. `.porta/layout.json` keeps one share for every
previewed file.

**Not the editor splits.** Editor splits were measured and declined. The
reason: `CodeEditorWidget` is one shared service with 25 constructor
injections, all meaning *the* editor. That decline was recorded only in agent
memory until now. The preview split needs none of it. There is one editor, one
active buffer and one focused-editor model; the right half is an ordinary
`EditorPaneWidget` fed the buffer's text. A split of two TEXT views would still
reopen that measurement.

What it took, and why each piece is shaped as it is:

- **Routing.** `PluginApi::registerPreview()` sits beside `registerViewer()`,
  on the same `ViewerProviderSource` seam, because both answer one question for
  one consumer. `Editor\PreviewRoutes` adds core's markdown claim behind the
  plugins'. A preview claim is asked first, and only for a file small enough to
  edit (2 MB), so a viewer claim still takes a file too large for the editor.
  The bundled plugins keep both claims for that reason.
- **The slot.** `Layout\PreviewSplit` sizes its two sides by FLEX WEIGHT,
  never by a width. A fixed side would be `flex: 0`, and the layout engine
  measures an intrinsic side by rendering it — the editor, twice a frame, its
  cache cleared in between. Weights cost nothing, hold across a resize and are
  exactly the persisted value. Below 41 columns the split shows the text alone
  and keeps Split as the arrangement.
- **The feed.** `Editor\PreviewFeeder` watches `Document::$version` from the
  frame hook, the `StructureFollowsCaret` shape. No event covers every
  mutation: typing, a formatter, an LSP edit, undo and a reload all move the
  version. A trailing 0.15 s pause means a burst of typing costs one render.
- **Focus.** `EditorArea::widget()` is whichever half has the keys;
  `entry()` is where they land when the area is entered anew (the text, unless
  the preview is alone). The status bar speaks for the focused half, so a
  graph's node count shows once the graph is clicked.
- **A lone preview hands the text back.** The pickers and the unsaved-changes
  list composite over the EDITOR, which Preview detaches. They switch the tab
  to Split first rather than open over a widget nothing draws.

**`BufferService`'s pane-swap cluster was extracted, reopening a settled row.**
The extraction audit rejected it at one exclusive dependency. The split gave
it a second (`PreviewSplit`, beside `editor_body`). Kept in place, it also made
a new `Editor>Layout` edge and pushed `BufferService` past the kan-defect
ceiling of 8. It is `Workbench\EditorSlot` now. That is the audit's own reopen
condition, a changed dependency count on one candidate, not a fresh sweep.

**Alt+P could not be the key.** The legacy encoding of Alt+P (`ESC p`) is what
the key parser reads as Alt+Up, so it ran Previous Declaration. Ctrl+Alt+P is
the psalm plugin's. Ctrl+Alt+V is free and encodes cleanly (`ESC ^V`).

**The markdown preview reads like the documentation.** A request followed the
first version: the same navigation bar, links, history and find as the F1
browser. The whole help page became `Widget/MarkdownPageWidget`, and the help
browser and the preview extend it. That is not the extraction the audit turned
down. The audit weighed pulling the history stack (0 of 6 exclusive
dependencies) and the FindTarget adapter (1 of 7) out of the help widget into
collaborators, and the numbers still say no. Here the whole page gained a second
page that needs all of it. The two differ in their tree and in where a link
leads, and the handler already decided the second: `MarkdownPreviewActions`
opens the linked file's tab, whose preview is the same pane, so Backspace walks
back through files. The five tab verbs both handlers delegate are one trait.
Found on the way: core panes were never handed a tab handler, so Ctrl+Left/Right
and Alt+W did nothing on the large-file look. `CorePanes` hands it now.

*Re-opens on:* a request for two text views of one file, which is the editor
split and its 25 injection sites; or a preview whose render is too slow for the
0.15 s pause, which would need the pause per pane rather than one constant.
