# Decision history, part 4

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## The sweep, and what it found on its first run (2026-09-09)

Three defects in one session shared a shape: a width chosen against the English
screen, and content that later grew past it. The nav's floor was 14 while
French spells Keybindings `Raccourcis clavier` (19). The keybindings header
lost `Ctrl+F to filter` when a fourth hint went in. The status bar dropped the
FILE NAME at 100 columns in English and 140 in German. Each was found by
rendering a screen and looking at it, which is not a thing that happens on
every commit.

`ChromeSurvivesEveryLocaleTest` does the looking: four chrome surfaces, all
seven shipped locales, six widths from 60 (below `COLLAPSE_BELOW`, where the
sidebar folds and the IDE is still meant to work) to 140. 422 assertions in
5.4 seconds.

**The assertions are locale-INDEPENDENT on purpose.** Comparing against an
English string is the assumption under test, so they ask structural questions
instead — is the element still there, and if it did not fit, does the row say
so. Menu titles are read off the widget itself. `Ctrl+F` is Latin in every
catalog. The fixture file is named so its tail is unmistakable.

The rule the whole thing encodes: **a surface may truncate; it may not silently
drop.**

### It found a fourth on its first run

At 60 columns the German and French menu bars end at Ansicht / Affichage, and
the row read as though Hilfe / Aide did not exist. They were never
unreachable — `menu_left`/`menu_right` cycle the whole list, not the drawn part
— so what was missing was any sign the row had been cut. It carries the
ellipsis now, and the mark is paid for out of the TITLES: a row filled to its
own edge has nowhere left to say it was cut, and taking the column from the row
instead renders 13 columns into a 12-column pane, which is the over-wide line
that garbles whatever sits beneath it.

### And two things the sweep corrected about itself

The first version rendered the settings nav through a narrow `RenderContext`
and asserted no row was elided. It passed for the wrong reason: the widget
renders at `min(its own width, the context)`, so a bare context measures the
default 22 and says nothing about the floor. It calls `setWidth()` now, and
asserts the width took.

The first attempt to prove the header assertion could fail mutated the English
source string in the widget — which changed the trans ID, missed the catalog
entirely, and made every locale render the English header. A mutation that
breaks the lookup is not a mutation of the thing under test. The honest one
reorders a catalog TARGET, and the gate does catch that.

Japanese needed one real change to pass at 60: `%count% 件のアクション` became
`%count% 件`, which is idiomatic and fourteen columns shorter. Every locale now
closes `Ctrl+F` by column 55, comfortably inside the narrowest supported width.

## "Module" that quietly means everything (2026-09-09)

`search-prefs.json` on this machine says `"scope": "module"` — the user's
standing Find-in-Files scope. What `Module` resolves to is the nearest ancestor
of the current file holding a `composer.json`, **or the whole project when
there is none**, and the selector said `Module` either way.

In this repository that fallback is the common case, not the edge one. The
packages live under `plugins/`, so editing anything in `src/` gets it: the
scope reads `Module` and searches everything. Editing
`plugins/git/src/Widget/…` gets `plugins/git`. Same word, two completely
different searches, and no way to tell which one is about to run.

The built-in labels say where they land now:

    Module (plugins/git)      Module (Whole Project)      Directory (src/Deep)
    Open Files (3)            Open Files (0)

`Whole Project` and `Current File` are left alone — they name themselves
exactly, and appending the path would be repeating the label back.

`Open Files (0)` is the same defect from the other side: with nothing open that
scope matches nothing at all, and a search returning no results because its
scope was empty looks exactly like one that searched properly and found none.

Reusing the existing `Whole Project` id rather than minting a new "whole
project" string: it is already in all seven catalogs, and one more literal
would need `en` plus six targets in the same commit for a parenthetical.

Safe by construction on the persistence side — `search-prefs.json` stores the
KIND and the selector index comes from `array_search($kind, BUILT_INS)`, so the
label text is display-only and could not shift a saved choice. The modal's box
clamps at 80 columns and the label elides inside it, so a deep package path
widens the modal at most to that.

## The rule column was the first thing cut (2026-09-10)

`483b8999` put the rule that fired at the END of a Problems row, and
`d24e1135` wrote down why: *"At the end rather than in front, because the rule
is what you search for to suppress it or to tell two identically-worded
findings apart; the sentence is what you read the row for."*

Both true. But the end of a row is the first thing a narrow panel throws away,
and the row is `path:line  message  rule` — so a realistic mago finding loses
it at 100 columns:

    ● Unused import: this `use` statement imports a symbol that is never refe…

The rule fails exactly where the docs say it earns its keep: **long messages
are the ones where a rule name is the only thing telling two findings apart.**

The placement was never the problem, so it has not changed. The rule travels as
its own `tail` field instead of concatenated onto the message, and the panel
reserves its width and elides the SENTENCE. Below a 24-column floor for the
message the row goes back to the ordinary whole-row cut — a rule name with no
sentence beside it names nothing anyone could act on.

### The shape this exposed

`array{path: string, line: int, text: string, ranges?: …}` is written out by
hand in **eighteen files**. Adding one optional key meant editing fourteen
declarations across eight of them before the analyzer went quiet, and the only
thing keeping them in agreement is that somebody notices.

Every other row in this codebase that travels between subsystems is an object —
`DisplayRow`, `SearchHit`, `KeybindingRow`. This one is a raw array because it
started as a grep result and grew consumers. Not refactored in that commit: it
is a mechanical change and a defect fix should not carry one.

**And "a small readonly class" turned out to be wrong** — see the entry below.

## The other list that scrolled in silence (2026-09-10)

The menu bar was made to mark its overflow this morning under one rule: **a
surface may truncate, but it may not silently drop.** That rule has a vertical
half, and only one widget in the IDE was keeping it — `TabBarWidget`, which has
drawn ▲/▼ edge rows since it was written. Everything else that scrolls drops
in silence.

Most of them are fine that way. The keybindings list is two hundred rows with
its count in the header and a filter. The results panel carries `N results` in
its status. The file tree is unbounded by nature and nobody expects to see all
of it. What they share is that the reader already knows the list is longer than
the window.

The settings nav is the one that is not like that: five sections, one indented
row per installed plugin, then the language catalogue — a finite, complete menu
of destinations, with no count anywhere. With nine plugins that is 28 rows, and
on a 24-row terminal the last six were simply not there, indistinguishable from
a list that ends where it appears to.

It reserves the two edge rows now, and only while it actually overflows, which
is the same trade `TabBarWidget` makes. A reserved ROW rather than a marker
column, because this widget's floor is measured to the column: a marker in the
last cell would come straight out of a name, which is the defect its own
`minWidth()` was fixed for earlier today.

The indicator draws unconditionally and the caller picks between it and a
blank — the sibling's shape, and the one that keeps mago's
`no-boolean-flag-parameter` quiet without an expectation comment.

**Not swept further on purpose.** The rule is not "every list gets arrows"; it
is that a list whose content the reader takes to be complete has to say when it
is not. Applying it to the keybindings list or the results panel would add
chrome to lists that already answer the question in words.

## One row shape, and the two ways it refused to become a class (2026-09-10)

The previous entry said the fix for fourteen hand-copied declarations of the
results-row shape was "a small readonly class, like `DisplayRow` or
`SearchHit`". Tried it. It cannot be one.

**`PluginApi::showList()` takes that shape.** It is part of the published
plugin API, so every plugin that builds rows builds plain arrays. Turning it
into a class is a breaking change to code this repository does not contain.

So it stays an array and stops being fourteen arrays: `@phpstan-type ResultRow`
lives once on `Porta\Support\ResultRowShape`, and every consumer does
`@phpstan-import-type ResultRow from …`. mago resolves it — checked before
committing to the sweep, and checked again afterwards by feeding a wrong shape
to an imported signature and watching it fail. No runtime change, no new arch
edges: the annotation carries the FQCN, so not one `use` statement was added.

Three things that had to be got right, none of them obvious:

**The holder cannot share the alias's name.** `final class ResultRow` holding
`@phpstan-type ResultRow` collides: the `use Porta\Support\ResultRow` wins and
every import silently resolved to the empty CLASS. Analyze went from clean to
noisy on unrelated lines, which is what gave it away. The holder is
`ResultRowShape`; the alias is `ResultRow`.

**The annotation belongs in the class docblock, above the attributes.** Written
between `#[AsWidget(...)]` and `final class`, it is a floating comment and not
the class docblock at all — mago reports `Cannot find … Porta\Widget\ResultRow`
and the alias resolves to nothing.

**A class with no members is the point, not an oversight.** `ResultRowShape`
carries no code because it cannot; the docblock explains that so the next
reader does not "clean it up".

*Re-opens if* `PluginApi` ever versions its row API, at which point the class
this wanted to be becomes possible.

## Infection over the session's own thirteen commits (2026-09-10)

Ran the project's own review tool across everything changed since
`3a55f8cb` — 309 mutants over 24 files, **259 killed, 47 escaped, MSI 84%**.
Read as a review rather than a score. Four of the 47 were real.

**`elideMiddle` was graded in one direction only,** which is the exact defect
this session committed a fix for twice (the doc gate, the catalog gate). The
sweep asserted `visibleWidth(...) <= $width` across widths 2–30 — and every
arithmetic slip in that function makes the result NARROWER, so all of them
passed. It is graded from below now, and an ASCII case pins the width exactly.

Tightening it immediately disproved my first guess at the bound. I wrote
`>= $width - 1`, reasoning that one cut could straddle a double-width grapheme;
width 16 came back 14. **Both** cuts can straddle, and each one that does costs
a column. The comment records the wrong number and the case that killed it.

