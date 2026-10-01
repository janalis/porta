# Decision history, part 8

Entries in the order they were written. Part of the [decision history](README.md); the verdicts they led to are in [Settled questions](../decisions.md).

## A mirrored frame's surfaces ride a layer of their own (2026-10-01)

A Hebrew audit of every screen went through 33 screens, each bare and then under
the File menu and a mid-bar menu, 226 frames in all. It checked row widths,
shadow placement, corner shapes, and every reordered row against fribidi (255
segments, all equal). It found one defect, on every screen: **an open surface
blanked a hole at its logical position.** With the File menu open, the editor's
left third was empty. Under a mid-bar menu, each line stopped short of the box.
Opposite a toast, the tree's top rows were gone.

`PaneReorder` blanked each surface's logical cells, reordered the panes, and
stamped the surface at its mirror across the row. That only lines up for a
mirrored pane spanning the whole row: part 5 already recorded that "the hidden
cells leave their gap at the MIRRORED span". A verbatim pane keeps the hole where
it was, and a narrow mirrored pane moves it within its own span. The text under
the surface's logical cells was overwritten at compose time, so no reorder could
bring it back.

**The fix composes the surfaces after the reorder.** Under an RTL locale,
`OverlayDelivery` and `MenuOverlayController` push spans to
`Overlay/State/FloatingLayer`, a blank screen-sized canvas, and the panes keep
only the backdrop dim. `PaneSegmenter::surfaces()` hands `BidiTui` the canvas
rows. `PaneReorder` no longer blanks anything (`blankRegions()` is gone) and
stamps each region from its canvas row. The LTR path is untouched.

Two follow-on edges came out of the audit re-run. A region claimed as a rectangle
takes cells no surface paints, and the canvas stamped them blank: the cell beside
the title row, and the one under the far corner. Regions now carry `firstRow` and
`lastRow`. A verbatim box moves as one block without flipping, so rows narrower
than the box were placed a column off; they now carry their `box`.

## Views open in a window, and every modal is one kind of window (2026-10-01)

Settings, Git and Review replaced the editor area, and while one was open the
menu greyed every item off the view's short list — the way to the other views
included. The round moved them into **a movable, resizable window over the
mounted editor**, opened centred at 80% of the workspace; made every floating
surface one kind of window; and moved the docs from an editor tab into a window
of their own.

**Hosting.** A view's region is a live widget tree, so three ways to float it
were weighed against the code:

| Way | Verdict | Why |
| --- | --- | --- |
| Off-tree, as Welcome renders its cards | rejected | `HitTester::rect()` answers null for a widget with no context, nothing joins the FocusManager, `resolveElement()` falls back to the default stylesheet (the theme is lost), and timers and render requests through the context go nowhere |
| A tree widget rendering the region in its own `render()` | rejected | a non-fill leaf renders in the vertical layout's measuring pass with position tracking off (`suppressStack`), so no rect is recorded; and it lands a frame late for panes already composited |
| Attached under a `hidden` host, rendered before each frame | **adopted** | the renderer drops hidden children before layout, so the frame never draws it; between frames the position stack rests at `[[0,0]]`, so `renderWidgetLines()` on a container padded to the window's origin records every rect at its screen cell — no router, divider drag or select list changed. A permanent spike (`ViewWindowPresenterTest`) fails if a vendor bump breaks either premise |

**The menu.** Every item stays enabled. `$viewSafe` survives with its meaning
turned round: not "what may run while the view is open" but "what runs over it";
any other command — from the menu, the palette or a key — closes the window
first, so its effect is seen (`ViewCommandGate`). The gate on keys needed a
list of the workbench's own commands (`ide_menu`, focus cycling, the palette…):
F10 closed the window it was pressed over until it had one.

**One window model.** `WindowFrame` replaced two copies of the drag state
machine (Welcome, Find in Files) and gave the pickers one: a title-bar move,
every edge and corner but the title bar's own edge as a resize handle, a click
beside a window closing it (Welcome used to swallow it). Pickers were
"fixed-centered, not draggable" only as a description; nothing settled it. They
keep the tallest-height rule — placed at their tallest, shrinking from the
bottom — and a resize sets that height.

**The close button is the platform's**: a red glyph leading the bar, title
centred, on macOS; a `☒` ending it elsewhere. The first macOS glyph was `ⓧ`,
which is a circled LATIN letter: strong left-to-right under bidi, and East
Asian **Ambiguous** — a terminal set to draw those wide pushed the whole title
bar a column out on the user's screen. `⊗` is Neutral and bidi-neutral;
`GlyphsWidthTest` pins both buttons to narrow. Under the pointer the button
darkens, with no fill: a red cell read as a hole cut in the frame.

**The docs** are a view window holding the page alone (the region's sidebar and
divider hidden), with a find bar of its own — the editor's sits behind the
window. No help tab exists any more, which also retired the tab list's and the
session's special cases for one.
