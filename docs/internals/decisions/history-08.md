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