**The settings nav's overflow test never came near equality.** `count($entries)
> $rows` with `>=` reserves two edge rows to announce an overflow that does not
exist — dropping two entries that fit in order to say the list is longer than
it is. Nothing tested exactly-fits.

**Delete with no reset callback wired dereferenced a null row.** The guard is
`null !== $row && null !== $this->onReset`; with `||` it is a fatal, and the
mutation escaped because every test wires both. Callbacks on that widget are
installed after construction, so "not wired yet" is a real state.

**The rule column's two-space gap was unasserted.** Removing the separator runs
the sentence and the rule together as one word, and
`assertStringContainsString('worse.unused_import')` passes either way. Asserted
against `…  worse.unused_import` now, cut and gap together.

### The other 43, and why they stay

Checked, not waved past. The bulk are the centring arithmetic in the ▲/▼
indicator rows (`intdiv($width, 2) - 1`) — moving the glyph a column is not
behaviour any test should freeze. `MenuBarWidget`'s `break` in
`fittingTitles()` is a provably equivalent mutant: `$used` only grows, so once
it passes `$columns` the counter is never reached again whether the loop
breaks or not.

The lesson worth keeping is not the 84%. It is that **three of the four were
one-way assertions** — a bound with no floor, a comparison never tested at its
boundary, a callback never tested absent — written by someone who had spent
the same day fixing exactly that shape in two gates.

## What mutating the PLUGINS costs, and what stops it (2026-09-10)

`infection.json5` says `"directories": ["src"]`, so no plugin has ever been
mutation-tested. The note in `porta-mutation-scope-src-only` records the
consequence — a green `composer mutate` says nothing about `plugins/` — but not
whether the gap is worth closing. Measured now.

**The recipe works.** Infection needs a coverage report that contains plugin
files, and the parallel run cannot produce one (the `<source>` block in
`phpunit.xml.dist` explains why). The serial plugin pass's own flags do:

    php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=#/(vendor|tests)/#' \
        vendor/bin/phpunit --testsuite=Plugins --coverage-filter plugins \
        --coverage-xml=… --log-junit=…

Without `pcov.directory=.` the report holds `src/` only and Infection reports
`0 mutations generated / MSI 0%` — which reads exactly like "nothing to fix"
and is worth recognising, because it is the failure mode this whole exercise
started from.

**What it found: nothing to fix.**

- `symfony-lsp` — **437 mutants, 437 killed, MSI 100%.** The plugin changed
  most in this session, and its own tests are stronger than anything core got
  today (84%).
- `todo` — 12 mutants, one survivor, and it is equivalent: dropping
  `array_values()` around an `array_filter()` whose result only meets
  `implode()` and `[] === $parts`, both indifferent to list-ness. The call is
  typing, not behaviour.

**What stops it being routine.** Infection 0.35.4 aborts mutant generation on
`plugins/git/src/Widget/GitPaneKeys.php`:

    Expected an instance of PhpParser\Node\Stmt\Class_. Got: …\Stmt\Trait_

It is a trait composing a trait — but that alone is not the trigger:
`src/Widget/Support/ListPanelInteraction.php` has the same shape and mutates
fine (128 mutants generated in that directory). The difference is that
`GitPaneKeys` composes `PaneNavigationCallbacks` from `src/`, outside the
configured source directories.

**`--dry-run` never reaches that step**, which cost the best part of an hour:
every plugin dry-runs clean, and `git` aborts the moment it is run for real. A
dry run is not a check for this.

No `composer mutate:plugins` script, deliberately: one that aborts on the
largest plugin is worse than a documented recipe. *Re-opens if* the upstream
`Trait_` abort is fixed, at which point the recipe above is the script.

**And a live trap in `composer mutate` itself, checked and NOT found.** The
obvious worry was that the same abort waits in core the day someone edits a
trait — `composer mutate` is always diff-scoped, so it only ever parses changed
files, and today's diff happened to contain none. Ran the whole of
`src/Widget/Support` (traits included) for real: no abort. The trigger needs
the cross-tree composition, so core is clear.

## The one toggle that could not warn (2026-09-10)

Went looking for settled decisions whose recorded *re-opening condition* had
since been met — a stale condition is the same defect as a stale justification,
and it costs the next reader a round. One had: "Refusing a write the other
layer overrules" says it re-opens if the settings screen gains a way to edit a
scope other than the one in effect, and {@see SettingsScopeRow} is that. The
decision survives anyway, for a reason that was not written down — switching to
Project scope to store a PERSONAL default writes it into a file that travels
with the repository — so the entry says so now rather than inviting the same
round again.

Checking its other claim found a real gap. It says **every** path that can
write into a shadowed key warns and proceeds — "a setting, a reset, a plugin
toggle, a rebind". The plugin toggle could not:
`ShadowedWriteWarning::warnIf()` reads `origin`, and
`SettingsPluginsController` was the only settings controller that set it on no
row at all. With `origin` null the warning returns early, always.

That matters most on precisely this row. The two plugin lists merge
differently, and CLAUDE.md's settled list singles the asymmetry out:

- `disabledPlugins` **unions** — both layers saying "not this one" agree, so a
  global disable always lands and can never be shadowed.
- `enabledPlugins` is **replaced whole** by the nearest layer that has it.

So flipping a BUNDLED plugin on from Global scope, while the project has an
`enabledPlugins` list — which this machine's `.porta.json` does, with nine
entries — wrote correctly to the user file, was discarded entirely by the
project list, and said nothing. Every other row on every other page would have
warned.

The toggle carries `origin` now, and only for bundled plugins: an installed
plugin's toggle writes `disabledPlugins`, and tagging that would claim a
conflict that cannot happen. Both halves are mutation-checked — dropping the
provenance fails, and claiming it for installed plugins fails too.

Nothing new was needed to say it: the existing funnel in
`SettingsViewController::afterWrite()` already routes every form edit through
`ShadowedWriteWarning`, and the row was the only thing missing.

### And the arch gate earned its keep

The test fixture wanted a temporary directory of bundled plugins, and the first
version chained off `new TempProject()->dir`. `composer arch` failed it by
name: the temporary is freed — and its directory removed — before the installer
ever globs it. That check exists because the trap has been hit before; this is
the first time it caught it before a flake did.

## The scopes the docs never mentioned (2026-09-10)

Auditing the re-open conditions (previous entry) meant reading the settled list
closely, which led to reading the user docs the same way.
`docs/search-navigation.md` described `Alt+S` as "Whole Project or saved
scopes" and stopped. Four of the five built-in kinds were undocumented —
Directory, Current File, Open Files and **Module**.

Module is the one that matters, and it is the one this machine has persisted as
its default. A reader never told the scope exists has certainly never been told
that it searches the WHOLE PROJECT when nothing above the file holds a
`composer.json` — which, in a repository whose packages live under `plugins/`,
is what it does for everything in `src/`.

The doc has a table now, one row per kind, saying what each searches. And a
gate, because nothing else reads prose.

### The gate's first version proved nothing

It asked whether each `ScopeKind::label()` appeared **anywhere** in the
document. Renaming the `Module` table row to `Modul` left it green: the word
still occurs in the sentences around the table. A vocabulary check against a
whole document is not a check that the vocabulary is described.

It reads the `| Scope | Searches |` table now, asserts one row per enum case
and no more, and that no row is empty. Three doc mutations kill it: rename a
row, delete a row, empty a row's description.

That is the third time in two days that a first-draft assertion has been
one-way — anywhere-in-the-file, `<=` with no floor, a comparison never tested
at its boundary. The pattern is asking a question the defect can answer
correctly.

## Eight widgets that had never been squeezed (2026-09-10)

`search-history.json` on this machine reads `["nb", "mb_", "mb", "co"]` —
someone auditing multibyte width calls, which is the family this whole session
has been fixing. Finished the audit.

**The `mb_strwidth` sweep came back clean.** Seventy call sites; one measures a
value that is also styled elsewhere, and it is right by construction —
`KeybindingsListWidget` measures the PLAIN `$shortcut` while drawing the styled
`$right`, and SGR adds no visible width. Measuring the plain value is the safer
of the two.

**The gate behind it was half-applied, and that is where the defect was.**
`RendersExactWidth::assertFitsEveryWidth()` renders at every width from 1 to 20
and asserts no row exceeds the pane — the guard against the over-wide row that
garbles the line beneath it. Seventeen widgets carry `#[AsWidget]`; **eight had
never been through it**, including both settings panes.

One of the eight failed: `KeybindingsListWidget` drew **eight columns into a
one-column pane**. The row is `label … shortcut`, and only the LABEL yields —

    $labelWidth = max(0, $width - $rightWidth - 2);

so once that floor is reached the row is still `' ' + shortcut + ' '` wide,
whatever the pane. `Ctrl+Alt+Shift+C` is sixteen columns; any pane narrower
than eighteen got a row wider than itself. The composed row is clamped now, a
no-op at every width where the label has room.

The other seven fit. All eight are in the sweep's provider now, so the next one
fails a build instead of garbling a screen.

### The vacuity guard earned its keep on the way in

`DockTabsWidget` with no provider renders zero rows, and a loop over no rows
asserts nothing while reporting success. The sweep refuses that explicitly, and
it refused my first fixture by name. The lesson it encodes is the same one this
session keeps relearning from the other side: an empty result satisfies almost
any assertion you can write about a result.

## Doc examples that nobody was checking (2026-09-10)

Swept the user docs against the surfaces this session changed, the way
`search-navigation.md` turned out to need. Two were under-described, both in
`docs/editor.md`: nothing said what happens to two open files with the same
name, and the Problems panel's rule column was documented as sitting "at the
end" without saying that being at the end no longer makes it the first thing
dropped.

