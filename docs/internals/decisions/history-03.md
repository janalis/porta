# Decision history, part 3

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## Complete Current Statement, once it had a table (built 2026-09-07)

Filed one round earlier as blocked, with the blocker named: it has to know that
a statement ends in `;` and a block opens with `{`, and that neither is true in
Python or YAML. `Editor\StatementSyntax` is that table, gated over every
grammar the same way `CommentSyntax` is.

**The difference from the comment table changes what the gate is FOR.** Every
language has some comment form, so a `null` there was always an omission. Most
languages have no statement to complete — JSON, Markdown, TOML, `.env` — so
`null` here is a real answer, and the gate exists to make it a written-down one
rather than a gap that looks the same. `Ctrl+Shift+Enter` doing nothing in JSON
is correct; the test asserting it uses a language the table says `null` for
rather than one merely absent from it.

**Three rows are worth defending:** Go has no terminator (the compiler inserts
semicolons and gofmt removes them) but still braces, which is the case that
proves the two halves are independent. Python's block has no CLOSER — it is the
indent — so the closer is separately nullable. CSS has `;` and no keywords: its
`{` follows a selector, which cannot be recognised without parsing, so brace
completion is left out rather than half-supported.

**The opener is stored exactly as it is appended** — `' {'` and `':'`. The
first draft spaced it in the code and produced `if x > 1 :`; whether a brace is
spaced off the condition and a colon binds to it is a fact about each language
and belongs in each language's row, not in a separator rule.

**What mutation testing was worth here.** Six survivors, and one named a real
case: dropping the `^` from the first-word match made `} else {` — a line that
CLOSES one block and opens the next, already carrying its brace — read as a
block opener and gain a second one. Another was better answered by changing the
code than by testing it: `mb_strtolower` on a head the pattern has already
restricted to ASCII is a guard against an input the function cannot receive, so
it is `strtolower` now.

**The limit is written down as a test, not only as a docblock.** The bracket
count does not read strings, so `echo "a(b"` counts an unclosed paren and gets
one. A parser would not, and there is none here; the mitigations are that this
is an explicit keystroke and one undo step. `testAParenInsideAStringIsCountedAndThatIsKnown`
exists so the behaviour is a decision rather than a surprise — and so that
anyone who fixes it properly has a test to change.

Also caught in passing, by the test failing rather than by review: `foo(` is NOT
a line that continues. Its bracket closes to `foo()`, which is a whole statement
and does want its semicolon. The continuation rule is about what the line ends
with AFTER the closers land.

## The logs, read again — and the instrument was the defect (2026-09-07)

Round 7's habit, run once more, and worth recording because the ANSWER was
different from the one the numbers first suggested.

| log | what it holds now |
| --- | --- |
| `error.log` | untouched since **2026-08-27**. Round 7's three-site `RenderException` fix holds; the 21 entries are all pre-fix |
| `freeze.log` | **2** new entries, both `loop stalled … (recovered)` (0.67s, 0.54s), both inside one 24-second window |
| `perf.log` | **15** `slow-paint` lines, `render=100–408ms`, `write=1ms` — all inside that same 24-second window, then nothing across two later sessions |

**The window is 09:09:14–09:09:38 at `load=11–12`**, on a machine whose load at
that moment was very plausibly this repository's own gate runs. `write=1ms`
with `pendingBytes` at 70k–260k says the terminal was not draining either,
which is what a saturated machine looks like from inside a process. Two later
sessions the same morning are clean: `paintMs p50=0.7`, no slow paints, no
stalls. **No Porta defect is claimed from this**, and the next reader should
check the `load=` column before treating a burst as a regression.

**What WAS a defect is the field I nearly diagnosed it with.** Every beat line
carries `edMs p50=… p95=…`, the editor widget's share of the frame, and every
line in that window read `edMs p50=0.0 p95=0.0` while `renderMs p50` sat at
11–90ms. The obvious reading — "the editor is free, so the frame's time is
elsewhere in the widget tree" — is one the log cannot support:
`percentile([])` returned `0.0`, so an EMPTY sample set printed exactly like a
set of fast ones.

Every other timing field has a counter beside it that gives the difference
away — `paints=0` explains an empty `paintMs`. `edMs` has none: nothing in the
line says whether the editor rendered at all. So the field read the same for
"twenty renders, each free" and "no renders", and the conclusion it invited may
be exactly backwards.

An absent measurement prints `-` now, across all five timing groups. The test
asserts BOTH halves — `-` with no samples, `0.0` with a genuinely zero one —
because a change that printed `-` for everything would satisfy the first alone.

**How to apply:** this is [[claims-are-not-guards]] pointed at an instrument
rather than at prose. Before reasoning from a diagnostic field, ask what it
prints when it has nothing to say; if that is indistinguishable from a real
measurement, the field cannot carry the argument. It is the same shape as the
comment table's `default => null`, one layer further out — and the tell is the
same, a value that means two things.

Removing the ambiguity also removed a line: `percentile()`'s own empty guard
returned the `0.0` that caused all of this, and with the check moved up into
its only caller it became unreachable — the coverage gate said so before any
argument did. Deleted rather than tested, which is the third time in this
repository's rounds that a mutation-or-coverage signal has been better answered
by removing a guard than by exercising it.

While fixing it, mutation testing named a second one: the `max=` field's
milliseconds conversion was pinned by a 30ms fixture, and at 30ms a 0.1% scale
error still prints `30.0`. The fixture is hundreds of milliseconds now. **A unit
conversion needs a magnitude big enough to expose getting it wrong.**

## The missing editor verbs, two of three (built 2026-09-07)

Round 8's backlog listed six verbs PhpStorm has and Porta lacked. Two landed
(`dfa8ae85`, and the clipboard history below); one is deliberately still open.

**Start New Line above / below (`Shift+Enter`, `Ctrl+Alt+Enter`).** The COLUMN
is the whole reason these are separate verbs from Enter — Enter splits the line
at the caret, so starting a statement from the middle of one costs an End first
and starting one above costs Home, Enter, Up. `shift+enter` had been an ALIAS
for `new_line`, a second way to do what Enter already does; it has its PhpStorm
meaning now, and plain Enter still splits.

The indent rule is asymmetric on purpose: going DOWN from a line that ends in an
opener takes one more level (the rule `insertNewLine()` already follows, so the
two keys never disagree about the same position), and going UP takes none. A
line above a `}` is inside the block and a line above an `if` is outside it, and
nothing on the line says which — copying the indent is neutral where guessing is
wrong half the time.

**Clipboard history (`Ctrl+Shift+V`).** The clipboard was a `string` field on
`EditorActions`, handed to the next instance on every buffer switch. It is a
`ClipboardRing` passed by HANDLE now, which is what makes the feature mean
anything: one history across every buffer in the session, because the copy you
want back is usually from the file you just left. A string could only ever have
been handed over by value.

- **Re-copying PROMOTES rather than duplicates.** Otherwise the list fills with
  the same snippet from the four times you pasted it around, and what you were
  looking for is pushed off the end by its own duplicates.
- **Newlines show as `⏎` rather than the entry being cut to its first line.**
  What tells two copies apart is usually their shape — three short lines against
  one long one — and a list of first lines throws exactly that away. It is also
  what the picker's filter matches on, which is why this landed after the
  overlay filter and not before.
- **Picking promotes too**, so the plain `Ctrl+V` after it repeats what you just
  pasted rather than the entry you reached past. Without that the two keys
  disagree about what "the clipboard" is, one keystroke apart.

`EditorHost` gained `openListOverlay()` — the third of the prompt/jump
primitives its docblock already claimed, and the alternative was a ninth
constructor closure on `EditorActions`.

**Two mutation survivors, both judged and both VERIFIED rather than argued:**
`array_values()` in the ring and the `(int)` cast on the picked index are
type-preserving, not behavioural — the runtime survives without either
(`array_unshift` renumbers; PHP coerces a numeric string offset). Removing them
was tried, and `composer analyze` refuses both. That is the distinction worth
keeping: a line mutation cannot kill is not automatically a line to delete, and
the way to tell is to delete it and run the gate.

**Still open:** bookmark mnemonics. ~~and Go to Super method (no LSP request
answers it; see the capability table)~~ — **Go to Super method SHIPPED**
(corrected 2026-09-13): `SearchActions::goToSuperMethod()` on `Ctrl+U`, answered
by `Porta\Search\SuperMethodNavigator` reading the source itself rather than by
a server, which is why the capability table's verdict stopped being the whole
story. The entry stood as open for long enough to be quoted as a gap. *Complete Current Statement* was filed
here as needing a per-language table before it could ship — it has one now, see
below.

## Type-to-filter in the list overlays (built 2026-09-07)

Round 8's step 1 gave the results PANEL grouping and a filter, and its "not in
this round" list said the OVERLAYS were left out: `Ctrl+F12` on a forty-method
class opened a list you could only walk. The overlay handled four keys — up,
down, Enter, Esc — and every printable keystroke fell on the floor.

It is universal rather than opt-in, because that is PhpStorm's speed search and
because a per-caller flag is a decision ten call sites can get wrong. The two
verbs each prefer the other's fallback, so no key is dead: the filter row
appears only once something is typed, so a list that was fine without one still
opens looking exactly as it did.

**Three decisions the tests exist to hold:**

- **Esc clears the filter before it closes the surface.** Typing is how you get
  around a long list; a typo must not also cost you the list.
- **Any filter edit puts the selection back on the first survivor and drops the
  hover**, because the ROWS MOVED. The old index names a different item, and
  picking the one you never looked at is worse than losing your place.
