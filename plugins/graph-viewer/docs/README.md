# Graph viewer

Opening a `.dot` or `.gv` file shows its source with the Graphviz graph beside
it as a [live preview](../../../docs/editor.md#live-preview), **the picture `dot` itself draws** — real splines, arrowheads,
fills and fonts (install graphviz, or point the graph-viewer plugin's `dotPath` setting at it).
Zoom with `=`/`-`, `0`/`f` fit, `1` actual size, a click on the status bar's
`+`, `-` or `0 fit`, or `Ctrl+wheel` at the pointer — including the trackpad's Ctrl+two-finger-scroll, which glides (see
[the image viewer](../../image-viewer/docs/README.md) for why that is as close to a pinch as a terminal
gets); pan with the arrows, the wheel, or by dragging; select nodes with
`n`/`p` or a click, which frames the node and names it in the status bar. A
click selects the node the picture shows under the pointer, and nothing over
the empty space around it. A terminal that draws pixels outlines the selected
node in the picture itself, to the pixel.
Zooming in re-renders the graph at a higher resolution rather than magnifying
pixels, so labels sharpen as you go.

`/` finds a node by name. Two hundred namespaces laid out by `dot` are not
something you can read your way through, so the key opens the ordinary
filterable list over every node — type to narrow it — and picking one selects
that node and pans to it, exactly as `n`/`p` would. Each row carries the node's
**in/out degree**, which is the one thing a list can say and the picture cannot:
the hub you are looking for is the row with fifteen edges, and it looks like
every other box on screen. `Graph: Find Node` does the same from the palette.

`v` toggles the two renderings. **Bitmap** is what the graph actually looks
like — dot's own splines, arrowheads and fonts — and **cells** draws the node
labels as real terminal text, which stays readable at a pane size where the
bitmap's type is a few pixels tall. Cells is also what paints when dot cannot
produce a bitmap at all (no libgd) or has not answered yet, and the standing
preference is the graph-viewer plugin's `renderMode` setting. A PlantUML
picture has no layout to draw from, so it ignores the toggle.

The same tab opens **PlantUML** documents — `.puml`, `.plantuml`, `.pu`,
`.iuml` and `.wsd` — rendered by the `plantuml` tool: set the *PlantUML path*
setting to a launcher **or straight to a `plantuml.jar`** (it is run with
`java -jar`), or leave it empty to use `plantuml` from your PATH. Zoom, pan and
the re-render-as-you-zoom all work the same, and a syntax error is reported in
the banner instead of being drawn into the picture. What a PlantUML tab does
*not* have is a node model, so `n`/`p`, click-select, the node counts and the
`v` cell rendering do nothing there. PlantUML uses Graphviz for several diagram
types (class, activity, state), so install `dot` as well if those come out
empty. With no tool installed — or with *Preview PlantUML beside its source* off on the
graph-viewer plugin's page — these files stay editable text.

`v` switches to a **cell rendering** that draws node labels as terminal text
— less faithful, but readable when the picture's own type is only a few
pixels tall. The *Graph rendering* setting (Settings ▸ General) picks which
one new tabs open with. It is also what you get automatically if your
graphviz was built without PNG support, with the reason in the banner.

The graph follows the source as you type. An invalid document keeps the
previous graph, and what `dot` says is wrong shows in the editor like any other
error: underlined on the line it names, in the gutter and in Problems. With the
preview shown alone, where the editor is out of sight, the first error also
tops the picture. A failure of Graphviz itself (not installed, or answering
nonsense) is the pane's to say, in a banner. The viewer is a bundled plugin, enabled by
default — disabling it opens `.dot` files as plain text with no preview.
**Plugins ▸ Project Architecture** generates a namespace-dependency graph of
the current project into `architecture.dot` and opens it with the graph alone
on screen (`Ctrl+Alt+V` brings its DOT back). The
plugin's *Architecture output directory* setting picks where that file goes —
empty means the project root, a relative path resolves from it, an absolute one
is taken as given, and the directory is created if it does not exist. (Its
*Architecture command* setting supplies the DOT instead — that command's stdout
wins.) `Graph: Export SVG/PNG` in the palette renders the active graph
beside its file.

## Keys

When a `.dot`, `.gv` or PlantUML tab is active. The zoom keys match the image
viewer's, but zooming in re-renders the graph at a higher resolution rather
than magnifying pixels.

| Key | Action |
| --- | --- |
| `=` / `-` | Zoom in / out (also `Ctrl+wheel`, anchored at the pointer) |
| `0` / `f` | Fit to window |
| `1` | Actual size |
| Arrows / `Shift+arrows` | Pan (also the wheel, or dragging) |
| `n` / `p` | Select the next / previous node, framing it and naming it in the status bar |
| `/` | Find a node by name — a filterable list of every node with its in/out degree; picking one selects it and pans to it |
| `v` | Toggle bitmap / cell rendering — the picture `dot` draws, or node labels as real terminal text, which stays readable when the bitmap's type is a few pixels tall (no effect on a PlantUML picture, which has no layout to draw from) |