Both now carry worked examples — and a worked example is the part of a doc a
reader trusts most and the part nothing checks. `plugin.php (git)` is a promise
about a format, and the format lives in `TabListProvider`.

`TabNamingDocTest` executes the promise: it builds the buffers the doc
describes, asserts the provider returns exactly the strings printed there, and
asserts the doc still contains them. Both directions were mutation-checked —
changing the format to a prefix fails it, and editing the doc's example to
`(symfony)` fails it too.

That is a different question from the one the other doc gates ask.
`KeybindingsDocTest` and `SearchScopeDocTest` ask whether a vocabulary is
MENTIONED; this asks whether a demonstration is still TRUE, which is the
failure mentioning cannot see.

### Checked and left alone

`.porta.json` is gitignored and untracked — the dead
`plugins.symfony-lsp.binary: ""` key left by the relaunch trick removed in
`55acea0e` is in the user's personal config, not the repository's, so it is
theirs to clear and not mine.

## Drag and drop in the file tree (built 2026-09-10)

The tree had no drag at all: a left press was consumed by `tryChromeClick` and
armed nothing, so the `isLeftDrag()` that followed matched no `continue*`
handler, fell out of `if (!$mouse->isLeftPress()) return;` and was **silently
dropped** — as was the release. Moving a file meant `Rename…`, which is
confined to `dirname($old)`.

PhpStorm's semantics, as asked: drop onto a folder to move, drop onto a FILE to
land in its folder, drop on the editor to open, drag a selected row to carry
the whole selection, and a name clash asks rather than refuses.

**Almost none of it is new code.** `TreeOperations::treeRename()` already had
the load-bearing sequence — resolve the old path and collect the buffers under
it BEFORE the move, rename, re-point, dispatch `FileRenamed`, refresh — and
none of it was rename-specific, so it came out whole as `moveInto()`. The seven
path-keyed stores and local-history then follow a drag-move for free, which the
E2e asserts by watching a bookmark arrive at the new path.

### Three decisions worth the words

**`Alt` copies, not `Ctrl`.** PhpStorm uses Ctrl, but Ctrl+click on a tree row
already means "add to the selection" and Shift means "extend to it", so that
chord would decide two things in one gesture. The modifier is read from the
RELEASE, so the chord that starts a drag never picks the outcome.

**A folder's expand/collapse now waits for the release.** Pressing a folder to
drag it used to toggle it, and that is not cosmetic: the rows below move, so by
the first motion event the row under the pointer is a different node and the
drop lands somewhere nobody aimed at. `pressRow()` defers only the toggle —
a file still activates on the press, which is this tree's documented behaviour
— and the release pays it when nothing moved. The shape is the graph pane's
`$paneDrag`, where a release with `moved === false` becomes a click. Found by
building it: the first version's folder-drop test failed because `src/` had
collapsed under the pointer.

**The drop is an event, not a call.** `MouseRouter` is the class both of mago's
ceilings are bisected against, so injecting `TreeOperations` into it broke
`excessive-parameter-list` outright. The tree reports `TreeDropRequested` — a
command with exactly one listener — the way `DockTabsWidget` reports
`DockTabMoveRequested`, and the drag itself lives in `Input/TreeDragRouter`,
reached the way `WelcomeMouseRouter` is. What is left in `MouseRouter` is one
entry in the `||` chain and one `ctrl || shift`: +2 cyclomatic, +1 parameter,
both recorded in `mago.toml` beside the numbers they moved.

### Not fixed here

- ~~**Unsaved changes are still lost on any move.**~~ **Fixed straight
  after** — see below. It was pre-existing, but shipping a casual gesture over
  a path that drops work made it this change's problem.
- ~~**Git decorations go stale after a move**~~ — **fixed**, see below. The
  cause was not the missing listener; it was core never speaking at all.
- ~~**The language server keeps the old URI open**~~ — **fixed**, see below.
  `workspace/didRenameFiles` is still not sent; that is a feature, not this bug.
- **Moves are not undoable**, which is settled — `WorkspaceEditor` calls
  `RefactoringUndo::decline()` for any create/rename/delete. The collision
  prompt is the safety net instead.

## The move that ate your edits (2026-09-10)

Recorded as "not fixed" an hour earlier and then fixed, because drag-and-drop
turned a deliberate act into a flick of the wrist.

A move closes each affected buffer and re-opens it from disk.
`BufferManager::close()` has no dirty check — the guarded Save/Discard/Cancel
prompt lives only in `closeActiveBuffer()` — so anything typed and not saved
was simply gone. The tree's `Rename…` has always had this; nobody hit it often
because renaming is something you stop and think about.

Reproduced first, as a failing E2e: type into a file, drag it to a folder, read
the moved file off disk. It contained the original bytes.

**Saved, not asked.** "Discard" is meaningless here — it would throw the work
away and move the file anyway — so the only answer that loses nothing is to
flush first, which is what PhpStorm does. It goes in `moveInto()`, the choke
point both the drag and the context-menu rename now share, so the older path is
mended by the same three lines.

The copy path saves too, for a subtler reason: a copy reads from DISK, so an
unsaved edit would be missing from the copy while still sitting in the
original — the same surprise wearing a different face.

### And the class hit mago's inference budget

Analyze started failing on `foreach ($inside as $path => $target)` — code this
change never touched — with `mixed-argument` on the two calls inside it.
`TreeOperations` had grown past mago's per-class inference budget, and past it
every local is inferred `mixed` and the complaint lands on the CALLS rather
than on the size that caused it. Pinned with a `@var` pair and a comment saying
which it is, because the next reader will otherwise try to fix the calls.

*Re-opens if* the class grows again: the honest fix at that point is to move
the drop's five methods out, not to add a third annotation.

## Telling the language server a file is gone (2026-09-10)

The second of the four items drag-and-drop shipped over, and the last of them
that was actually a defect.

A move re-points the buffer — close the old path, open the new one — but only
the second half ever reached the server. `didClose` was sent from the two
tab-closing paths in `BufferService` and from nowhere else, so a rename, a move
or a delete left the server holding a document at a URI with no file behind it.
Everything it had indexed from that copy stayed live: the class defined twice,
"go to definition" offering a path that no longer exists.

**A path listener, not a call in the mover.** `FileRenamed` and `FileDeleted`
already have six listeners in that family — bookmarks, breakpoints, recent
files, search scopes, edit history, jump history — each retiring or re-keying
one path-keyed store on the same two facts. The session pool is a seventh such
store, so `Lsp/LspPathListener` is the shape, and it adds no namespace edge:
`Lsp>Event` and `Lsp>Support` were both already allowed.

**Every session, not the one the path routes to.** `src/Old/` can hold a `.php`
and a `.ts`, which live in different sessions, and after the move the old path
routes to no server at all. `LspSessionPool::notifyDidCloseUnder()` therefore
asks all of them and lets each answer from its own open set — `LspSession`
iterates `$syncedText`, which is exactly "the documents this session opened", so
a path the server was never told about is never announced as closed either.

### Proved through the log channel

The mock server now echoes every `didClose` as `window/logMessage`, which lands
in `LspLog` and repaints nothing. `showMessage` was the other option and would
have flashed into every test that closes a tab with the mock running. The E2e
asserts what the server was **told**, not what this side believes it sent —
and it starts by asserting the list is empty, so it cannot pass by accident.

### One mutant survived, and it was right

The guard was written `$path === $root || ProjectPaths::isUnder($path, $root)`.
Removing the first arm changed nothing: `isUnder` is reflexive, so the explicit
file case can never be the only true half. Dead the moment it was written, and
invisible to coverage — the line ran every time. It is one test now, with the
reason in the docblock so the next reader does not add it back.

*Re-opens as a feature, not a bug:* servers advertising
`workspace.fileOperations.didRename` want the rename itself, not a close and an
open — that is how a server updates imports for you. Needs capability
negotiation, so it is its own change.

## Core was the one producer of working-tree changes that never spoke (2026-09-10)

The last of the four items drag-and-drop shipped over, and the diagnosis I had
written down was wrong.

I had recorded it as "nothing listens to `FileRenamed`", which suggested adding
a listener in the git plugin. Tracing it properly said something else: the file
tree's marks come from a whole-repository `git status`, refreshed on a save, a
commit, a stage, a stash, a pull — and every one of those triggers is a git
verb. `WorkingTreeChanged` exists precisely so a producer core knows nothing
about can keep the marks honest, and core, which creates, moves, copies and
deletes files all day, had never dispatched it once.

So it was never one missing listener. It was five missing announcements, and
they all leave through the same door: `TreeOperations::withFs()`, the wrapper
every filesystem verb in that class already runs inside. One dispatch there and
new file, new folder, move, copy and delete are all covered.

**In `finally`, not after a successful call.** A mutation that raises partway —
three of five files moved, then a permission error — has still changed the tree,
and asking git is how anyone finds out what actually landed. The no-op cases
(an empty name, a rename to the same name) never reach `withFs` at all; they
return at their own guard.

### The event's meaning, restated rather than widened

`WorkingTreeChanged`'s docblock listed only VCS verbs, which is why nobody had
thought to dispatch it from core. The list was a set of examples that had
hardened into a definition. The event is defined by what its LISTENER owes it —
"the tree moved, recompute" — not by who moved it, and a file created in a
repository changes what `git status` says exactly as much as `git add` does.
Both the class docblock and the plugin event table in `docs/plugins.md` now say
so, because the table is the contract a plugin author reads.

*Re-opened the same day, by reading the change's own blast radius* — see
below. The reasoning above is right for ONE file and wrong for twenty.

### The other silent producer, found and fixed in the same breath