- **The empty-list guard returns before the movement keys and still lets a
  character through.** Every movement steps the selection modulo the row count,
  so a filter matching nothing is a division by zero one arrow-press away; and
  without the second half the filter is a trap you can only Esc out of.

**The architecture gate wrote the design.** Filtering started in the state tier,
which handed the render tier the narrowed rows — and `arch` refused the reverse
call outright (`OverlayHost <-> PickerLayout`). Handing down the RESULT works
for the rows and loses the WIDTH: the box is centered and sized from its
content, so a box measured on what survives the filter shrinks and re-centres on
every keystroke, crawling out from under the eye reading it. Layout needs both
lists, so it needs the RULE — `Widget\Support\ListFilter`, a pure function both
tiers call. The gate turned "where does this go" into a question with one
answer.

**What mutation testing was worth here: 23 survivors, and the shapes repeat.**
Eight were concat arithmetic in one line building the filter row, all alive
under `assertStringContainsString` — the same lesson round 7's completion type
column taught, so the row is pinned WHOLE now. Three were `mb_strtolower` →
`strtolower`, which an ASCII fixture cannot tell apart; and the first fixture
written for them did not either, because `Größe` has only an ASCII uppercase
letter, so both functions produce the same string. The letter being folded has
to be the multibyte one.

**And `pad: true` turned out to be load-bearing in a way no stripped-text
assertion can see.** The padding happens BEFORE the style wraps the row, so
dropping it leaves a highlight bar that stops under the text instead of crossing
the box — identical text, visibly broken row. That one is asserted against the
raw ANSI.

Three survivors are judged equivalent, all `ReturnRemoval`, all verified rather
than argued: two guard a fall-through into `typeIntoFilter`, which is reached
only by non-printable chords that `TextField::insert` rejects
(`src/Ui/TextField.php:65`); the third is the empty-needle fast path, and
`str_contains($x, '')` is `true`, so falling through returns the same list more
slowly.

## Comments work in every language now (built 2026-09-07)

Round 8's backlog filed this as "block comment is missing, and Twig has no
comment verb at all". Measuring it first turned a feature request into a defect
report: `Ctrl+/` was a **dead key in thirty-one of the forty-five grammars**
`LanguageMap::grammars()` can produce — Twig, HTML, Markdown, XML, Blade, Vue,
Svelte, OCaml, Go, Rust, Java, Kotlin, Swift, Lua, Ruby, Haskell and fourteen
more. And in CSS it was worse than dead: it inserted `//`, which is not a
comment in CSS at all, so the key silently broke the rule that followed it.

**The shape of the old answer was the bug.** `commentPrefix(): ?string` — a
fourteen-arm match with `default => null` — could not express "this language
comments with a block", so Twig's entry was literally `'twig' => null, // block
comments only`. In that shape "no comment form" and "nobody got round to it" are
the same value, which is why every gate passed for the whole life of the
feature.

So the answer is a VO with up to two forms (`CommentSyntax`), and the table
lists **every grammar explicitly, including the two that legitimately have
none** — `json` forbids comments (that is what `jsonc` is for) and `diff` is
generated. `CommentSyntaxTest` gates the table against `LanguageMap::grammars()`
in BOTH directions: a new language fails until someone says how it comments, and
a removed one fails until its row goes. That is the part that stops this
reopening.

**The two verbs are defined in terms of each other**, which is why they are one
change and not two: `Ctrl+/` prefers the line form and falls back to wrapping
each line in the block pair; `Ctrl+Shift+/` prefers the block pair and falls
back to the line form. Neither key is ever dead in a language that can be
commented at all. The block verb is character-precise — `foo(bar, baz)` with
`bar` selected becomes `foo(/* bar */, baz)` — and its un-toggle needs the
target range to BE the comment; scanning outwards for an enclosing one is a
parser's job, not a range operation's.

**Two structural decisions worth keeping:**

- **The block pair is one nested column, not two flat ones.** `['<!--', null]`
  is a plausible typo, and as two columns it downgraded a language to "no block
  form" in silence. Nested, half a pair is not a shape the table can hold. This
  came out of a mutation survivor: the `&&` pairing the two columns had nothing
  that could distinguish it from `||`, and the fix was to delete the condition
  rather than to test it.
- **`css` is the one entry that changes an answer** rather than adding one, so
  it has its own test naming why: `//` is a SCSS extension.

**A real bug came out of the mutation run**, at the usual distance from what the
mutant was about. An `Assignment` mutant on the caret arithmetic was EQUIVALENT
in every test — `$cursorCol -= 3` and `$cursorCol = 3` agree when the line
starts at column 0. Writing the case where they disagree meant an INDENTED blank
line, and there the content range ran BACKWARDS: `rtrim()` takes a wholly blank
line to length zero while the span starts at its full width. A backwards range
does not throw — `substr` reads a negative length as counting back from the end
— so it read as empty text and then deleted the indent on its way to inserting
the comment. Clamped with `max()`.

**How to apply:** an equivalent mutant is a question about which inputs the tests
never reach, not a verdict that the code is fine. Both times this round, asking
"what input would tell these two apart?" produced a case worth having, and once
it produced a bug.

One survivor was judged rather than killed: `break` → `continue` in the
already-commented scan is a pure early exit, and the pre-existing
`commentToggled` directly above has the same shape.

## Inline debug values reach phpantom too (built 2026-09-07, `3a5c726f`)

Round 8 recorded this as a whole round of its own and it was half a day, because
the measurement answered it: the feature above is gated on `inlineValueProvider`
and **phpantom does not advertise it**, so on the setup it was built for it
never fired once.

The values were never the server's. They come from the DAP frame; the server
supplies only the WHERE — which range on which line names a variable. phpantom
answers that question under a different name: its `semanticTokensProvider` emits
`variable` and `parameter` tokens whose ranges **include the `$`** (`"$count"`,
not `"count"`), which is exactly how the debug adapter names the variables in a
frame. So the fallback is a lookup against decorations already on screen, not a
heuristic that guesses at identifiers — measure the token text before assuming
a fallback needs one.

- `InlineValueRequest::forRange()` returns whether it ASKED. `false` is not an
  error and not an empty answer; it is "this server has no opinion", and only
  then does the fallback run. A server that answers with an empty list is not
  second-guessed — it said there are no variables here, and that is an answer.
- **`property` tokens are skipped.** `$this->count` is not a frame local, and
  its token covers only `count` anyway, so including them would look up a name
  the frame does not have.
- No paint after the fallback fills `$this->lookups`: `clear()` ran first, so a
  paint there could only draw the empty map it had just written.

## The reasoning it followed

`textDocument/inlineValue` is PhpStorm's "variable values shown at the end of
the line while stopped at a breakpoint". phpactor advertises it and answers it
properly. Measured, stopped inside a method with three locals, it returns one
`InlineValueVariableLookup` per variable:

```json
{"range": {...}, "variableName": "$count", "caseSensitiveLookup": true}
```

The server says WHERE and WHICH NAME; the VALUE comes from the debugger. Porta
has both halves already: `DebugController::loadScopes()` fetches every scope's
variables the moment the session stops, and `LineAnnotationSink` draws dim text
at the end of a line.

What it needs, and why it is a round rather than an afternoon: the two halves
are owned by different subsystems that have never spoken. It is a JOIN — LSP
ranges married to DAP values, keyed by variable name, invalidated on every step
— and every defect this session came from a join, not from a piece. It also
needs a channel decision: `LineAnnotationSink` is one slot per line and a
plugin already writes to it, so debug values and git blame would fight over the
same cell unless one of them yields.

**Do not start it without deciding that ownership first.**

## Local History follows a rename now (built 2026-09-05, `1d31206e`)

The entry below is kept because its reasoning is what the build followed. What
changed: `FileRenamed` IS `PluginObservable` now — the promise was made
deliberately, with the contract test's list and `docs/plugins.md` updated in the
same commit. A FILE rename is followed; a directory's still is not, for the md5
reason below, and `FileDeleted` is still not observable because local history
keeping a deleted file's snapshots is the whole point of it.

## The finding as it stood

Six path-keyed stores follow `FileRenamed`/`FileDeleted` now. A SEVENTH does
not, and it holds the most valuable data of the lot: `local-history`'s
`SnapshotStore` keys its snapshots by `md5(realpath)`, so renaming a file
orphans its entire history — at exactly the moment you are most likely to want
it. Every store above is core; this one is a plugin.

The fix is small and the obstacle is not technical. `FileRenamed` is not
`PluginObservable`, and that marker is deliberately a promise: the class is
pinned in a literal list in `PluginObservableContractTest`, named in
`docs/plugins.md`, and frozen from then on. It qualifies on every rule (scalar
payload, human frequency, documented), so the question is only whether the
public plugin surface should grow — which the marker's own docblock says is a
diff someone reviews rather than a thing you do while passing.

Two details for whoever builds it:

- **A file rename is enough; a FOLDER rename is not reachable.** `dirFor()`
  hashes the path, and md5 does not run backwards, so a directory rename cannot
  enumerate the snapshot dirs under it without a marker file recording each
  dir's source path. That is a schema change to what the store's own docblock
  calls a cache.
- **A DELETE must keep the snapshots.** They are the last copy of the content;
  local history exists precisely so a deletion is survivable. So only the
  rename half is wanted, and `FileDeleted` needs no marker.

## Undated evidence, and the one row nothing measured (2026-09-09)

Two findings, both reached by reading rather than surveying, and the first one
cost most of the round.

### `error.log` was the only channel with no timestamps