`WorkspaceEditor` — the LSP-driven create/rename/delete/rewrite — had it too. I
started to record it as "not reproduced, so not fixed", then noticed the
reproduction was a unit test with a real dispatcher and two minutes' work.
Deferring something that cheap is not discipline, it is bookkeeping.

Same shape, different door: one announcement for the whole edit, because a
refactoring may rename twenty files and twenty `git status` runs answer the
same question. The guard is `!$outcome->isRefused()` — the one case where
silence is right, since `refuseUnhonourable()` returns before any operation
runs, so there is no half-applied state to ask about. Both halves of that guard
were mutation-checked: announcing unconditionally, and not announcing at all,
each fail a test.

## Mutating the round's own work (2026-09-10)

Infection over everything this session put in `src/` — 730 lines across four
commits — `--git-diff-lines` against the round's last commit. 100 mutants, 84
killed, **16 escaped**. Read as a review, not as a score.

Four of the sixteen were **one-way assertions in tests written this same
session**, which is the recurring self-inflicted defect and worth naming again:

- `closeUnder()`'s fixture opened every matching file FIRST, so `continue`
  → `break` closed everything the test asserted on before it stopped. The two
  non-matching paths are opened first and in between now.
- "released over nothing" asserted no MOVE and never asked whether anything had
  been OPENED — and removing that early return opens every dragged file.
- "a folder dropped on the editor opens nothing" used a set of one, so skipping
  the folder and giving up at it were the same answer. It is a mixed set now,
  folder first.
- `pressRow` on a file asserted only that nothing was deferred, which is equally
  true of a press that does nothing at all.

The largest finding was a whole **unguarded precedence chain**: the file tree's
row-style `match(true)`. Every arm of it is a style and nothing else, so the
plain text is identical whichever fires and a stripped comparison grades all of
them as passing. Only the arm added this session had a test — the one written
after the same trap caught me an hour earlier. Five arms now, pinned by
rendering one row in five states and requiring five different strings, plus a
directory row that must be styled with a file row in the same state as the
control.

Also found: `setDropTarget()`'s no-change guard held nothing, and mode 1003
hands the router a flood of motion events, so losing it re-renders the whole
tree dozens of times per drag for a picture that never changed.

**16 → 5, covered MSI 84% → 95%.** The five left, each judged:

- `TreeDragRouter::drop()`'s `return` after `requestDrop()` — unreachable by
  construction: the target only exists when the release was inside the TREE
  rect, and the tree and the editor do not overlap.
- `$this->tui->requestRender()` in `continueDrag()` — the line is right and
  every sibling drag handler has it, but **no test can attribute the repaint**:
  the loop's ticker paints within an E2e await either way. I wrote a test for
  it, watched the mutant survive it, and deleted the test rather than keep one
  that passes for a reason it does not name.
- `FileTreeWidget::nodeAtRow()` public → protected — Infection noise on a final
  class.
- The two `$this->events?->` nullsafes — the class's own defensive idiom for a
  widget built without a dispatcher; no production path does.

*Re-opens if* a Unit-only mutation run is ever mistaken for a full one: it runs
`--testsuite=Unit`, so anything only an E2e would kill escapes it. Three of this
batch's survivors were checked against the E2e suite by hand before being
judged.

## Auditing the feature against its own plan (2026-09-10)

The approved plan for drag-and-drop listed six E2e cases. Three were never
written, and nothing noticed — coverage was 100% and every gate was green,
because the paths they name are reached by OTHER tests and only the questions
go unasked.

- **Cancel on the collision prompt.** Written. It is the only one of the three
  prompt answers whose branch is an ABSENCE: the closure falls off both its
  `if`s and does nothing. Coverage cannot tell that apart from the answer never
  being chosen, and what it decides is whether a file gets overwritten.
- **A folder dragged into another folder — the success case.** Written. Only its
  refusal (dropping onto its own child) had a test, and the success path is the
  one with the moving parts: every store keyed by the FILES has to ask
  `ProjectPaths::moved()`, so the case puts a bookmark two levels inside the
  folder and requires it to arrive.
- **Dropping onto a FILE targets its parent directory.** Not written, and that
  is the right call: `dropDirAt()` answers exactly that question and is unit
  tested for the file row, the folder row and the header. An E2e would re-test
  the wiring the folder case already exercises.

Both new cases were mutation-checked: routing `cancel` into the overwrite branch
fails the first, and dropping the subtree-buffer loop fails the second.

*The general lesson, which is not about this feature:* a verification list in an
approved plan is not a gate. Nothing re-reads it after the work lands, and the
cases most likely to be skipped are the ones whose absence is invisible —
an answer that does nothing, a success case whose refusal is already covered.

## Escape left a folder toggle behind (2026-09-10)

Found by following a mutant, not by reading the code: the mutation review
flagged `forgetPress()` in the drop path as unguarded, and asking who ELSE owes
that call turned up a path where nobody made it.

Pressing a folder no longer expands it — the toggle waits for a release that
never moved, or the rows would slide out from under the pointer mid-drag.
Escape ends the drag while the button is still **down**, so the release that
follows finds nothing armed and the router discards it: `commitPress()` never
runs. The pending toggle survived the whole gesture, and the next click that
happened to end without motion paid it. Press a folder, start dragging, think
better of it, click a file — and an unrelated folder opens. The rows below it
move, so the click after that lands somewhere nobody aimed at.

Reproduced twice before fixing: a unit test on the router's state, and an E2e
that does the whole gesture and reads the tree.

### The obvious fix was wrong, and the suite said so

`cancel()` gained a `forgetPress()`, and two existing tests went red: clicking a
folder stopped expanding it. The release path called `cancel()` too, so the
disarm now ate the very toggle `commitPress()` was about to pay, one line later.

They are two different meanings wearing one name, and they are split now:
`disarm()` spends the drag without judging what the press still owes — the
release path, which then decides between paying and forgetting — and the public
`cancel()` is `disarm()` plus `forgetPress()`, for a gesture abandoned from
outside. Both halves are mutation-checked: folding them back together fails the
tree's click tests, and dropping the `forgetPress()` fails the new ones.

*Worth keeping in mind:* the deferral has three exits — paid on a motionless
release, forgotten on a drop, forgotten on a cancel — and each is written in a
different method. A fourth caller of `arm()` would have to find all three.
**Followed up below**, because a warning in prose is not a guard.

## One call, so the two answers cannot disagree (2026-09-10)

Follow-up to the entry above, whose closing note was a warning in prose — the
thing this codebase already knows reads like a verified rule and is not one.

The press site asked the tree two questions and had to keep their answers in
step by hand:

```php
$sources = $tree->dragSourcesAt($rel['row'], $mouse->ctrl || $mouse->shift);
$tree->pressRow($rel['row'], match (true) { ctrl => Add, shift => Range, default => Open });
$this->treeDrag->arm($sources);
```

"Is this press modified" is spelled twice — once from the modifier keys, once as
an intent — and the ordering (sources BEFORE the click, which may collapse the
selection) was held by a comment. A fourth intent added to that `match` without
touching the line above it re-opens the bug fixed an hour earlier: a toggle
deferred with no drag armed to spend it.

`pressRow()` returns its own sources now. One call, one derivation, and the
ordering is structural because it happens inside the method that owns both.
`dragSourcesAt()` keeps its own tests but has exactly one caller — the widget
itself.

### Two tests, because the invariant has two halves

The first is stated over the whole matrix: every row the fixture has × every
`ClickIntent` case, and wherever a toggle was deferred the press must have
handed back something to drag. It carries its own floor — a tree that never
defers would satisfy it vacuously — so it also asserts that a plain press on a
folder still defers.

That one was not enough, and a mutant said so: spelling the flag
`ClickIntent::Range === $intent` instead of `Open !== $intent` keeps the
invariant true while making **Ctrl** arm a drag it should not. So the second
test pins the other half — a modified press arms nothing, over both modified
intents and three rows, with an unmodified press as the floor. `dragSourcesAt()`
had documented that since it was written; nothing had ever checked that its
caller asked it the right question.

## Twenty files, one gesture, one announcement (2026-09-10)

A regression introduced earlier the same day, found by asking what the new
dispatch costs when the verb runs in a loop rather than by any log.

`withFs()` announces a working-tree change around every filesystem verb, which
is right — it is the choke point every verb goes through. But `moveEach()` calls
it once per file, so dropping twenty files fired twenty `WorkingTreeChanged`
events for one gesture. The listener runs `git status` over the whole repository
and asks for the branch. `WorkingTreeStatus` coalesces an in-flight status into
at most one rerun; `GitRefresher::refreshBranch()` coalesces nothing, so that
half was twenty processes.

I had written the opposite conclusion in the entry above — "one cheap call on a
user-initiated action" — which was true of the single-file case I had in front
of me and false of the one the feature exists for.

Reproduced by counting the events for a three-file drop before touching
anything: three.

**A counter, not a flag.** `moveEach()` raises it around the loop and announces
once in `finally`; `announceWorkingTree()` stays silent while it is up. Nothing
nests batches today, and a flag would work — until something does, at which
point the first inner batch to finish would clear it for everyone still running.
The counter costs the same and cannot be got wrong.

**In `finally`, twice, for the same reason:** a set that gives up halfway still
moved the files it got to, and a listener that is never told cannot find out.

Both halves are mutation-checked: dropping the batch fires per file again, and
dropping the batch's own announcement leaves a completed drop silent.

## Twelve strings the catalog gate could not see (2026-09-10)

`CatalogCoverageTest` reads every `->trans()` in `src/` and the bundled plugins
and asks whether its id is in `messages.en.xlf`. It took the FIRST TOKEN after
the `(` and required it to be a quoted string — so any call whose id is chosen
rather than written was invisible:

```php
$this->translator->trans($single ? 'Overwrite' : 'Overwrite all');
```

Proved before fixing, by deleting the `Overwrite` unit from the English catalog
and running the gate: green. Twelve ids across six such pairs, 431 → 443. All
twelve happened to be translated — by luck, not by the rule, which is the only
thing that was ever at stake here.

**The whole first argument, minus two things.** Stop at the first top-level
comma, because the params array is full of `'%file%'` keys that are not ids;
and in a ternary take only what follows the `?`, because the CONDITION can be a
string too. Both real: `'i' === $scope ? …` and `'indentSize' === $key ? …` were
reported as untranslated by the first draft, which is how the second rule got
written.

### The enum half, which had already been solved once

The same file has `categoryLiterals()`, there precisely because
`->trans($category->title())` is a dynamic argument no scanner of literals can
see. `ScopeKind::label()` is that pattern exactly and was not listed, so a sixth
search scope would have shipped its English label into six locales with every
gate green. Both enums are in that list now, and the docblock says a new enum
whose cases reach `trans()` belongs there too.

*Still not covered, and named so nobody assumes otherwise:* a ternary NESTED
inside a ternary hides its branches at bracket depth 1. There is no such call
today. Concatenation into `trans()` would be read as several ids rather than
one, which fails loudly rather than silently — the right way round.

## The width sweep had never been pointed at a plugin (2026-09-10)

`NarrowPaneRenderTest` renders every core pane widget at widths 1..20 and
refuses a row wider than its pane. It exists because `CodeEditorWidget` did that
twenty-one times in this repo's own `.porta/error.log`, and a `RenderException`
does not garble a line — it escapes the render loop and takes the session.

It is a CORE test over core widgets. Every widget the git plugin mounts — the
whole git view — had never been through it. Pointing a throwaway probe at them
found **three live crashes**, all the same shape:

| Where | The clamp |
| --- | --- |
| `HeaderRenderer::render()` | `max(0, $width - suffix - 1)` bounds the room, then draws the suffix at full width: the commit box's `Esc` was four columns in a one-column pane |
| `DiffViewWidget::renderToolbar()` | `max(0, $width - $tagsWidth)` bounds the title, then draws both toggles: a fixed twenty-two columns at every width below that |
| `DiffViewRenderer`, unified and split | `max(1, $width - 12)` / `max(1, $width - 6)` bound the TEXT while the gutter keeps its eleven (or five) fixed columns |

Every one is a **bound with no floor** — the recurring shape this codebase keeps
finding: a `max()` that clamps one PIECE of a row while the rest is emitted at
its natural width, so the total only grows as the pane shrinks.

`HeaderRenderer` is core and shared. Only the commit box passes a suffix today,
so the crash had exactly one door — but the next caller with a key hint would
have inherited it, which is why the fix went into the helper rather than the
widget.

**Clipped before styling, never after.** The gutter is built plain, measured,
then styled, because truncating an already-styled string cuts escape sequences
in half. That is a rule this codebase learned the hard way and it is easy to
undo by accident here.

All three floors are mutation-checked: restoring any of the three `max()`
spellings fails the new sweep.

*Both remaining plugin panes were swept straight after* — see below. Naming a
gap is not closing one.

## Finishing the sweep, and three more crashes in core (2026-09-10)

The entry above closed by naming two unswept plugin panes. Naming a gap is not
closing one, so both are swept now — `ImageViewerWidget` and
`GraphViewerWidget`, three states each, **and both were already correct**. The
sweep's vacuity guard is what makes that a real answer rather than a loop over
zero rows: 723 assertions apiece.

Then the obvious question, which had not been asked: is the CORE sweep complete?
Listing the `#[AsIdeService]` widgets and diffing them against the provider
found **four that were registered all along and had never been in it** — and
three of the four overflowed.

- **`FindBarWidget`, both modes.** `max(0, $columns - tagsWidth())` bounded the
  bar and drew the eight-column tag strip whatever the pane could seat. The same
  clamp the diff toolbar had, under a comment that literally cites the diff
  toolbar as the pattern it follows.
- **`HelpViewerWidget`.** `padRight()` is a bound with no CEILING: the markdown
  renderer floors its own wrap width, so in a narrower pane it returns lines
  longer than the pane and padding them achieves nothing. Sixty-one rows over,
  at every width from 1 to 18.
- `MenuBarWidget`, closed and open: correct.

`ContextMenu` is the fourth registered widget and is deliberately NOT in the
sweep: it renders `[]` into the layout and draws through the overlay
compositor, so the vacuity guard would reject it and rightly.

### The find bar's off-by-one, which the first fix missed

Guarding on `$col < 0` still left width 8 — exactly `tagsWidth()` — one column
over. `scrollLineToCaret()` keeps the CARET visible, so a body asked for zero
columns still returns the one the caret sits in. The guard is `$col < 1`: the
strip needs its eight columns and the bar needs at least one.

### A test that pinned the bug

`testTagZoneStartClampsToZeroWhenTheBarIsNarrowerThanTheTags` asserted that at
three columns the first tag zone clamps to column 0. That described the widget
accurately and the zone was honest about where the tag was — five columns
outside a three-column pane, which is what threw. It is two tests now: no tag
zones at a width that cannot seat the strip, and zones where the strip actually
lands at one that can.

**Running total for this crash family, today: six live overflows** — three in
the git plugin, three in core — every one of them a `max()` that bounds one
piece of a row while the rest draws at its natural width, or a pad with no
ceiling.

## Auditing the clamp idiom, and why the overlays were safe (2026-09-10)

Six over-wide rows in one day, all the same spelling, so the next step was to
stop hunting widget by widget and grep the idiom: `max(0, $width - …)` and
`max(1, $width - …)` across `src/` and the bundled plugins. Twenty-eight sites.

**Every reachable one is already correct**, and for a reason worth writing down:
they are all inside widgets the sweep now covers, and the sweep is what proves
them. The status bar, the dock strip, the menu bar, the editor's gutter, the
keybindings list — each carries the idiom and each fits at every width, because
each was made to.

The one component outside the sweep is `PickerLayout`, which the overlay host
composites rather than laying out. It carries the idiom twice. It is **also
correct**, and not because of that arithmetic:

> `ModalFrame::wrapSections()` clips every content row to the inner width in
> one place — "the single place the invariant is enforced", as its own docblock
> puts it.

That is the difference between the two halves of this UI, and it explains the
whole day's tally. The overlay path has a central ceiling, so a row assembled
one column too wide is clipped on the way out and nobody ever sees it. The
widget path has none: each widget assembles its own rows and hands them
straight to the renderer, which throws. Six widgets were missing a ceiling; no
overlay was, because the frame is theirs.

Mutating `PickerLayout`'s inner bounds does not fail the new test, and that is
the finding rather than a hole in it — the frame renders those bounds
unobservable from outside.