`.porta/error.log` held twenty-one byte-identical `RenderException` traces and
nothing that placed any of them in time. The file's mtime said 2026-09-08 —
three days AFTER the fix that closed them — so the reasonable reading was a
live regression, and that is the reading I published before checking it. It was
wrong. The entries are the same twenty-one round 7 already fixed; what finally
dated them was `NarrowPaneRenderTest`'s docblock, which happens to quote their
COUNT, and the table entry above. An mtime dates only the newest write, and a
repeating fault writes identical bytes, so neither the content nor the
filesystem could tell a live crash from a closed one.

Roughly an hour went into reproducing a crash that no longer exists: a widget
sweep at every width 1–60, a full IDE boot at 1×1, a progressive shrink, and a
replay of the crashed session's own `layout.json` and open file. All green,
which was the answer — it just took a long time to trust.

`freeze.log` and `perf.log` had stamped every line all along, from five
call sites repeating `$this->clock->now()->format('Y-m-d H:i:s')`. The stamp
now lives in `DiagnosticLog::append()`, so **every** channel gets one —
`error.log` and `dap.log` included — and the five call sites lost their copy.
First line only: a trace is one entry, and stamping its frames would break the
shape a reader counts entries by.

The prose note in the table above ("the 21 entries are all pre-fix") is exactly
the kind of standing-in-for-data that goes stale — the data says it now.

### The modal never measured its own toolbar row

`FindInFilesModal::boxWidth()` was a max over the title, the query and the
visible result rows: everything that VARIES. The options row does not vary —
five fixed tags, a translated caption, the scope label, and ` (Alt+S)`. Being
absent from that max it was the one row guaranteed to overflow, by one column
at the default width, and the row-wide truncate spends an overflow on the tail.
So the modal rendered `Scope: Whole Project (Al…`, eating the only thing on
screen that says how to change the scope — for a user whose own
`search-prefs.json` records `scope: module`, i.e. someone who changes it.

Fixed in both directions, because they answer different questions: `boxWidth()`
counts the row now (so it fits, in French too, where the caption is longer),
and the label — not the key — is what elides when a terminal is genuinely too
narrow to widen into. The label is a value the results already show; the key is
an affordance with nowhere else to appear. Same ordering the gutter's bookmark
glyph got, and for the same reason.

### For the next round

A screen read through `modalText()` is NOT the screen. It renders the editor
widget alone at a fixed `RenderContext(160, 40)`, so modal rows composited
outside the editor pane silently vanish from it — which had me convinced for a
while that the modal's title bar and its result count clipped away at 24 rows.
`screenGrid()` showed the title present and intact. Read the join, not the
piece.

## A sharing rule applied where nothing was shared (2026-09-09)

`SearchCategory::cap()` returns 5 for every category, and its reason is written
down: "so no one source floods the list". Six groups on one list, none of which
may crowd the others off it. Correct — for Search Everywhere.

`createGoToFile()` and `createScoped()` build the same controller with ONE
source, and paid the same cap. Rendered, that is Ctrl+P over forty matching
files showing five of them and a `35 more results` row inside a box with room
for twelve: seven empty rows and a keystroke standing between the user and
results already fetched.

The discriminator is passed, not inferred. Deriving it from the source count
read well and was wrong twice over: it makes a construction detail into
policy, and it changed the cap for the single-source rig five controller tests
use to exercise the grow machinery — tests whose subject is the shared rule,
which would then have needed a second source added purely to keep the number
they assert. `createGoToFile()` and `createScoped()` know what they are; they
say so with `categoryCap: SOLO_CAP`.

`SOLO_CAP` is `MORE_STEP`, the step the grow row already reveals, so the window
fills and the arrows scroll it. Past that the same row appears: this widens the
first page, it does not unbound the list — pinned by its own test, because a
cap that only ever grows is the failure mode here.

**How it was found, and the shape to reuse.** Not by reading `PickerLayout`,
which is correct: its window is `min(count, rows - 6, 12)` and it fills
whatever it is given. The disagreement was between three layers that each
looked right alone — the box seats twelve, the window fills what it is handed,
the source hands up five — and nothing anywhere errors. It is visible only in
the composed frame, which is why the guard for it is an E2e that counts rendered
rows (`IdePickerTest`) rather than a unit test of any one layer.

## The footer names its key, and a test that proved nothing (2026-09-09)

`Alt+Enter` — "open in Find window" — appeared **nowhere** in the find modal.
Both its footer siblings say their key: `⏎ open` names Enter, and `Scope`
carries `(Alt+S)` on the options row above. It was the one action in that modal
you could only learn from the source, so the footer names it now.

The footer is counted by `boxWidth()` for the same reason the options row is,
and establishing that it MATTERS took three tries — recorded because the first
two would have shipped.

**Attempt one** asserted the French screen still contained `(Alt+Enter)` and
`Portée`. Green, and worthless: with the default scope the options row is the
wider of the two fixed rows in every locale we ship (en 51/41, de 56/45, es
55/51, ja 53/45, he 47/40), so it alone sets the floor and dropping
`footerRowWidth()` changed nothing. Both mutations survived.

**Attempt two** narrowed the options row by selecting a SHORT scope label —
`src`, which is what a real project has; this repo's own `search-scopes.json`
holds `src`, `docs`, `config`. That inverts it: fr with `src` is options 43,
footer 53, so the footer sets the width. The mutations still survived, because
the assertion looked at the whole screen and `Portée` is ALSO the options
row's scope caption. The footer was being truncated behind a passing test that
matched the wrong row.

**Attempt three** reads the footer ROW — the one line carrying `(Alt+Enter)` —
and asserts the action after the key is still on it. Four mutations, all dead.

The lesson is the old one with a new face: a screen-wide `assertStringContains`
is a search over every row, so it passes on whichever row happens to carry the
needle. When the defect is that ONE row is too narrow, the assertion has to
name that row. See also `claims-are-not-guards` and the phantom title-bar clip
in the entry above — both times the reading was wide where the defect was
narrow.

## "1 results" (2026-09-09)

The find modal's title, the results panel's status, and the export flash all
counted with `%count% results` — no plural form, so one match read `1 results`
in all seven locales.

The catalog knows better in fifteen other places (`%count% version|%count%
versions`, `Copied %count% line|%count% lines`, `%count% setting|%count%
settings`), and the find modal's OWN grow row two rows below the title says
`%count% more result|%count% more results`. The two most-read counters in the
app were the ones not doing it. `Exported %count% results` was the third.

Nine test assertions pinned the ungrammatical form, which is how it survived —
`'1 results'` had been written down often enough to look intended.

**The guard needed care.** `assertStringContainsString('1 result', $screen)` is
satisfied by "1 results" and pins nothing; the test denies the plural
explicitly. That is the second time in one round a containment assertion passed
for the wrong reason — see the footer entry above — and both times the fix was
to make the assertion narrower than the screen.

Not changed: `%count% shown` ("1 shown" is correct), `Files (%count%)` and the
other parenthesised counts, and `Keybindings — %count% actions`, where a keymap
with one action is not a state the app has.

## The doc gate ran one way (2026-09-09)

`docs/keybindings.md` promised `Ctrl+G`, `Ctrl+Shift+T` and `Ctrl+U` under
**Inside Find in Files**. The modal binds none of them — they are editor verbs
that had sat at the end of the Search table since the doc was written, and
inserting the `### Inside Find in Files` heading above them (`f056e73b`)
swallowed all three into the new table. Moved back.

`KeybindingsDocTest` could not see it, and the reason is worth writing down: it
asked only whether every bound key is written down. That direction cannot
notice a ROW for a key nothing binds. `composer arch` fails on drift in either
direction and says so in `CLAUDE.md`; this gate had the same job and only half
the rule, so it now also grades the table against the code.

**Two mutations, and the third is the interesting one.** Re-planting the exact
regression fails, and renaming a documented chord to one nothing binds fails.
But breaking the row PARSER passed: no rows extracted means no strays, and the
gate goes green and stays green. Fixed by asserting the count first — the
`settle-loops-need-content` rule, in a new place: "nothing is wrong" is
satisfied by having looked at nothing.

Also checked, and NOT a defect: the five option tags (`.* Aa \b Pc ·"`) show no
keys on screen, which is the same gap the footer's `Alt+Enter` had. Every one of
them is documented in the table above, which is what `F1` renders — so the keys
are discoverable, the row has no room for five more hints, and adding chrome
there would be design rather than repair.

## Ninety-eight dead translations (2026-09-09)

Applying the previous entry's lesson — a doc gate that ran one way — to its
sibling. `CatalogCoverageTest` asked whether every used literal is catalogued.
`CatalogCompletenessTest` asked whether every English source has six targets.
Neither asked whether a catalogued string is used at all.

Fourteen were not, and each is carried in seven files: **98 dead trans-units**,
most of them translated into six languages by hand. Every one is a REWORD whose
old unit stayed behind — the editor's empty-state hint from before it read the
keymap, both find-bar hints from before they listed the regex and whole-word
chords, the settings footer from before it gained its find chord, the Go to File
title from when it carried a colon. A translator opening `messages.fr.xlf` today
would still be maintaining them.

**The gate is deliberately loose, and that is the whole design.** It substring-
searches the entire tree, tests and docs included, rather than reusing the
precise `->trans()` scanner beside it. Precision is the wrong instinct in this
direction: a false negative leaves one stale row, while a false positive DELETES
a live translation from seven files. Measured on the same data, the loose read
found 14 with no false positives; the strict scanner reports 143, because it
cannot see menu labels, action-catalog entries or plugin verbs.

**Two traps, both hit before the gate was trusted.**

The first run called the plugin-loader's vendored-package warning dead. It is
live — the PHP source escapes an apostrophe inside the literal and the catalog
stores it decoded, so the texts differ by a backslash. Had the list been acted
on unread, a live string would have been deleted from seven files. The corpus
decodes PHP escapes now, and dropping that decode fails the gate.