*Recorded, not fixed:* below eight columns of bounds the picker's own
`min($width, $columns - MARGIN, MAX_INNER)` has no lower bound and `$width`
goes negative, giving a four-column frame in a smaller box. It bleeds rather
than throws — I could not make it throw — and it needs the editor pane union at
three columns or less, which `clampSidebarWidth()`'s twenty-column floor
prevents outside a 1×1 terminal transient. That transient is real (the crash
log's own trace is `layout(…, 1, 1, …)`), which is why this is written down
rather than dismissed.

### Two measurement mistakes worth naming

I read the picker's declared `width` as excluding the frame chrome, twice —
first assuming two columns of it, then four — and both times the probe reported
overflows that were my arithmetic, not the code's. The rows equal `width`
exactly. A probe is only as good as the invariant you hand it, and an invariant
you guessed at will happily report someone else's bug as yours.

## The list of everything was a hand-written list (2026-09-10)

Six over-wide rows in a day, and the honest question afterwards is not "did I
get them all" but "why were they findable at all, given a sweep exists".

Because the sweep's membership was a hand-written provider. It graded whatever
someone had remembered to add, which is why four registered core widgets and
seven plugin widgets had never been through it, and why three of each group
were broken. The sweep was never wrong; it was just answering a smaller
question than its name suggested.

Both sweeps check their own membership now. The core one reflects over
`src/Widget/*.php`, keeps the classes carrying `#[AsIdeService]` — the
container's own definition of "mounted in the IDE" — and fails if one is
neither in the provider nor in `OUTSIDE_THE_PROVIDER`. The git one globs its
`src/Widget/` for `AbstractWidget` subclasses, where a plugin class cannot be a
container service and the directory is the membership.

**Two exemptions, and they mean opposite things**, which is why they carry
their reasons rather than sitting in a bare list:

- `CodeEditorWidget` — swept HARDER than a provider entry can manage. The crash
  was on the row path, and reaching it needs a file, a full gutter, sticky
  headers and the viewer door: four states, four dedicated cases.
- `ContextMenu` — not swept, and should not be. It renders `[]` into the layout
  and paints through the overlay compositor, whose ceiling is
  `ModalFrame::wrapSections()`. Its geometry is graded in `PickerLayoutTest`.

Both new gates were mutation-checked in both directions: dropping a widget from
a provider fails, and emptying the exemption list fails.

*Deliberately not done:* the same self-check for `image-viewer` and
`graph-viewer`, which have one widget apiece. A one-item list cannot go stale
in the way this is guarding against, and a gate per plugin directory would cost
more to read than it protects.

## Another hand-written list of everything (2026-09-10)

Same shape as the width sweeps, found by asking the same question of a
different file: `CatalogCoverageTest::labelLiterals()` named **two** files
carrying a `'label' => '…'` table translated with a dynamic id. There are
**seven**, and one of the five it never read — the settings nav's inline
`Languages` header — is translated at render time exactly like the two it did.

Proved before fixing, the same way the ternary gap was: delete the `Languages`
unit from the English catalog and run the gate. Green. It had been catalogued
by luck for as long as this gate has existed.

All seven are classified now, `true` or `false`, and a new test asserts the set
of files carrying the pattern **is** that list. The `false` ones build their
labels from data — a file's basename, a branch name, a symbol out of the
buffer — so they are not ids, and a scanner that swept them would report the
literal prefix of a concatenation (`'composer '.$name`) as an untranslated
string. That is why the answer is a classification rather than "scan
everything".

Three of these lists have now gone stale the same way in one day — the core
width sweep, the git one, and this. The pattern is worth stating plainly:

> **A hand-written list of "everything" is wrong the first time someone adds
> one, and nothing tells you.** Every such list wants a companion test that
> derives the same set from the tree and fails on the difference.

The lists that already do this — `PluginObservableContractTest`'s observable
set, the architecture edge allowlist, `ThemeLockTest` — are the ones that have
never been the source of a defect this session.

## Sweeping for the pattern itself (2026-09-10)

Having found the same defect three times, the next step was to stop finding it
by accident. Every `const array` in the suite that reads like an inventory,
checked for whether it derives its set from the tree or asks a human to
remember.

Two more did not, and both are gated now:

- **`CatalogCoverageTest::categoryLiterals()`** named two enums whose labels
  reach `trans()` through a dynamic argument, because two were all anyone knew
  about. The call SHAPE is enumerable — `->trans($x->method())` — so the sites
  are pinned and a third one fails until its enum is added. This closes the last
  of that file's three hand-written lists.
- **`SettingsPagesGoldenTest::PAGES`** listed five settings pages; there are
  five section classes. A sixth would have shipped with no golden capture at
  all, which is precisely the defect that test exists to prevent, arriving
  through the one door it cannot see.

Checked and already sound, so left alone: `KeybindingsDocTest::surfaceKeymaps()`
derives from every class in the tree with a `getDefaultKeybindings()`;
`PluginObservableContractTest` pins its set and asserts it against reflection;
the architecture allowlist fails on drift in either direction. Its `CONVENTIONS`
list is an EXCLUSION rather than an inventory — a stale entry there weakens one
check and cannot hide a new key, and adding to it is a visible edit.

### The rule, stated once

> A hand-written list of "everything" is wrong the first time someone adds one,
> and nothing tells you. Give it a companion test that derives the same set from
> the tree and fails on the difference.

Five lists in this codebase now do that. The three that did it already have not
produced a defect this session; the four that did not produced six crashes and
two silent translation gaps between them.

*Not gated, with the reason:* `image-viewer` and `graph-viewer` have one widget
each, and `MockServerContractTest::GATED_BY` is a capability map whose "set" is
the mock's own advertised capabilities — already the thing it is checked
against.

## Plugin code has never been mutation-reviewed, and here is how far I got (2026-09-10)

`plugins/` is 4,421 lines at 100% line coverage that no mutation run has ever
touched — `infection.json5` is `src`-only by design, because it judges against
the Unit suite and plugin code is covered by the Plugins suite. After three live
crashes turned up in `plugins/git/src/Widget/` today, that gap was worth
attacking directly.

**Two obstacles solved, one not.** Written down because the first two cost most
of the time and neither is guessable.

1. *Infection resolves `source.directories` relative to the CONFIG FILE.* A
   config in `/tmp` looks for `phpunit.xml.dist` in `/tmp`. It has to sit in the
   project root.
2. *pcov instruments one directory tree and it is not the repo by default.*
   Every attempt produced a coverage XML rooted under `src/` with zero
   `plugins/git` entries, whatever `--coverage-filter` said, because the
   `<source><include>` in `phpunit.xml.dist` deliberately excludes
   `plugins/*/src`. The working recipe is the one
   `CoveragePluginsCommand::phpunit()` already uses and nothing else documents:

   ```
   php -d pcov.enabled=1 -d pcov.directory=. -d 'pcov.exclude=#/(vendor|tests)/#' \
     vendor/bin/phpunit plugins/git/tests --coverage-filter plugins \
     --coverage-xml=coverage/<dir>/coverage-xml --log-junit=coverage/<dir>/junit.xml
   ```

   `pcov.directory=.` is the whole trick. With it the index carries 74
   `plugins/git` entries and Infection reads them.

3. **Blocked here:** Infection 0.35.4 aborts partway through
   `plugins/git/src/Widget` with `Expected an instance of
   PhpParser\Node\Stmt\Class_. Got: PhpParser\Node\Stmt\Trait_`.

*What that is NOT:* "Infection cannot handle traits", and this was worth
measuring rather than assuming, because the first draft of this entry warned
that a `src/` diff touching the wrong file could abort `composer mutate`
outright. **It cannot.** `src/Widget` — a hundred files carrying sixteen
traits — generates **6,127 mutants with no crash**, as does `src/Widget/Support`
(eight traits, 228 mutants). The documented workflow is safe; the warning was a
guess and is withdrawn.

I did not isolate which file trips the plugin run. Excluding the directory's
only `^trait` declaration (`GitPaneKeys.php`) does not help, so either the
exclude did not apply the way I spelled it or something else in there parses as
a trait. I stopped there rather than keep spending the round on tooling, which
is the honest place to stop — the remaining question is upstream, not this
codebase's.

*One thing the whole-tree attempt did establish:* Infection over all of `src/`
dies at 144 of 716 files with `Allowed memory size of 536870912 bytes
exhausted`. That is not a defect — it is why `composer mutate` is diff-scoped —
but it does mean a full-tree run needs `-d memory_limit=3G`, which nothing said.

## A crash log that empties itself when it matters most (2026-09-10)

`DiagnosticLog` caps itself at 256KB and, past the cap, replaced the whole file
with the entry that crossed it. Bounded, tested, and deliberate — the test was
called `testAnOversizedFileStartsOverInsteadOfGrowing` — but nothing recorded
WHY starting over was chosen over keeping the recent half, and the two are not
equally bad in the case that actually happens.

**A crash storm is what pushes this file past its cap.** So the rule discarded
the history at the exact moment there was most of it: the more the IDE was
failing, the more likely its log was a single line. The class docblock's own
worked example is the loss — the twenty-one identical `CodeEditorWidget` traces
this repo's `error.log` held, which are what led to six over-wide rows earlier
today. One more crash before I read them and there would have been nothing.

It trims to the recent half now, cut FORWARD to the first dated line so the
survivors begin with a stamp rather than the middle of somebody's stack trace —
which is the shape the same docblock promises a reader counting entries with
`grep '^2'`. A tail holding no entry start at all (one enormous trace) keeps
nothing, which is the old answer and still bounded.

Reproduced before fixing, and the first attempt at that test was **vacuous**: it
appended a fixed number of entries and passed under the old rule, because
straight after a reset the file legitimately holds recent entries. Only driving
the log to the exact byte of overflow — append until `filesize > cap`, then one
more — discriminates. The old test keeps its case under a name that no longer
overclaims: a cap too small to hold one whole entry keeps nothing.

*Not changed:* the 256KB cap itself, and the silence-on-failure contract. The
trim reads the file once, inside the same suppressed-error block, on the one
append per cap-crossing.

## The configuration reference described under half of it (2026-09-10)

`docs/configuration.md` carries the settings table a user consults before
editing `.porta.json`. It listed **six** of the fourteen keys the file accepts.
The missing eight — `breadcrumbs`, `indentGuides`, `lineNumbers`,
`methodSeparators`, `searchIncludeVendor`, `showWhitespace`, `stickyScroll`,
`tabBarPosition` — include every interface toggle the Settings view offers, and
six of them appeared nowhere in `docs/` at all.

And the inverse, in the same table: `relativeLineNumbers` was documented as
current long after {@see IdeConfig::SUPERSEDED_BY} retired it in favour of
`lineNumbers`. The one key the table named that nothing reads was described,
while its replacement was not.

Found by asking a question this codebase has answered well several times today
and had never asked of the docs: **the set is derivable, so is it derived?**
`IdeConfig::DEFAULT_SETTINGS` is the authority for what a `.porta.json` accepts,
and nothing compared it to the page.

Gated in both directions now, because each catches a different failure: a new
setting shipping undocumented, and a row outliving its key. A legacy key may
stay in the table only by being listed in `MAY_LINGER` with its reason, and a
third assertion requires that reason to still be true — the key has to be one
`SUPERSEDED_BY` actually knows about, so the exemption cannot outlive the
supersession it cites.

*Descriptions taken from the code, not invented:* every new row's wording comes
from the Settings view's own descriptor label and its `options` list, and every
default from `DEFAULT_SETTINGS`.

## The plugin API reference omitted what the bundled plugins use (2026-09-10)

Same question as the settings table, asked of the other big derivable set:
`docs/plugins.md` is the contract third-party plugins are written against, and
`PluginApi`'s public methods are enumerable. Eight were named nowhere on the
page — and **six of the eight are called by the plugins this repo ships**:

| Method | Used by |
| --- | --- |
| `registerHighlighter()` | tempest |
| `syntaxTheme()` | tempest, git |
| `setChangeMarks()` | git |
| `copyToClipboard()` | git |
| `onFileReloaded()` | git |
| `onFileRewritten()` | local-history |
| `registerTokenSpans()` | — |
| `registerInstaller()` | `AbstractToolPlugin`, so every tool plugin |

An author reading the reference could not discover how to do what the bundled
plugins visibly do. That is worse than an undocumented corner: the feature is
demonstrably supported and the only way to find it was to read core.

**What is deliberately NOT documented** is the loader surface — the sixteen
`buffered*` drains and `pluginName()`, which are public because the RUNTIME
calls them and sit under `PluginApi`'s own "Loader/manager plumbing" banner.
Documenting them would invite a plugin to call them.

Gated both ways, and the second direction is the one that matters here: the
exemption list is derived (`buffered*` plus one name) and pinned, so it cannot
quietly grow to cover a method nobody wanted to write up. That is the failure
mode an exemption list invites, and it is the same shape as `MAY_LINGER` in the
configuration gate an hour ago.

*Form left alone on purpose:* `registerPane()` and `registerViewer()` are taught
by a worked example rather than a table row, and the gate accepts a mention
anywhere on the page. A gate demanding a table row would be arguing about form
rather than coverage.

## Where the derived-set rule stops (2026-09-10)

Third application of the same question, and the first one whose answer was
"leave it alone" — which is the more useful half of the entry.

**Fixed:** `languages.<id>` in `docs/configuration.md` listed eleven of the
thirteen ids `LanguageCatalog::LANGUAGES` accepts. `xml` and `dotenv` were
missing, both real — `xml` with its own extension mapping, `dotenv` matched by
FILENAME rather than extension (anything starting `.env`, except
`.env.local.php`, which is real PHP). That last detail is now on the page,
because a reader who cannot guess how an id is matched cannot use it.

**Deliberately not gated:** the 188 entries of `ActionCatalog::ACTIONS`. 147 of
their labels appear in no doc, and they should not — the sample is `Move Caret
Up`, `Move Caret Down`, `Move Caret Left`. The discovery surface for a command
is the palette and Settings → Keymap, both of which search the live catalog;
`docs/keybindings.md` already gates the thing that genuinely needs writing down,
which is the KEYS, and it derives its surfaces from the tree.

The rule earns its keep where a doc claims to be a REFERENCE for a closed set a
user must type exactly — settings keys, language ids, the plugin API, the
observable events. It does not follow that every enumerable set wants a doc: an
inventory nobody would read is worse than none, and a gate demanding one would
be work with no reader at the end of it.

## The RTL column map had no guard, and a docblock said it did (2026-09-11)

`MouseRouter::route()` opens by mapping a click's screen column back to the
logical column the widgets rendered, so a mouse works under an RTL locale.
**Delete that line and everything passes** — all 5,727 unit tests, and all nine
tests of `IdeRtlLocaleTest`, the file written for exactly this.

Worse than unguarded: `testRtlClickInTheTreeRoutesToTheClickedEntry` said in so
many words that "a broken RTL column map would misroute the click out of the
(right-hand) tree pane and B would never open". It does not. That is the
`claims-are-not-guards` failure in its purest form — a causal guarantee spelled
out in prose, in the file whose job was to hold it.

**Why every existing test missed it.** `PaneReorder::logicalColumn()` is the
IDENTITY for a left-to-right run, and returns early for a row with no recorded
runs. Every RTL mouse test here clicks a row of Latin filenames, so the map
does nothing and its absence is invisible. Mirroring the LAYOUT (sidebar to the
right) is a different mechanism entirely, and that is what those tests actually
cover.

The map bites where a row is genuinely right-to-left. Measured, not guessed: in
Hebrew on a 160-column terminal, **row 0 column 0 maps to column 159**, and 122
columns across the top rows move. That row is the menu bar, whose titles are
translated — so a click on the first title is the case where visual and logical
disagree by the width of the screen.

The new test asks the live map where `titleStart(0)` lands, clicks THERE, and
requires the first menu to open — plus an assertion that visual and logical
differ, so it cannot pass by the map having quietly become the identity.
Removing the map fails it.

*And the same correction applied to my own work:* the RTL drag test written
twenty minutes earlier claimed the drag "inherits the remap for free". It does
not depend on it at all — a drop resolves by ROW. Its docblock now says what it
covers (a mirrored layout) and points at the menu test for the map. Writing a
false claim while fixing one next door is exactly how the first one got there.

## Identity in the common case (2026-09-11)

The RTL column map above was invisible because `logicalColumn()` returns its
argument for a left-to-right run, and every test that claimed to cover it used
Latin filenames. That is a SHAPE, not a one-off, and it is worth hunting
deliberately:

> A line that is the IDENTITY for ordinary input cannot be graded by ordinary
> fixtures. Coverage says it ran; nothing says it did anything.

Second instance, found by asking the question of the next candidate on that
list. `Document` normalises `\r\n` and `\r` to `\n` at three doors. Deleting
each in turn and running the unit suite:

| Door | Reached by | Guarded |
| --- | --- | --- |
| `__construct()` | opening a file | yes |
| `insert()` | a bracketed PASTE of Windows-line-ending text | **no** |
| `replaceAll()` | `FilesystemReconciler` reloading a changed file from disk | **no** |

Both unguarded doors are ordinary user paths — text copied out of a browser,
and any Windows-authored file in the repository changing on disk. Without
normalisation the `\r` survives INSIDE a line, where it is a control character
the width arithmetic counts as a column and the terminal does not: the
over-wide row family again, arriving from the data rather than the layout.

The constructor's normalisation was covered, which is why nothing noticed —
a suite reaches a class through its most obvious door and the other two inherit
the confidence without the evidence.

*The rest of the list, for whoever picks this up:* tab expansion, BOM handling,
and wide-character width are all identity for ASCII input, and the shipped
Japanese locale makes the last one reachable. `mb_strwidth` was audited in an
earlier round and came back clean; the other two have not been asked.

## The byte-order mark nothing had ever looked at (2026-09-11)

Third item off the identity-in-the-common-case list, and the first where the
answer was that the handling did not exist at all: `grep -rn "BOM"` over `src`
and `plugins` returned one line, and it was a bidi codepoint range.

A UTF-8 BOM is valid UTF-8, so a marked file loaded without complaint and
carried U+FEFF as the first character of line 0. Measured rather than assumed:

- `mb_strwidth("\u{FEFF}<?php")` is **6**; a terminal draws **5**. Line 0 of
  every marked file measured a column wider than it rendered, so it truncated
  early and every column mapped on it was off by one.
- The mark sits exactly where the caret starts. Typing one character on opening
  such a file put it IN FRONT — invisible on screen, and no longer a byte-order
  mark.

Stripped on load and re-emitted on save, so the bytes round-trip. Everything
between — the dirty digest, the LSP's copy of the document, a search — sees
text without it, which is what all of them want, and the one line that writes
bytes puts it back.

### A false alarm I nearly reported

On the way there I found that inserting at column 1 of `"é"` splits the
character and produces invalid UTF-8, and briefly had a much larger defect.
It is not one: columns in `Document` are BYTE offsets, `moveRight()` steps a
whole character (0 → 2 across `é`), and `setCursor(0, 1)` is a position no real
path produces. Checking the real cursor path before writing it up is the only
reason that did not become a wrong entry in this file.

*Still unasked from that list:* tab expansion. `mb_strwidth` was audited clean
in an earlier round.

## One file, two tabs, on every developer's laptop (2026-09-11)

The identity-in-the-common-case list is finished — tab expansion turned out to
be guarded, which is a clean negative — so the same question went to a new
candidate: **path identity is a string compare, and it is only right when the
spelling is consistent.**

Buffers are keyed by `realpath()`, which reads like a canonical name and is
not one. Measured on this machine:

```
realpath(".../Foo.php")  ->  .../Foo.php
realpath(".../foo.php")  ->  .../foo.php     (the file is Foo.php)
is_file(".../foo.php")   ->  true
```

`realpath()` resolves symlinks and `..`; it does **not** fold case. So on macOS
and Windows — most developer machines — two spellings are two keys over one
file, and `BufferManager::open()` made two buffers with two documents. Edit
both and the second save silently discards the first.

Reachable by typing: `porta src/ide.php` when the file is `src/Ide.php`, then
reaching it again through Go to File, which offers the on-disk spelling.

**Identity asked of the filesystem, not of the string:** same device, same
inode. That needs no guess about which platform folds case, stays correct on
one that does not (two names, two inodes, nothing merges), and answers for hard
links, which are the same question in different clothes.

### The 100% gate improved the design

The first version guarded each failure separately — `is_file()`, then `false
=== stat()`, then a `continue` for a buffer whose file vanished. Two of those
are races that cannot be reached from a test, and coverage failed at 99.99%.
Collapsing them into one expression (`is_file($p) ? stat($p) : false`, read
back with `is_array()`) made every line run on both paths — and the result is
simpler than what it replaced, not more convoluted. A pane tab whose path is
not a file now answers `null` through the same door, which is why the caller
can compare answers without excluding those first.

## Saving a CRLF file rewrote every line in it (2026-09-11)

The identity hunt's biggest catch. Lines are held LF-joined whatever the file
used — every consumer wants one shape — and `DocumentWriter` turned them back
into bytes with `match ($config->eol) { 'crlf' => …, 'cr' => …, default =>
"\n" }`. `EditorConfigResolver::resolve()` never returns null, so that writer
runs for every save, and a file with no `.editorconfig` saying otherwise took
the default.

So the first Ctrl+S on any Windows-authored file converted the whole thing to
LF. Silently, and as a whole-file diff — the kind that buries a real change in
a thousand-line review.

Reproduced end to end before touching anything: open a CRLF file, type one
character, save, read the bytes back. Every `\r\n` was gone.

The document remembers the ending it loaded and the writer uses it when the
config does not name one. An explicit `end_of_line` still wins, which is the
whole point of an `.editorconfig` — and that arm needed its own test, because
"keep what the file had" as a default makes the override the interesting case.

**Mixed files answer for their FIRST ending**, on the grounds that a mixed file
has no majority worth honouring and the one it opens with is the one a reader
saw.

### Three concerns, two doors, one seam

This is the third thing that had to happen at BOTH `__construct()` and
`replaceAll()`: line-ending normalisation (which drifted — present in both,
tested in one), the BOM, and now the ending itself. Each was added by hand to
two places, and the CRLF gap is exactly what that costs.

They go through one `adopt()` now. A fourth concern arriving should not be a
fourth chance to miss a door.

## "No matches" and "the engine gave up" are not the same answer (2026-09-11)

The find bar checks a user regex by running it against an EMPTY subject, which
asks only whether it compiles. A pattern can compile and then fail on a real
line, and PCRE says so the same way it says nothing matched: `preg_match_all()`
returns `false`. The loop skipped that line, and the bar reported `[0/0]`.

Measured, because a plausible failure is not a reproduction. `/(x+x+)+y/` — the
regex people quote for this — never fails on modern PCRE2: the JIT and
auto-possessification absorb it at 100,000 characters. `/^(a|a?)+$/` fails on
**twenty**, in two milliseconds, with `preg_last_error()` 2. That is the shape
worth guarding against, and it is one people write by accident.

The bar now distinguishes the two, with `pattern too complex` beside the
existing `bad regex`. Three mutants, and the third is the one that mattered:
never SETTING the flag fails, removing the render arm fails, and never
**clearing** it survived until the test went on to fix the pattern and require
the message gone. A state set on a bad pattern that outlives it is the same lie
in the other direction.

**And the project-wide surface, fixed straight after.** `ProjectSearch` swallowed
the same `false` at three places — `matchRanges()`, the multiline whole-file
scan, and the per-line offsets. I wrote it up as "a channel decision, not a
one-line change" because the class takes only `$rootDir` and has no notifier,
then reread that and found it was bookkeeping: the CONTROLLER already flashes
elsewhere, so the class needs no new dependency, only to record what happened
and let the caller read it.

A project search is the worse of the two, not the better: a handful of skipped
files among thousands is invisible, where a find bar at least shows `[0/0]` on
the line in front of you.

`testMultilineScanSkipsFilesThatBlowThePcreBacktrackLimit` already existed and
asserted the silent empty result. Reading it closely is what settled the
question — "which the whole-file scan skips (no results)" DESCRIBES the
behaviour and gives no reason for preferring it. It asserts the signal now too.

And the same sticky-state mutant survived here that survived in the find bar
twenty minutes earlier: nothing proved the flag clears. One controller holds one
`ProjectSearch` for the session, so reuse is the only case that happens — the
test runs a giving-up search and then an ordinary one on the same instance.

## A typo in .porta.json cost you the whole file (2026-09-11)

Same lens as the regex fixes — **a sentinel indistinguishable from a real
answer** — pointed at `json_decode`. `JsonFile::read()` returns null for a
missing file AND for a malformed one, and `PortaJsonWriter::edit()` did
`JsonFile::read($file) ?? []`.

So a `.porta.json` with a trailing comma, the commonest JSON typo there is, meant
the next settings change started from an empty array and wrote back a file
holding only the key just toggled. Theme, language, plugin list, LSP config,
per-language indents — gone, from one click in Settings.

### What I got wrong first

My first fix refused the write. Two existing tests went red:
`testPersistSettingIgnoresCorruptExistingFile` and its keymap twin have asserted
"overwrite a corrupt file" for a long time.

That is a **deliberate decision and it is the right one.** Refusing strands the
user: the Settings view offers no way to repair JSON, so every change would fail
until they found a text editor. Inverting it would have traded silent data loss
for a silent dead end.

What was wrong is losing the file, not writing. It is renamed to
`.porta.json.invalid` first, so a one-character typo costs a rename rather than
everything configured. The two existing tests keep asserting the write and now
carry the reason they never had — their names described the behaviour
("Ignores corrupt existing file") and justified nothing, which is why it was not
obvious from the suite that inverting them was off the table.

`.invalid` rather than `.bak`: a `.bak` reads like a copy of something that
worked. A previous one is overwritten, because the interesting file is the one
just lost and a chain of them would turn one typo into a litter of dead files in
a repository. The rename's own failure is ignored — it runs to make a
destructive write less destructive, and a rename that cannot happen must not
also stop the setting from saving.

*Read paths left alone, with the reason:* `bundledBelow()` and the other
`JsonFile::read(...) ?? []` callers degrade to defaults on a malformed file,
which is right for a read — `IdeConfig` is the one that warns, and it does.

## Mutating the day's later work (2026-09-11)

Second mutation pass over this session, base `97875ff1` — 72 mutants across the
1,100 lines the fixes since then added, 44 killed, **7 escaped**. Read as a
review, and only one of the seven was a real gap.

**The real one, and it is my own recurring mistake:** inverting
`if ($this->projectSearch->gaveUp())` in the controller survived. The test
asserted the "too complex" flash APPEARS for a bad pattern and never that it
stays away otherwise — which a warning that fires for every search would also
satisfy. That is the third one-way assertion of mine this session and the same
shape each time: a positive with no floor. It has one now.

**The other six, judged and left:**

- `BufferManager::fileId()`'s concat, four mutants. Checked by hand rather than
  reasoned about, which was worth doing: dropping EITHER operand is killed — the
  method returns `?string` and an int fails the return type — so what actually
  escapes is the `dev`/`ino` ORDER swap, and any deterministic pairing of the two
  is equally correct. Genuinely equivalent.
- `PortaJsonWriter::setAside()`'s error-suppressing closure returning false
  instead of true. No test makes the rename fail, and the suppression exists for
  a case that cannot be provoked from a fixture. Defensive, and the entry above
  says why the failure is deliberately ignored.

*Worth keeping:* checking a survivor by hand beat reasoning about it twice
today. The concat operands looked unguarded and are not; the RTL column map
looked guarded by a test that said so and was not.

## src/History's first mutation review (2026-09-11)

The `coverage/infection_*` directories left by the August rounds name the areas
that have had one: config, console, editor, highlight, input, settings, support,
ui, widget, workbench. `src/History` is not among them, and it holds everything
the user's `.porta/` remembers — so it got one. 328 mutants, 278 killed, **9
escaped**, covered MSI 96%.

**Two real gaps, both in the same shape: the common case untested.**

- `SessionRestorer::restore()` guards `null === $data || [] === $data['buffers']`.
  Turning that `||` into `&&` left the whole History suite green — with no
  session file, `$data` is null and the mutant dereferences it. Every existing
  test wrote a session first, so the FIRST-RUN path — every new project, every
  clone — was the one nobody asked about. Both arms have a test now.
- `SearchHistoryStore::load()` caps at `MAX`, and nothing held it. `add()` slices
  too, so the store never writes more than the cap — which is exactly why the
  load-side slice looked safe. A hand-edited file, or one left by a build with a
  larger cap, would have loaded whole and stayed that size.

### Seven equivalent mutants, and I got them wrong twice

The other seven are `array_values()` removals across four stores. I judged them
real — dropping one leaves gaps in the keys, and `json_encode` writes
`{"0":…,"2":…}` where the file says `[…]`, which is a format change in a
user-editable file. Then I "confirmed" three as killed.

Both conclusions were wrong.

The confirmation was a **broken mutation**: a crude `replace('array_values(', '(')`
left unbalanced parentheses, and the `exit=2` I read as a catch was a parse
error. Mutated cleanly, they survive. And they survive because every one of them
is followed by `array_unshift`, `array_slice` or `array_merge` — **all three
renumber** — so the calls are redundant tidiness and no test can kill them.

The test written for the property is kept, with a docblock that says plainly it
does not guard those calls: PHP's renumbering does, and the test pins the file's
FORM against a refactor that drops whichever operation is currently doing it.

*Third time today that checking beat reasoning, and the first time in this
direction:* a survivor that looked real was equivalent, after two survivors that
looked equivalent turned out real. Neither instinct is worth trusting; only the
run is.

## src/Tree's first mutation review, and what the scope can and cannot say (2026-09-11)

26 mutants, 23 killed, 3 escaped — and the small number is itself the finding.
`TreeOperations` is nearly all of this namespace and nearly all of it is covered
by E2e, so a run judged against the Unit suite barely sees it. That is the
harness artifact `infection.json5` warns about, read from the other end: **an
area whose tests live outside Unit cannot be reviewed this way at all.**

Worth remembering when picking the next area: `Search`, `Lsp`, `I18n`, `Help`
and `Container` have real Unit coverage; `Overlay`, `Layout` and `Tree` do not.

All three escapes were in `FileDecorations`, the per-frame tree marks, and two
were real — both the same shape as the History ones, a loop whose SKIP was never
exercised:

- The buffer loop's `continue` for a buffer with nothing to report. As a `break`
  the whole suite stayed green: every test opened one buffer, or opened the
  interesting one first. Two tabs where the first is clean is the ordinary case,
  and the second tab's dirty mark simply would not be drawn.
- `worstMarkable()`'s `continue` past a line with no diagnostics. As a `break`,
  the suite stayed green because every fixture put its diagnostic on line 0. A
  warning on line 5 of a file with clean lines above it loses its mark.

The third — `break` → `continue` on the first error — survives and should: it is
a pure early exit, nothing outranks an error, and the method's own docblock
already says so. Equivalent, verified by running it rather than by reading it.

*Four areas reviewed for the first time today between this and History, and
every real gap in both was the same one:* a loop's skip-this-one branch, taken
only when the fixture has a second element that differs from the first. Fixtures
tend to have one of everything.