The second is stranger and worth remembering: the first version of the test
PASSED with its own target string planted back in the catalog. The docblock
quoted the dead strings verbatim as examples, and the test file is itself in the
corpus — so documenting a dead string is enough to make it used. The examples
are paraphrased now, and the same restraint applies to this entry: none of the
fourteen is quoted here either.

## The one-way-gate sweep, and where it ended (2026-09-09)

Two defects today came from a gate that graded only one direction, so the rest
of the gates were swept for the same shape. The result is worth recording
mostly for what it does NOT contain.

| gate | reverse direction | found |
| --- | --- | --- |
| `KeybindingsDocTest` | doc row → a key something binds | **3 stray rows** (fixed) |
| `CatalogCoverageTest` | catalogue entry → used anywhere | **14 dead strings, 98 units** (fixed) |
| `DocsIndexTest` | index link → a file that exists | 60 links, all live |
| `PluginObservableContractTest` | `docs/events.md` → a class | 13 named, all real |
| `VerifyGatesMatchCiTest` | — | already both ways |

The last three are clean, so only `DocsIndexTest` gained a gate — its direction
guards the F1 browser, which would otherwise offer a page it cannot open, and
the check is one `file_exists` per link. The other two were left alone: a gate
with nothing to catch and no way to be sure of its false-positive rate is a
liability, and `escalating [loop-leak] to a failure` is already on the settled
list for that reason.

**A whole-doc version of the keybindings reverse check was attempted and
abandoned.** Extending it past the one table means enumerating every binding
channel — the action catalog, the editor keymap, both find surfaces, and every
widget with its own `getDefaultKeybindings()`. A first pass over four channels
reported 31 undocumented-but-bound chords; every one was a blind spot, not a
defect: `Key::F5` rather than `'f5'`, `ctrl+page_down` in `TerminalPanelWidget`,
mouse gestures, and backticked `` Ctrl+` `` read as a backslash. The subsection
gate works because that modal keeps its keys in one explicit const; the general
case does not have one place to read, and a gate whose false-positive rate you
cannot state is worse than none.

That is the same conclusion the catalog gate reached from the other side: in
the reverse direction, prefer the check you can be sure of over the precise one.

## Infection over the session's diff (2026-09-09)

100 mutants over today's `src/` changes, 11 escaped, MSI 87%. Ten were real and
they cluster in exactly one place, which is the finding.

**`optionsRowWidth()`/`footerRowWidth()` sum each row's geometry a SECOND time**
— once to size the box, once to build the string — and nothing checked the two
sums agree. Every arithmetic mutant escaped: each `+ 2` off by one, the leading
column dropped, the separators dropped. Every test in that file asks whether
some text survived, and a box one column too WIDE still contains the text.

The guard is the invariant rather than the numbers: a row's declared width must
equal the width it draws, over five locale/scope pairs (the two rows trade
places for the widest depending on both). It kills all nine width escapes at
once and would have caught the original `(Al…` before it shipped. Pinning the
constants instead would have been nine assertions that go stale the moment a
glyph changes.

**The tenth**: `exportResults()` is Unit-covered, but every test reads the
FILE, so deleting its flash outright was invisible — and the flash is the only
thing telling the user an export happened, since it writes to
`.porta/search-results.txt` and opens nothing.

**The one survivor is left deliberately.** `max(1, …)` → `max(2, …)` on the
scope label's elision floor: both values keep `truncate()` off zero, and it only
bites in a box already too narrow to render the row. An equivalent mutant by the
doctrine at the top of `infection.json5` — recorded so the next reader does not
re-derive it.

**Cost of the fix.** Adding those tests pushed `FindInFilesModalTest` past
mago's `too-many-methods` threshold, which `mago.toml` says is bisected to the
real maximum and must not be raised to fit new code. Split by subject instead —
`FindInFilesModalGeometryTest` is the arithmetic, `FindInFilesModalTest` is what
the modal says — with the four-helper rig extracted to
`tests/Support/ComposesFindModal`, because the duplication gate is tree-wide and
copying `compose()` into the new class is exactly the clone it exists to refuse.

## The filler row nothing measured (2026-09-09)

Third instance of the same shape in one day, found by applying it as a lens
rather than by reading a screen: **a fixed row in a box sized from something
else**.

Both picker layouts size themselves from what VARIES — the title, the query,
the item labels — and both then draw a filler row when there is nothing to
list, which varies with none of it. `PickerLayout::layoutList` has no minimum
width at all (`layoutSearch`'s 24 columns is what has been hiding this on the
other side), so a Run or Branches overlay — three-letter title, short rows —
sizes to about fifteen columns and the filler is cut to fit:

| locale | message | drawn |
| --- | --- | --- |
| en | `no matches` | `no matches` |
| de | `keine Treffer` | `keine Tref` |
| fr | `aucune correspondance` | `aucune cor` |

Worse than the `(Al…` case that started the day, because these are truncated
with `ellipsis: ''`: a German reader sees `keine Tref` with **nothing** saying
it was cut. Both layouts count the filler now.

**Corrected the same day — do not take the reasoning below on its own.** This
originally counted the filler ONLY when it was about to be drawn, arguing that
measuring it always would widen every populated list for a row it never shows.
That trades a static width for a resize under the reader's eye, which
`layoutList` is explicitly built to avoid; see *Reviewing the day's own diff*
further down. It is measured unconditionally now.

`layoutSearch`'s floor seats every translation of `no results` today at 24
columns. That is luck, not a rule, and the test uses a longer message on
purpose so the guard does not depend on it staying lucky.

**Left as equivalent:** the `+ 2` in each addition mutated to `+ 1` and
survived. The row is `' '.$message`, so one column is the leading space and the
second is the trailing one that matches it — cosmetic symmetry with the item
rows above, not correctness. Asserting it would pin padding, which
`infection.json5` warns against in as many words.

## An input that hid its own tail (2026-09-09)

The previous entry flagged the list overlay's filter row as "probably the same
shape, but I cannot produce a case where it visibly breaks" and left it. That
was the wrong call twice over — it IS reachable, and the defect is not the one
the shape predicted.

The filter row was truncated whole, which puts the ellipsis at the END. A
26-character filter in a box sized from two short items drew `≡ exceptio…`:
past the width the row **stops changing as you type**, showing the opening
characters of a filter you can no longer read. The box measures the UNFILTERED
labels and never grows, so this is reachable by typing a branch name into a list
of short ones — which is precisely when a long filter gets typed.

The sibling settles it. `layoutSearch`'s query row has always scrolled
(`AnsiText::scrollLineToCaret`), because it carries a cursor marker to scroll
to. This row deliberately carries none — the frame allows one caret and the
search modal owns it — so the window is taken at the END of the text via the
same `caretWindow()` underneath, with an empty marker. The glyph stays put; only
the typed text scrolls.

**Why the earlier dismissal was wrong.** Both times the reasoning was "the user
watches the text appear, so a cut is visible". It is the opposite: an
end-truncated input goes STILL, and stillness is the one thing a typist reads as
"nothing happened". The rule to carry forward is that a row holding live input
scrolls to where the input is; only fixed text may elide.

## The family, swept (2026-09-09)

Four fixes today were the same shape, so the rest of the family was checked
rather than waited for. Two results, and the second one closes the thread.

**The remaining surfaces are correct**, and two of them are the reference for
how it should be done:

| surface | verdict |
| --- | --- |
| `MenuPanel` | counts label AND shortcut, elides the label, keeps the shortcut |
| `StatusBarWidget` | right side cut to `columns - 3` first, then the left; `128:37` survives to ten columns, every cut marked |
| completion popup | no fixed rows — every row is an item, all measured |
| the input sweep | `TextField::render()` self-scrolls via `caretWindow`; the picker's filter was the only site drawing `value()` instead, and it is fixed |

**The rule now lives in `subsystems.md`**, not only here. `decisions.md` is
where a settled question is argued; `CLAUDE.md` routes someone about to touch
overlays to `subsystems.md`, and that is the page that has to say "measure every
row you draw" BEFORE the fifth instance, not after it. Three rules in priority
order — count fixed rows, give up the value rather than the affordance, scroll
live input — with the four cases as the table that earned them.

**On the gate for it.** Pinning constants goes stale the first time a glyph
changes. The invariant — declared width equals drawn width, across locales
because the widest row changes with the translation — both fails when it should
and survives ordinary edits.

**Read the next two entries before quoting this one.** It originally said
"pinning content does not work" flatly, on the strength of nine mutants escaping
`FindInFilesModalTest`. That is true of a PRESENCE assertion and not of an exact
one — `MarkdownTableTest` pins whole rendered rows and kills the same class
without an invariant. The distinction is presence versus exactness.

## Table cells wrap now (2026-09-09)

`MarkdownRenderer` had always said tables and code fences truncate rather than
wrap. This measures what that cost and replaces it for tables.

**Before**, `docs/*.md` rendered at a range of pane widths, counting rows that
came out elided:

| pane | rows elided | of | share |
| --- | --- | --- | --- |
| 70 | 296 | 3407 | 8.7% |
| 91 (120-column terminal, tree open) | 192 | 3019 | 6.4% |
| 120 | 136 | 2745 | 5.0% |
| 160 | 99 | 2534 | 3.9% |
| 200 | 77 | 2413 | 3.2% |

Worst pages at 91: `plugins.md` (86 rows), `keybindings.md` (54) — the two most
reference-shaped documents, where a reader can least guess what was cut. It did
not go away with a bigger terminal: one cell in `plugins.md` runs to about 600
characters, so it was structural rather than a narrow-pane edge.

**Two things compounded it.** `HelpViewerWidget` binds no horizontal movement
at all, so an elided cell could not be scrolled to; and `findLines()` returns
the RENDERED lines, so `Ctrl+F` could not find the elided text either. A reader
who knew a phrase was in the docs was told there were no matches. The rendering
limit was documented; that consequence was not.

**After**: columns are budgeted against the pane — the widest gives up a column
at a time down to an eight-column floor — and each cell wraps inside its own, so
a row is as tall as its tallest cell. Truncation at the pane edge, counted the
same way:

| pane | truncated rows, before | after |
| --- | --- | --- |
| 70 | 296 | 34 |
| 91 | 192 | 9 |
| 120 | 136 | **0** |
| 160 | 99 | **0** |
| 200 | 77 | **0** |

Every one of the nine remaining at 91 is a **code fence**, which still
truncates on purpose: reflowing code changes what it says. No rendered row
exceeds its pane at any width, so the compositor invariant holds.

**How it is built.** The word splitting and the greedy packer were extracted
from the paragraph wrapper and are now shared, so a cell breaks exactly where a
paragraph would — and there is one implementation for the duplication gate to
be happy about. Link spans come back per wrapped fragment and column-relative,
which is what lets a link survive being wrapped: three tests that used to assert
a link was DROPPED by truncation now assert it survives, which is the whole
point of the change.

**The mutation follow-up, and a correction to the entry above.** Infection over
the change: 118 mutants, 22 escaped, MSI 81%. Every escape is an
Increment/DecrementInteger on a defensive fallback — `?? 0`, `?? 1`,
`max(0, …)` — whose branch cannot run, because `$widths` is the union of every
row's columns and each row iterates a subset of them. The analyzer requires the
`??` (it cannot prove membership across two arrays), so they stay: equivalent
mutants, recorded here so the next reader does not re-derive it.

Two tests were written for those escapes before reading them properly — a
column-alignment invariant and a wrapped-link-position check — on the assumption
that the escapes were LAYOUT arithmetic. They were not, and both tests turned
out to kill nothing the suite did not already kill: verified by disabling them
and re-running the same mutations, which still failed. Deleted.

That corrects the claim in the entry above it. "Pinning content does not gate
geometry" was true of `FindInFilesModal`, whose tests ask whether some text is
PRESENT — and a box a column too wide still contains the text. It is not true
here: `testATableColumnPadsToTheWidestCellPlusATwoColumnGap` and its neighbours
pin the exact rendered rows, and an exact row moves when the arithmetic does.
The rule is not about content versus geometry; it is that a presence assertion
gates nothing about layout, while an exact-rendering assertion gates it well.

**Two things the gates caught.** The column budgeter had an empty-set guard that
`max()` seemed to need; the coverage gate showed the line never runs, because
with no columns the row is one space wide and the loop containing the `max()`
cannot start. Deleted, with a test for the degenerate table that made the
argument concrete. And the test class hit mago's `too-many-methods` — bisected
in `mago.toml` to that class's own count — so the table cases moved to
`MarkdownTableTest` rather than the threshold moving.

## Reviewing the day's own diff (2026-09-09)

Fourteen commits with one pair of eyes on them, so the diff was read back as a
reviewer would. One finding, and it is in my own change from earlier the same
day.

`PickerLayout::layoutList()` measures its width over the UNFILTERED items on
purpose — the comment there says why: a box sized from what survives the filter
shrinks and re-centres on every keystroke, walking out from under the eye
reading it. The filler-row fix counted `no matches` **only when the filler was
about to be drawn**, which reinstates exactly that. Measured: 15 columns, then
27 on the keystroke that emptied the list.

The commit message even argued for the conditional — "measuring it always would
widen every populated list for a row it never shows" — a plausible-sounding
trade that swapped a static width for a dynamic resize, against a principle
written down three lines above the line being changed. Both layouts measure it
unconditionally now, and the no-resize property has its own test: one width
across a filter that matches everything, something, and nothing. Re-planting the
conditional fails it, and so does dropping the measurement.

The lesson is not "review your own diff" so much as **read the comment beside
the line you are changing**. The reason that box is sized the way it is was
already there, in prose, three lines up — and the fix that broke it cited a
trade-off the codebase had already decided.

## What the wrapping cost, measured (2026-09-09)

The rest of the day's diff, read back the same way. No further defects; two
numbers worth keeping and one wart written down.

**Rendering is memoized on width.** `HelpViewerWidget` re-renders only when
`$cols` changes, so the column budgeter's shrink loop is paid on a resize, not
per frame. Alternated A/B/A/B against the pre-change renderer:

| workload | before | after |
| --- | --- | --- |
| all `docs/*.md` × 4 widths (40 renders) | 95–97 ms | 108–112 ms |
| `plugins.md` at 70 columns, the worst case | 5.97 ms | 8.20 ms |

About 0.35 ms per document render, and 2.2 ms on the largest document at the
narrowest width — comfortably inside a frame, and most of it is producing the
extra rows rather than the loop, which runs a few hundred trivial iterations
even against a 600-character cell. No optimisation warranted; the number is
here so the next reader does not have to take it on faith.

**The wart.** `DiagnosticLog`'s clock is a trailing default, so `freeze` and
`perf` stamp from their injected clock while `error` and `dap` — built where no
container is at hand — stamp from the wall clock. A time-frozen test sees the
two disagree. Nothing asserts on a crash stamp, and `Ide` cannot reach the clock
without widening the `porta.ide` locator for one caller, which its own
membership gate refuses. Recorded in the class docblock rather than fixed.

## The dependency upgrade, four commits (2026-09-09)

Thirteen direct packages were behind. Done one package group per commit, each
green before the next, because the history said all three risky shapes had bitten
here before — a tui bump that moved a golden (`afa6e6c1`), a mago bump that
raised a false positive (`05793753`), and `mago.toml`'s own warning that an
upgrade recounts metrics.

**What actually happened, against what was expected:**

| expected | actual |
| --- | --- |
| a golden diff from `symfony/tui` | none — both byte-exact goldens unmoved |
| the `BidiTui` private-property mirror breaking | intact: `Tui.php` has ONE change, in teardown cursor math |
| mago finding new things | yes, 7 — 5 redundant docblocks, 1 false positive |
| thresholds recounted by mago 1.47 | no — the one that moved, moved because the CODE did |
| infection 0.35 breaking its config | no — all five keys survive the schema |

**The pre-flight was worth it and would have been worth it even at zero
findings.** `src/Ui/BidiTui.php` reflects four private `Tui` properties and
mirrors a private method body, and nothing in the suite checks that mirror is
faithful — so it is checked by reading the vendor diff, not by running tests.
All four names survive and `processRender()` is byte-identical.
`TerminalInterface` is unchanged, so `NonBlockingTerminal` misses no method, and
`Terminal::getColumns()` moved `?? 80` to `?: 80` — a cached ZERO now falls back
to 80×24, which we inherit because we delegate.

**`AnsiUtils` gained real width fixes** that could have moved everything and did
not: `visibleWidth()` now walks graphemes when combining marks or ZWJ are
present, and tabs measure as `TAB_WIDTH` in the slicers where `mb_strwidth`
counted them as one. The cursor-marker format is unchanged, so the hard-coded
regexes in `OverlayCompositor` and `SpanSlicer` still match.

**The one threshold that moved is a lesson about attribution.**
`too-many-methods` went 87 → 86 — and NOT because 1.47 recounts it. Splitting
`MarkdownRendererTest` earlier the same day dropped the top class, so
`ResultsPanelWidgetTest` at 85 is the pin now. Had the two changes ridden in one
commit, the drift would have looked like the metric change `mago.toml` warns
about. Probing each of the nine at one below its pin is what separated them.
Also corrected: the `too-many-properties` inventory line read 32 against a pin
of 31 with no correction note — 31 is right, and the prose was the stale half.

**Infection 0.35 changes the denominator, not the quality.** The same reference
diff went 118 mutants / MSI 81% to 126 / 75%: more variants per line, same
lines, same equivalent class.

### And it found a test I had deleted too early

One escape at line 330 was NOT the usual defensive-fallback shape. The
empty-fragment fallback a SHORT cell receives on a tall row carries
`width => 0`; at `-1` every later column on that continuation row shifts one
place right. The table is visibly crooked and the whole suite passes, because
every assertion in that class asks whether text is PRESENT.

That is the column-alignment invariant deleted earlier the same day as
"redundant" — a judgement made by testing it against three mutations and
generalising. This is a fourth it catches and nothing else does. Restored, and
tightened: the first version only caught text crossing LEFT into the padding,
so `width => -1` (which shifts everything RIGHT) still escaped it. It now
asserts where each cell BEGINS, and both directions die.

The rule this leaves: "I verified it against the mutations I thought of" is not
"it is redundant". The mutation tool enumerates; I do not.

## The no-intl leg, checked without being able to run it (2026-09-09)

The upgrade's one remaining risk was CI's `intl` / no-`intl` matrix, which this
box cannot reproduce — intl is compiled statically into the local PHP, so it
cannot be unloaded. What CAN be checked is the thing that leg actually exercises,
and this upgrade raised the stakes on it: `AnsiUtils::visibleWidth()` now walks
GRAPHEMES whenever text carries a combining mark or a format character, where it
used to call `mb_strwidth()`. Without intl those calls land on
`symfony/polyfill-intl-grapheme`.

Two things needed to be true.

**The polyfill provides the function at all.** `grapheme_str_split()` is a PHP
8.4 addition; had the polyfill not carried it, the no-intl leg would fatal on
the new path rather than fail an assertion. It does (`bootstrap.php:62`).

**Native and polyfill agree on WIDTH**, which is all `visibleWidth()` uses the
split for. Compared across 18 strings — Latin plus combining marks, ZWJ family
and tag-sequence emoji, regional indicators, Devanagari conjuncts, Hebrew with
niqqud, Thai, Arabic, CJK, and zero-width joiners/marks:

- **2 split differently.** `क्ष` (KA + VIRAMA + SSA) is one cluster natively and
  two under the polyfill.
- **0 differ in width.** The Devanagari case sums to 2 either way — one cluster
  of width 2, or two of width 1.

So the two CI legs measure the same widths even where they disagree about
cluster boundaries. Worth knowing that the disagreement exists: `sliceByColumn()`
walks the same split, so a slice boundary landing inside a conjunct would keep it
whole under intl and cut it under the polyfill. Not reachable by anything here —
no shipped locale is Devanagari and no fixture carries one — and not fixable
from this side in any case.

Incidentally the new path is a fix, not just a change: `mb_strwidth()` scored
that conjunct 3 columns, the grapheme walk scores it 2, and 2 is what a terminal
draws.

## Merging what two servers answer (2026-09-23)

Stage A put two servers on one language and fed both. They still answered
nothing together: every feature request went to the first. This closes the
half that makes a second server worth having — a Symfony server's route names
in the same completion popup as a PHP server's methods.

**`requestAll()` is incremental on purpose.** `request()` has no timeout, so a
contract that waited for the last answer would let one server that never
replies hold the whole verb forever. Each answer merges into what is in hand
and re-renders; the popup grows as servers reply. Only servers whose OWN
capabilities advertise the method are asked — a merged capability would be a
claim no single server made, and a server that never offered the feature
answers an error per request.

**A second round trip has to reach the same server.**
`completionItem/resolve`, `codeAction/resolve` and `workspace/executeCommand`
are each about something one server offered, and sending them elsewhere is at
best an error response and at worst another server acting on an id that means
something different to it. `Lsp/ServerOwner` carries the answering server
inside the payload, because that is what survives the trip through the UI: the
popup hands the raw item back, the quick-fix list hands the raw action back,
and neither has anywhere else to put a note. It is taken off again before the
payload goes on the wire — the spec says an item is handed back to resolve
unchanged, and a field of ours arriving at a server is at best ignored.

**Every merging controller holds a generation.** Several servers answer one
Ctrl+Space at their own speeds while the user keeps typing, and the slow answer
to the request before last is not merely stale — it is a list of the wrong
things. The completion guard is two statements rather than one `||` chain,
because "it arrived too late" and "it says nothing useful" are different
reasons to drop an answer, and folding them together made half the condition
unkillable.

**A per-answer message must look at what is in hand.** "No quick fixes here"
used to be raised from the only answer there was. With several, a server with
nothing to offer would blank a list another had already put up — so the flash
asks the accumulator, not its own reply. The test for it asserts the flash
BEFORE applying anything, because applying raises its own message over it.

**Two capability gates had to stay single-server**, and they are the reason
`capabilityOf()` exists rather than a merged view: `SemanticTokensController`
reads the legend out of the same capability that decodes that server's
response, and completion detail resolution reads `completionProvider` to
decide whether asking is worth anything. One server resolving says nothing
about another.

**Behaviour change worth stating**: servers that do not advertise
`completionProvider`, `codeActionProvider` or `codeLensProvider` are no longer
asked for those. LSP requires advertising them, and asking anyway earns an
error response per request — but a non-conformant server that answered without
advertising would now go unasked.

### The eight mutants left standing, and why

Every one is equivalent, and the reason is at the site so the next round does
not rediscover it:

- three `Increment`s on `++$generation` — the counter is only ever compared for
  equality, so decrementing it works exactly as well;
- two `UnwrapArrayValues` — `array_values()` makes a declared `list` true
  rather than assumed, and nothing downstream reads the keys;
- two `CastString`s in `ServerLocator` — for the analyzer; PHP normalises a
  numeric string array key to an int anyway, and removing them turns
  `composer analyze` red;
- one `ReturnRemoval` in `requestTo()` — no session is keyed `''`, so falling
  through changes nothing today, and keeping the return is what stops that
  being load-bearing.

One survivor WAS a real gap and is now covered: an action arriving with its own
`command` on a server that resolves used to be indistinguishable from one that
needed resolving, so nothing proved it is run rather than round-tripped.

## Two servers on one language (2026-09-23)

Porta routed one language server per languageId, and which plugin got `php`
came down to load order — the directory glob, so alphabetical. Symfony's
language tools is *built* to run beside a general PHP server, and lost the
claim because `symfony-lsp` sorts after `phpantom`. Renaming either plugin
would have changed which one served PHP, which is the shape of an accident
rather than a decision.

**Diagnostics needed an owner first, and that half stands alone.**
`Buffer::$diagnostics` was one slot. A language server publishes on every
keystroke and an analyser plugin publishes on every save, both for the same
file — so running phpstan beside a PHP server showed one set or the other
depending on which had spoken last, flickering back on the next edit. Nobody
had to enable anything new for this: `PluginApi::setDiagnostics()` and the LSP
hook already shared the slot, and the docblock said so ("replace any previous
set for that file").

It cannot be fixed by ignoring empty publishes. LSP *requires* an empty
`diagnostics` array to mean "I have cleared mine", so "nothing to say" and
"nothing any more" are the same payload and only the owner tells them apart.
`applyDiagnostics()` takes one now — the server's name, bound per session the
way the log and `showMessage` hooks already were, or the publishing plugin's —
and `$diagnostics` is a derived merge of `$diagnosticsByOwner`. The merge is
ordered by owner name and then by line, because a server answering a keystroke
and an analyser answering a save land in whichever order the loop gives them,
and a Problems panel that reshuffled on that race would be unreadable while you
are reading it.

**`ServerRole` replaces load order.** The first `Primary` claim wins and later
ones are dropped — two general servers for one language is double the processes
for one answer, and neither is the right one to ask. A `Supplementary` claim
runs beside it, and is PROMOTED when nothing else claims the language, which is
what keeps symfony-lsp serving php on a project with no PHP server enabled: the
behaviour the plugin already documented, minus the accident that produced it.
`debugAdapters()` and `HighlightRegistry` keep the plain first-wins convention,
and the divergence is commented at the site, because a language server is the
one of the three a second provider can usefully sit beside.

**The map is a list, and the whole lifecycle fans out.** `didOpen`,
`didChange`, `didSave`, `didClose` and the flush inside `ready()` reach every
session for the language. Half a lifecycle would be worse than none: a server
still holding the text from three keystrokes ago answers about a document the
user has moved on from, which reads as a wrong answer rather than as no answer.
Feature requests still go to the first server only — most verbs pick a single
answer, and asking two would mean inventing a tie-break for each. Merging the
ones that *are* lists (completion, code actions, code lenses) is a separate
seam, and needs `request()` to carry server identity first, because
`completionItem/resolve` has to go back to whoever offered the item.

**Duplicates collapse twice, and the second time is not belt-and-braces.**
`PluginManager` drops entries with an identical launch signature, and the pool
does it again when routing. Telling one process a document opened twice is not
a harmless duplicate — the server would hold two copies of a file it can only
close once. Two mutants proved the first version of that guard was written for
the easy fixture: `continue` → `break` is invisible while the duplicate is
LAST, and both the dedupe skip and the not-running skip needed a case with a
distinct server behind the one being skipped.

**`LanguageServerMap` is its own class** because `mago lint`'s kan-defect score
said so, and it was right: deciding who serves a language is a policy with
three rules that interact, and `PluginManager` is already where every other
kind of registration lands.

**Two equivalent mutants are recorded rather than chased.**
`ServerLocator`'s `(string) $language` casts are for the analyzer: a JSON
object key like `"0"` arrives from `json_decode` as an int, and PHP normalises
a numeric string array key back to an int anyway, so dropping the cast changes
nothing that runs and only widens the inferred key type. Removing them turns
`composer analyze` red, which is the evidence that they are load-bearing
somewhere other than at runtime. The reason is at the site, so the next reader
does not spend the round rediscovering it.

### Two pre-existing tests that passed without observing anything

Both surfaced as coverage gaps under the (slower) coverage run while every
suite was green, which is the shape of the trap: a test asserting an ABSENCE
after a fixed gap cannot tell "it decided not to" from "it has not answered".

`testCompletionWithNullResultIsIgnored` waited 300 ticks and asserted no popup.
Its own comment defended the gap — "there is no positive to await here" — and
the branch it existed to cover never ran. There is a positive: ask a SECOND
completion that does answer. Responses come back in order over one stdio pipe,
so the popup from the second is proof the first was handled.

`testGoToImplementationWithNoResultFlashes` did have a barrier, and it was the
wrong one: both of its cases flash the same message, and `clearOutput()` does
not clear a flash that is still live and repainting — so the await after it was
satisfied by the FIRST flash coming back. The cases are the other way round
now, and the second waits for the toast stack to empty before it looks.

## symfony-lsp knows what it installed (2026-09-22)

The 2026-09-09 round named the theme and only half-closed it:

> the plugin could download a binary but had no notion of *state* — it never
> asked what was installed, whether it ran, or whether it was being used.

The smoke test answered "whether it ran". This round answers "what".

**The Update entry never turned on.** Reported from real use: a binary sitting
at `~/.porta/bin/symfony-lsp`, the plugin enabled, and the Plugins menu still
offering **Install Server** with **Update Server** greyed — for good. The
launcher resolved the binary through `ServerConfig` (setting → PATH → the
installed copy), and the menu through `AbstractToolPlugin::binaryAvailable()`,
which stops at the PATH. Nothing puts `~/.porta/bin` on the PATH, so the
predicate was false on exactly the machines where the install had worked. The
entry added in `3a55f8cb` to stop Install lying could therefore never appear.

One resolver now serves both, because two expressions for one question is how
they disagreed. The test that would have caught it asserts the menu items'
`enabled()` flipping across an install, not that both command ids exist — which
is all the original asserted, and why a 100% mutation score said nothing.
**Registration is not enablement.** Reverting the fix turns the new test red
with `installed → Install stops offering itself`.

**`--version` was already being run, and thrown away.** The smoke test executes
the staged binary before the irreversible rename; its stdout went nowhere. It
is the whole of the version story: `Release::versionFrom()` parses it,
`InstalledVersion` caches it in memory, and "Update Server" resolves the latest
tag first (one redirect, no API budget) and says `already up to date (0.21.0)`
instead of fetching ~9MB. Proven by the INODE in the E2e, not by the toast — a
download that happened anyway would rename over the file whatever was printed.

**No sidecar file, no setting, for the installed version.** Either would be a
second source of truth that desyncs the moment someone swaps the binary by
hand, and a setting would put a machine-global fact into a file that travels
with the project — the argument that removed the `binary: ''` write in
`27a63cd`. The binary answers for itself in ~50ms, once, off the render path.
`Runner::PROBE_TIMEOUT` is 5s rather than the install's 300s **because the
thing being asked is whatever `binary` points at**: a wrapper script that
ignores `--version` and waits on stdin would otherwise hold a process for five
minutes per window. The repo's own test stub was exactly such a script.

**Progress is a status cell, not toasts.** Two curl calls, a sha256, a tar and
a smoke test sat behind ONE four-second flash, after which the screen said
nothing for the rest of the operation. There is no determinate-progress widget
in this tree and this round did not build one: `registerStatusSegment()`
already exists, a cell can stay for as long as the work does, and five toasts
would be worse than one. The percentage is real — `curl --progress-bar
--stderr -` puts its meter on stdout (the body is going to `-o`, so stdout is
free) and `AsyncProcess::start()`'s long-unused `$onChunk` streams it. Verified
against live curl, including the `#=#=#` warm-up chunks, which carry no figure
and read as "downloading…" rather than as 0%.

**The daily check is silent when it fails, and records that it LOOKED.** A
background check nobody asked for must not toast "cannot reach github.com" at
every launch on a plane. The note is written whatever the comparison says,
because its job is "this machine looked today", not "this machine found
something". A timestamp from the FUTURE counts as due: a clock that was wrong
when the note was written would otherwise switch the feature off until it
caught up, which for a badly set clock is never.

**A test barrier has to cover the DECISION.** `testAMachineThatLookedTodayDoesNotLookAgain`
first waited on `LSP:` and asserted the note was untouched — which passes
vacuously while the check is still ahead of you, and the coverage run (slower
than the plain one) proved it: the throttle's `return` showed uncovered while
every suite was green. A decision not to act leaves nothing on screen and
nothing on disk, so the barrier is the version probe answering, read through
`Probe` — the check runs inside the probe's own callback, so once the version
is set the check has already had its turn.

**`registerInstaller` grew a fourth manager, `PluginApi::INSTALL_SELF`.** Its
`via` allowlist was composer/brew/cargo, so the one plugin whose tool has no
package at all was the one plugin whose settings page could not offer a button.
A self-install candidate names one of the plugin's OWN command ids, checked at
registration rather than at press time — `CommandRegistry::run()` is a no-op
for an unknown id, so a typo would otherwise ship a button that silently does
nothing. Such a row does NOT disappear once the tool is there, unlike a
package-manager row: `composer require` is not an upgrade path, but the thing
that downloads its own tool is the thing that replaces it, so it re-reads
`Update`. It re-derives off `onServerBinaryReplaced()`, which now also
refreshes the settings rows — a binary landing on disk is exactly when that
caption changes, and the install lands long after the page rendered.

**The Welcome card stopped claiming credit.** Every other card is done at
enable, because every other plugin brings its own code. This one enabled a
plugin whose server ships as a GitHub release and ticked itself, leaving a
finished checklist and a status bar reading `LSP:none`. It is done when the
plugin is enabled AND the tool is available, and pressing it does both. A
plugin that declares no offer has no tool to wait for and is ready by default,
which is why the predicate is an AND rather than a replacement.

**`ToolInstallOffers` is the slice, not `PluginManager`.**
`SettingsPluginsController` held the whole manager to call one method, and the
welcome flow needs the same one. Two consumers, one method — the same split
`PluginEnablement` already makes for the enable verb. It is aliased by hand in
`services.php` because `PluginManager` is built by a factory, so
autoconfiguration never sees the interface.

**And one pre-existing flake, fixed because it was in the way.**
`LspSessionTest` asserted `file:///tmp/x.php`, and `Uri::fromPath()` calls
`realpath()` — which resolves `/tmp` to `/private/tmp` on macOS only when the
file actually exists. The assertions were right exactly while nothing occupied
those paths in the shared `/tmp`; the day another project left a file at
`/tmp/x.php`, three tests failed with nothing in this repository changed. The
file's paths live under a root that cannot exist now, and the reason is
recorded on the class so nobody tidies them back.

## The symfony-lsp round: four symptoms, four causes (2026-09-09)

Four things the user reported from real use, none of which shared a fix. Worth
recording because the shape repeats: the plugin could download a binary but had
no notion of *state* — it never asked what was installed, whether it ran, or
whether it was being used.

**"could not resolve the latest symfony-lsp release"** (`fe0000ff`). The tag
lookup went through `api.github.com`, which is 60 requests/hour per IP
unauthenticated. That budget was exhausted for this IP while nothing else was
wrong. The tag now comes from the `releases/latest` redirect on `github.com` —
ordinary release traffic on the host the download already uses — with the API
kept as a fallback that carries `Authorization: Bearer` when `GITHUB_TOKEN` or
`GH_TOKEN` is set. The blindness was the deeper half: `curl -fsSL` discards the
status, so rate-limit, offline, DNS failure and a missing curl all produced one
string. They are four messages now, and the rate-limit one names the reset time.

**A binary that could not run replaced one that could** (`52175da9`). There was
no smoke test, and the rename is atomic and irreversible. A wrong-architecture
download now fails `--version` while the old copy is still on disk.

**An upgrade silently did nothing** (`55acea0e`). The plugin relaunched by side
effect — writing `binary: ''` to trip a reconfigure — and
`LspSessionPool::reconfigure()` returns false when the resolved map is
unchanged. On a FIRST install the map changes (null → path) so it worked; on an
upgrade the argv is byte-identical, so no session stopped and the running
server kept serving the inode `rename()` had unlinked. `reload()` is the
explicit force, reached through `PluginApi::relaunchLanguageServer()`.

The test that caught it did not exist because every relaunch test covered the
first install — the one case that *cannot* short-circuit.

**"Install Server" stayed in the menu** (`3a55f8cb`). It cannot disappear:
`MenuItem`'s `isEnabled` predicate greys an entry, it never hides it. Hiding
would also have removed the update path, which was the affordance actually
missing — re-running install WAS the update, which nobody could guess. Two
commands over one action, each enabled when it applies.

### The follow-ups, and one measurement that closed a bullet

**Probe curl and tar before a byte moves.** tar is not needed until the archive
is on disk, so a machine without it waited out a ~9MB download to be told
`could not extract` — which reads as a corrupt release. Both are resolved with
`ExecutableFinder` up front now, and the old `-1 && -1` heuristic for "curl is
not available" is gone: a missing tool no longer reaches that code, so leaving
the branch would have made it a wrong label for a timeout.

**The scratch reaps itself.** Quitting mid-download runs neither the success
sweep nor the failure sweep, so ~9MB stayed under a pid-keyed name — and pids
recycle, so that leftover was also the tree the next install with the same pid
would extract into. Proven, not argued: with the sweep disabled the test lands
the *stale* binary and reports success. Directories are reaped only when
nothing in them has been touched for an hour, because a second Porta window may
be downloading into a sibling right now.

**`hash_file()` on the archive stays synchronous.** Measured on the real
artifact rather than argued: sha256 over the 8.9MB binary is **37ms** warm
(five runs, 36.6–38.2), so the ~3.5MB compressed tarball costs roughly 15ms —
one dropped frame, once per install, inside an operation that already spent
seconds on the network. An async chunked hasher or a `shasum` dependency buys
that frame back and adds a failure mode. Re-open it if the release grows an
order of magnitude, or if the same read moves onto a per-keystroke path.

**And a claim in core that was wrong.** `AsyncProcess::start()` carried
`@codeCoverageIgnore` over its unspawnable-binary return, justified as "proc_open
spawns even unusable binaries (exec failure surfaces later as an exit code), so
this is unreachable in tests". Not on the ARRAY form: PHP resolves the program
itself and `proc_open` returns false. The path is reachable, it is the only
signal a caller has for "this tool is missing", and symfony-lsp's error messages
were already leaning on it. It has a test now and no ignore.

## The floor that stopped fitting its own rows (2026-09-09)

Found the way this round is supposed to find things: in the user's own
`.porta/layout.json`, which carries `viewSidebarWidthPercent: {"settings": 8}`.

Eight percent is not a width anyone chooses. It is what a drag to the stop
persists, and where it lands is decided by `SettingsNavWidget::minWidth()` —
because `LayoutController::clampSidebarWidth()` applies minWidth OUTSIDE its
max-ratio and neighbour caps:

    max($sidebar->minWidth(), min($width, $sidebar->maxWidth(), …))

so on any terminal narrower than 200 columns, `settings: 8` resolves to exactly
`minWidth()`. That number was a literal **14**.

**14 was right when it was written.** `3a4340cc` (15 July) extracted the
`PanelWidth` trait and gave the nav its own floor; the nav then held three
sections and the language catalogue, and its widest row was `   JavaScript` at
13 columns. Since then Interface and Highlighting joined `SECTIONS`, git became
a plugin, and every installed plugin became an indented sub-row under Plugins.
`   local-history` needs 16. Rendered at the floor, with this machine's own
nine plugins:

    |   image-view…|
    |   graph-view…|
    |   local-hist…|

Three of the nine names the sidebar exists to name, cut — at the one width the
sidebar is guaranteed to be drawn at.

**Translation makes it worse than the English screen shows.** Rows go through
`trans()`, and French renders Keybindings as `Raccourcis clavier` — 19 columns.
At 14 a French user reads `Raccourcis c…` with no plugins installed at all.

`minWidth()` measures its rows now, through the same `rowText()` the render
draws, floored at the old 14 (so a bare English install drags exactly as far as
it always did) and capped at 24. The cap is not cosmetic: given the `max()`
above, a minimum derived from content with no bound of its own would let one
absurdly named plugin win against the terminal's own width. 24 is what
`PanelWidth` asks of a side panel generally; past it, rows truncate as before.

Both halves are mutation-checked — freezing it back to 14 fails three tests,
dropping the cap fails the fourth.

### Why only this widget

The same shape was checked at every other `minWidth()` and left alone.
`FileTreeWidget` (12) and the git list panels (24) hold filenames and commit
subjects — unbounded content, where a content-derived floor is meaningless.
`GitTabsWidget` (30) has finite translated content and was measured: German's
` Änderungen ` + ` Verlauf ` comes to 24, inside its floor.

The settings nav is the one whose content is finite, known at render time, AND
grew after its bound was set. That is the whole distinction — not "measure
every floor", but "a floor over a growing set has to be measured".

## Two tabs called plugin.php (2026-09-09)

`TabListProvider::title()` was `basename($b->path)` and nothing else, so any
two open files sharing a name were two identical tabs. In THIS repository that
is not an edge case — fourteen `plugin.php`, a `composer.json` per package, a
`Installer.php` under more than one plugin — and the cost is closing or editing
the wrong one of a matched pair.

PhpStorm and VS Code both answer with `name (dir)`, and that ordering turns out
to matter here for a reason neither of them has: the side tab strip
(`tabBarPosition: right`, which is what this machine runs) gives a name 19
columns. A leading `symfony-lsp/plugin.php` would truncate away the part that
says what the file IS; `plugin.php (symfo…` still reads.

The qualifier grows a directory at a time, because one level is not always
enough: two `GitSidebar.php` under `<plugin>/src/View/` agree on `View` and on
`src/View`, and separate only at the plugin name. It stops at the first depth
that distinguishes, so the common case stays `plugin.php (git)`.

**Only path-derived titles take part.** Every help page is deliberately one
`Documentation` tab and a titled buffer shows the title it was given — those
are decisions, not collisions, and qualifying them would undo one. The guard is
mutation-checked: removing it turns two `Project Architecture` tabs into
`Project Architecture (a)` and `(b)`, and the test says so.

### Where it is NOT a second site

Checked rather than assumed. Both file pickers already carry the directory as
their own field beside the name — `FilesSource` and
`SearchEverywhereController` each pass `dirname()` as the hit's secondary text,
so Search Everywhere and Go to File never showed a bare basename. The tab strip
was the one surface where the basename stood alone.

## A rebind you could make and never unmake (2026-09-09)

The user's `.porta.json` carries `keymap: {"cursor_left": ["left"]}` — an
override that binds the action to the key it already had. That is what pressing
Enter on the Keybindings page and then pressing a key does, and there was no
way back: the page had `rebind` and nothing else, so undoing it meant editing
the file by hand.

**An override is not only a binding — it is a PIN.** Whatever the file says
survives every future change to the shipped default, silently and forever. A
line that looks like a no-op today is a divergence tomorrow, and this one is
already in a file that travels with the project.

Two halves, and neither works alone:

- **`Del` resets the selected action**, dropping its line from the current
  scope's file — and dropping the whole `keymap` block once its last line goes,
  because a file left saying `{}` reads like a decision nobody made.
- **Overridden rows are marked** (the modified colour on the shortcut). Until
  now the one row with something to reset looked exactly like the two hundred
  showing a default. A colour, not a glyph: this row budgets its width to the
  column, and a marker column would come out of the label.

The flash names the default it went back to (`Reset Copy Path to Ctrl+Shift+C`),
and when the OTHER config file also binds the action it warns instead, reusing
the string `rebind()` already had for the same situation.

### This is not the provenance the page turned down

That settled item is "where did this binding come from", which would mean
re-deriving the merge of three *widget* keymaps outside the merge. This asks
something else entirely: does `keymap` name this action in `.porta.json`? The
config layer knows that exactly, and `layerOverruling()` was already asking it.

### The header hint, measured

`· Del to reset` went in LAST rather than after `⏎ to rebind`, and that is not
a style choice. The settings pane renders as narrow as 60 columns, where the
header truncates — putting the new clause in the middle pushed `Ctrl+F to
filter` off the screen, and an existing test at exactly that width caught it.
Last, the new hint is the one that truncates.

## The catalog that loaded as nothing (2026-09-09)

Found by tripping it. The two new strings above were appended with
`trans-unit` ids that already existed elsewhere in the file, and the result was
not an error — it was **every French string silently reverting to English**.

The chain: duplicate ids are well-formed XML but violate the XLIFF schema;
Symfony's loader validates against that schema and throws
`InvalidResourceException`; and {@see LenientXliffLoader} deliberately catches
it and returns an EMPTY catalogue, because English is the right thing to show
when a translation file is broken. All correct individually, and together they
make a whole locale disappear with nothing logged.

Every gate passed while that was true. `CatalogCompletenessTest` parses the
files with `simplexml` — which answers "is this well-formed XML", a question
the defect passes. What caught it was a widget test that happened to measure a
French label's width, three steps later and for an unrelated reason.

`testEveryCatalogSurvivesTheLoaderThatShipsIt()` now loads all seven through
the real loader and asserts the unit COUNT matches English — not merely that
the load returned, because an empty catalogue is exactly what the defect
produces and "it loaded" is satisfied by it. Reintroducing the duplicate id
fails it with `messages.fr.xlf loads 0 of 748 units`.

### The duplicate the gate led to

The same trip turned up a pre-existing one: `Cannot write %file%` had TWO
`trans-unit` elements in every catalog, with different ids. Well-formed, schema-
valid (the ids differ), and invisible — but the message id IS the source text,
so the loader keeps whichever comes last and the other unit's translations are
dead. In French, Japanese and Hebrew the two had genuinely DIVERGED
(`d’écrire` vs `d'écrire`, `を書き込めません` vs `に書き込めません`,
`את` vs `אל`), which means someone translated a line and watched it change
nothing.

The dead unit is gone from all seven, keeping what actually shipped so the
cleanup is structural — except French, where the surviving copy carried a
straight apostrophe against a catalog that uses `’` twenty-eight times to
thirteen. That one is a typo and was fixed with it.

Not gated: a duplicate SOURCE is legal XLIFF and the loader's last-one-wins is
sane behaviour, so a test would be asserting a house style rather than a defect.
Worth knowing it can happen, which is what this paragraph is for.

## The status bar dropped the file, and kept the cheat sheet (2026-09-09)

Rendered the real bar at four widths in three locales and looked at it. In
English at 100 columns, and in **German at 140**, the row begins:

    |  1:1  LSP:keiner (einen PHP-Server in den Einstellungen aktivieren)  Ctrl+S …|

The file name is not truncated there. It is **gone**. 140 columns is an
ordinary terminal, and the row is spending its width telling the user which
keys exist instead of which file they are in.

The cause is an ordering that was right when it was written and is not now:

    $right = AnsiText::truncate($right, max(0, $columns - 3));
    $leftMax = max(0, $columns - $rightWidth - 3);

The right side takes what it wants and the left gets the remainder — down to
zero. The comment above it even says so ("the steady-state left (the file path)
yields as before"). That was fine while `$right` was short; `$right` is now
pane facts plus a five-item key legend, and translated it is the whole row.

The right's TAIL is that legend, so reserving a floor for the left before
cutting the right spends the difference on hints nobody needs twice. The floor
is 24 columns, never more than the left actually has, and never more than half
the row — on a 40-column split, spelling a path in full at the cost of the
caret position trades one missing fact for another.

### And the path was cut from the wrong end

Fixing the floor alone would have bought `src/Some…`, which is every file in
that directory. The left is elided in the MIDDLE now — `src/Some/De…pleClass.php`
— because it ends with the file's name and begins with the contributed cells,
so both ends carry and only the directories in between are spare.
`AnsiText::elideMiddle()` slices by COLUMN on both sides: a double-width
grapheme landing on either cut must not hand back a row one column wider than
asked, which is the classic way chrome overflows and garbles the line beneath.

One consequence, taken deliberately: the clickable status cells are withdrawn
once the left is elided at all. Their spans are offsets into the UNCUT string,
and a middle cut moves every column after it while leaving the width the old
filter compared still plausible — a hit region that answers for the wrong cell
is worse than none. Mutation-checked, along with the floor, the half-row cap
and the elision itself.
