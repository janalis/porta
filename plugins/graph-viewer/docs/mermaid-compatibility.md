# Mermaid compatibility

The compatibility target is Mermaid **12.1.0**, commit
[`21f72f07ea22c0af48a3149c550654e80d8e40cb`](https://github.com/mermaid-js/mermaid/tree/21f72f07ea22c0af48a3149c550654e80d8e40cb).
Its [detector registry](https://github.com/mermaid-js/mermaid/blob/21f72f07ea22c0af48a3149c550654e80d8e40cb/packages/mermaid/src/diagram-api/diagram-orchestration.ts),
family grammars and tests define syntax compatibility. A passing Porta test suite
does **not** mean this entire target is implemented.

## Native implementation status

| Family | Native parsing and cells | Native graphics | Selection and search |
| --- | --- | --- | --- |
| Flowchart / graph | 261 pinned body fixtures; optional/root directions, repeated groups, multiline links and extended operators | All 53 registered ordinary shapes and 150 unique names/aliases, typed endpoints and bounded CSS/text sizes; icon tiles and `data:` images | Native previews |
| Sequence | Existing subset; YAML/JSON participant configuration, lifecycle, controls and menus | Terminal-cell geometry; pinned participant glyphs, message endpoints, lifecycle headers and mirrored footers | Native previews |
| Railroad (primitives, EBNF, ABNF, PEG) | 86 pinned bodies; constructor trees, precedence, groups, alternatives, optional/repeated terms, exceptions, lookahead and metadata | Separate syntax tracks with terminal/nonterminal shapes, branch rails, bypasses, return loops and finite-count captions | Rule headings and operands with source origins; rails and markers excluded |
| Venn | 18 pinned bodies; sets, overlaps, labels, explicit/indented text and exact-target styles | Area-proportional translucent circles, fitted overlaps, clipped fills and outside callouts | Authored captions/text and compact overlap targets; decorative fills excluded |
| Event modeling | Time/reset frames, aliases, qualified entities, explicit inputs, typed/opaque data, notes and given/when/then | Chronological role-colored cards, lane bands, data compartments and inferred/explicit links | Authored frames with source origins; synthesized headers stay outside selection |
| Architecture | Groups, services, junctions, labeled/typed side ports, alignment hints and seeded configuration | Nested frames, five built-in resource glyphs, text/missing icons and orthogonal port routes | Services, junctions and compact group headers with source origins |
| Use case | Actors, use cases, boundaries, relationships, JSON tables, notes, classes and typed metadata | Actor variants, business slashes, package tabs, UML endpoints and ordered tables | Actors, use cases, JSON tables, notes and boundary headers |
| Gantt | Tasks/tags, sections, dates/durations, after/until references, includes/excludes, metadata and axis directives | Proportional bars, milestones, section bands, excluded-date shading, calendar ticks and vertical markers | Task bars, markers and section headers with source origins |
| Agent flow | Flow/global scopes, connectors, shape aliases, YAML contracts and named-link metadata | Nested native frames, typed links, lined reference documents and collapsed summaries with crossing-edge projection | Visible steps and compact flow headers with source origins |
| Swimlanes | Flowchart syntax subset, optional direction, nested lane ownership and anonymous lanes | Global temporal ranks, parallel tracks, nested frames, typed endpoints and source-linked headers | Authored lanes and steps; synthesized lanes are not selectable |
| Block | Nested authored grids, auto/explicit columns, spans, spaces, labels, CSS/classes and relationship chains | Grid frames, classic outlines, pinned arrow-direction polygons, typed endpoints and self-loop space | Cards and composite headers with source origins |
| C4 | All five headers, element/boundary/deployment macros, named arguments, relationships and local style/layout updates | Nested grids, person/queue/database glyphs, deployment perspective and dynamic numbering | Elements and boundary headers with source origins |
| Wardley | Coordinates/percentages, anchors/components, pipelines, dependencies/flows, evolution, custom stages, notes/annotations and sourcing metadata | Projected map axes, pipeline frames, strategy/inertia glyphs, trend arrows and positioned labels | Components, anchors and annotations with source origins |
| Git graph | Branch/checkout/switch, typed commits, repeated tags, merges, validated cherry-picks and LR/TB/BT | Colored branch rails, commit glyphs, labeled chronology and parallel histories | Branches and commits with source origins |
| State | Composite states, concurrent regions, aliases/descriptions, local directions, transitions, notes and bounded CSS | Rounded compartments, start/end markers, choices, fork/join bars, region separators and side-column notes | States, composite headers and note origins |
| Class | Class declarations/labels, generic identities, members, annotations, nested/dotted namespaces, relationships and bounded CSS | Attribute/method compartments and UML inheritance, aggregation, composition, dependency and lollipop endpoints; notes and callbacks remain descriptive metadata | Classes and source-linked member legends |
| Pie | Ordered numeric slices and metadata | Ordered circular sectors and donuts, outside labels and the legend | Slice labels |
| Quadrant | Unit-square coordinates, axes, quadrant labels, metadata, point styles and forward class definitions | Cartesian point markers; radius, fill, border color and border width; neutral quadrant fills and horizontal Y-axis labels | Points |
| XY chart | Vertical/horizontal line and bar series, category/numeric axes, ranges, labels and metadata | Bars behind connected point markers; clipped numeric ranges and fixed palette | Individual data points and bars |
| Packet | Contiguous single bits, inclusive ranges, +count, quoted labels and metadata | Proportional fields split over ascending 32-bit rows with bit numbers | Field fragments linked to their declarations |
| Kanban | Indented columns/cards, repeated ids, shape labels, flat metadata and decorations | Aligned headers, equal-width columns, wrapped cards and textual metadata | Columns and cards |
| Treemap | Indented quoted sections and valued leaves, multiple roots, metadata and classes | Proportional nested tiles, section headers, values and class colors/borders | Sections and leaves; full labels and values in legend |
| Sankey | Three-column CSV, quoted/multiline labels, duplicate flows and finite non-negative values | Proportional filled ribbons, normalized weights, justified sinks and fixed palette | Nodes at first source occurrence; flow legend |
| Journey | Sections, tasks, scores, actors and metadata | Pinned columns: section headers, task boxes with actor dots, the timeline arrow and score faces | Tasks |
| Mindmap | Indentation, repeated labels, quoted multiline text, shape syntax and decorations | Two-sided mind map; section colors; square, rounded, circular, hexagon, cloud, bang and plain outlines | Native previews |
| Entity relationship | Entities, aliases, attributes, keys, quoted comments, cardinality aliases, four directions and bounded node CSS/classes | Pinned attribute tables with aligned type/name/keys/comment columns, subgraph frames; crow's-foot, optionality and MD-parent glyphs; solid/dashed links and numeric role captions | Entities at first mention; attributes and relationships in source-linked legend |
| Info | `info`, optional `showInfo`, common title/accessibility grammar | Text identifying Porta native rendering and compatibility target; upstream reports its runtime version | One diagnostic linked to the header |
| Requirement | Six requirement types, elements, fields, seven directed relationship kinds, directions and bounded node CSS | Ranked `requirementBox` cards and labeled edges with SysML markers | Every declared card; relationship and metadata legend |
| Cynefin | Five domains, quoted items, metadata and transitions | One field of expanding quadrants, the seeded dashed folds and red cliff, the central confusion ellipse, item cards and exterior arrow routes | Domain headers and every retained item; transition legend |
| Radar | Named axes/curves, ordered or referenced entries, metadata and options | Circular/polygon grids, outlined closed curves, markers and optional legend | Individual data points linked to their authored entries |
| Ishikawa | Effect and indentation-based cause hierarchy; both header aliases | Effect head, spine, alternating pairs and nested horizontal/diagonal bones | Effect, causes and subcauses |
| Tree view | Indentation or Unicode branch prefixes, bare/quoted labels, metadata and annotations | Outline rows, elbow connectors, wrapped text and built-in highlight | Every authored row, including repeated labels |
| Timeline | LR/TD, periods, stacked events, sections and metadata | Period axis with perpendicular event chains | Periods and events |

Native flowchart geometry accepts `nodeSpacing`, `rankSpacing`, `diagramPadding`,
`padding`, `minNodeWidth`, `titleTopMargin` and `subGraphTitleMargin.top`/`bottom`.
Values are bounded from 0 to 1,000; spacing and title margins require integers,
while padding and minimum label width also accept finite fractions. Pixel values
map to native units at ten pixels per unit, with existing native defaults when
an option is absent. Minimum label width and padding apply before outline fitting;
label-free glyphs and explicit family dimensions retain their own sizes.

Edge curves follow the pinned order: an edge's own `id@{ curve: … }` or indexed
`linkStyle … interpolate`, then `linkStyle default interpolate`, then
`flowchart.curve`, then `basis`. Absent, empty, unknown and non-string names fall
back as upstream does, without an error. Edge-ID metadata never creates a vertex.
`interpolate` without CSS keeps the edge's earlier styles. All thirteen pinned
names (the twelve d3-shape curves plus `rounded`) are sampled at 32 steps per
piece. `flowchart-curves-reference.json` holds 234 d3-shape 3.2.0 and pinned
`generateRoundedPath` cases. ER, mindmap, state, class and requirement edges
use `basis`; block edges stay straight, because the pinned renderer passes them
collinear points.

Routed edges imitate dagre's doubled ranks. Each edge crosses a mid-rank point
that keeps the target's cross-axis position, or the source's when the target has
more incoming edges than the source has outgoing ones. An edge that spans ranks
reserves a two-unit lane through every intermediate rank band, then enters its
target along the rank axis. Lanes follow each rank's nodes in declaration order;
native placement still has no crossing minimization. Swimlane routes are
orthogonal through the rank gap, and their `basis` default becomes `rounded`,
as in the pinned renderer. Edges without a rank gap stay straight.

Authored `inheritDir: true` gives implicit subgraphs the global direction;
`false` gives isolated implicit subgraphs top-to-bottom direction. Explicit local
directions and the existing external-link rule take precedence. Native placement
uses its own ranked layout: this is not exact ELK/Dagre geometry, and the pinned
ELK default can ignore spacing and inheritance options that Dagre honors.
`useMaxWidth` and `arrowMarkerAbsolute` accept booleans; viewport fitting stays
caller-owned and native SVG uses drawn arrowheads rather than marker URLs.
Document overlays preserve zero and false values, and subgraph margin sides merge
independently. Title shifts resample source rows and remain cancellable for large
maps. Other flowchart options and math remain under the open compatibility work below.

### Icons and images

The pinned build registers no icon pack beyond each family's own built-ins, and a page
registers others in the browser. Native rendering draws the same built-ins and, for every
other name, the same unknown-icon tile: a white `?` on the `#087ebf` square. Flowchart
`@{ icon }` nodes draw that tile, 48px or `h` pixels square, above their label, or beneath
it with `pos: t`, and the icon name never becomes label text. Flowchart `@{ img }` nodes draw
a `data:` PNG, JPEG, GIF or WebP at `w`×`h` pixels, one side keeping the picture's proportions;
SVG export re-encodes the validated bytes, so authored text never reaches the file. A remote
or relative source draws a crossed frame of the same size, because native rendering never
fetches, while the pinned build fails the whole render on an unreachable image. Tree view
draws its built-in `folder` and `file` glyphs in `iconColor`, the unknown tile for other names,
and with `showIcons` the defaults by `filenameIcons` and `extensionIcons`, as `getNodeIcon`
resolves them. Architecture draws its five built-in glyphs. The pinned mindmap and kanban
renderers draw no icons at all, and neither does native. Host-registered packs are a non-goal.
`FlowchartMediaTest` and `TreeViewIconsTest` check the sizes, placements and resolution.

Native scenes can be exported to SVG without GD, and to PNG with GD/FreeType.
Both outputs share one drawing model. SVG includes the bundled IBM Plex Mono
font and its license. PNG allocation is bounded by the image decoder's pixel
limit and a 16,000-pixel axis limit. Work yields after 64 steps or a 4ms slice,
whichever comes first; a single GD operation is synchronous.
In a PHAR build, FreeType receives a private temporary copy of the font. That
copy is removed on completion and cancellation. PNG text uses the bundled face where its
`cmap` has the glyph and otherwise the first host font that does (Arial Unicode, Hiragino,
Apple SD Gothic Neo, Apple Symbols, DejaVu, Noto CJK and symbols, Droid Sans Fallback, or the
Windows equivalents). GD cannot draw characters beyond the Basic Multilingual Plane, so emoji
draw as a box in PNG; SVG keeps the characters and falls back to the viewer's fonts.

Markdown string labels (`` "`…`" ``) in flowchart nodes, edges and subgraph titles, and in
mindmap nodes, follow the pinned HTML label path. `**strong**` and `__strong__` draw bold,
`*em*` and `_em_` italic, nested emphasis bold italic, and `\` escapes punctuation. Emphasis
uses CommonMark's delimiter rules, as marked does. Newlines and `<br>` break lines.
`markdownAutoWrap` wraps words greedily at the family width: flowchart `wrappingWidth` or
mindmap `maxNodeWidth`, at 7.5 pixels a character for the pinned 16px label face. An
unbreakable word keeps its own line, and `markdownAutoWrap: false` never wraps. Elements size
to the wrapped text, and SVG and PNG draw each styled run in the bundled Bold, Italic or
BoldItalic face. `fixtures/mermaid/labels/markdown-reference.json` records the pinned lines
and runs, and `MermaidMarkdownTest` compares them. Terminal cells show the plain text.
Headings, lists, code spans and links inside Markdown strings stay literal text.

Selection ignores metadata and activation decorations. Native flowchart
outlines also define bitmap hit regions, so empty circle/diamond corners do
not select a node. CLI pictures can project native semantic IDs and unique labels
onto the rendered SVG's bounds, including nested transforms, viewBox fitting,
label offsets and inherited text alignment. Duplicate or ambiguous targets remain
unmapped. CLI regions are conservative bounds; native outlines retain their
shape-specific silhouettes.

The official CLI's installed Puppeteer browser rasterizes that same SVG snapshot,
including HTML/math labels, fonts and CSS. A missing or incompatible browser keeps
the existing CLI PNG path without a map. Native parsing or mapping failures keep
a successful CLI picture. One SVG up to 4 MiB is cached by source and live tool
path for resolution-only refinement, preserving selection. Mapping is bounded to
4 MiB, 32,768 SVG elements and 64 tree levels; browser rasters keep the 16-million
pixel / 16,000-axis budget. Temporary helper/SVG/HTML files are private and removed
on completion or cancellation, including materialized PHAR resources.

Exports use the open text buffer, including unsaved edits, or the saved file
for a viewer-only tab. Rendering failure and cancellation preserve an existing
export; successful output is published atomically. The export commands apply to
standalone diagram documents. Native inline Markdown fences expose semantic
hit regions, searchable source-linked labels, a Find node picker and SVG/PNG
controls. Exports use the successful unsaved fence snapshot and its current diagram
number. Pending/stale results disable controls and retire delayed selections.

Flowcharts render supported `style`, `classDef`, `class` and inline `:::class`
node assignments. Defaults, named classes and inline styles compose in that order;
forward class definitions and assignments, multiple target IDs and merged property
updates are supported. Styling does not create missing nodes or change declaration
origins, shapes or compound layout. Supported properties are fill, stroke, text
color, stroke width (0–1000 pixels, scaled to native units) and dashed/solid borders.
CSS named colors use their web values rather than Graphviz/X11 differences.
Dash lists accept whitespace or escaped commas (`stroke-dasharray:5\,5`);
`stroke-dasharray:none` resets inherited or authored dashes to solid. SVG/PNG
draw the authored lengths (an odd list repeats, as SVG does); terminal cells
show dash presence.
`fill:none` and `stroke:none` omit the respective paint in SVG/PNG; unfilled
nodes and nested subgraphs reveal the paint beneath them. Terminal cells retain
their existing box rendering. The `none` keyword is case-insensitive and does
not apply to text color.
Unsupported properties and invalid values produce located errors. The shared
limits are 4,096 style statements and 65,536 class assignments. Bold/italic font properties and bounded border shorthands are supported.
Transparency follows CSS: `opacity`, `fill-opacity` and `stroke-opacity` (numbers
or percentages, clamped to one) fade the matching paints, and colors keep their
alpha in every notation khroma reads, including `rgba()`, `hsla()`, `#rgba`,
`#rrggbbaa` and `transparent`, with escaped commas inside functional colors.
Other CSS properties are rejected (see Non-goals).

Flowchart `linkStyle` now applies stroke color, graphic stroke width, dashed/solid
lines and label color to indexed edges or `default`. Indices follow expanded
edge declaration order and must refer to existing edges; defaults may precede
edges. Repeated declarations replace earlier properties at that target, and
indexed properties override the current defaults. Styles preserve routes, tips,
labels and source selection, including front matter title translation. Terminal
cells use edge and label colors; SVG/PNG also use widths. Limits are 4,096 edge
style statements and 65,536 target assignments. `fill:none` is accepted on
indexed and default edge styles; it preserves unfilled paths and filled
arrowheads. Edge transparency and authored dash lengths follow the node rules
above. Colored edge fills are rejected, as their paint has no native meaning on an
open path. Flowchart subgraphs now render authored `style` and class assignments for fill,
border color/width, label color and dashed/solid borders. Nested container
geometry, source origins and routes are unchanged. The implicit default node
class does not style containers; assigning `default` explicitly does. SVG/PNG
include fills and widths; terminal cells show border/label colors and dashed
frames. Front matter captions preserve container presentation.
Click declarations retain their existing validation behavior.

Modern aliases now reach existing native outlines: `fr-rect` and
`framed-rectangle`; `pill` and `terminal`; `question`; `lean-right`, `in-out`,
`lean-left`, `out-in`; `priority`, `trapezoid-bottom`, `trapezoid`; `manual`,
`trapezoid-top`, `inv-trapezoid`; and `double-circle`. Alias names preserve
layout, routing and source behavior, including circular sizing. Framed
rectangles, double circles and cylinders apply authored stroke widths and
dash presence to their internal borders as well as their outer outlines.

Flowchart triangles accept `tri`, `triangle` and `extract`; flipped triangles
accept `flip-tri`, `flipped-triangle` and `manual-file`. Native graphics use
three-sided outlines with shape-aware edge endpoints and hit regions. Empty
corners do not select a node. As pinned, a triangle is square, its side the
padded label width plus the label height, and the label sits half a padding
from the wide base, including multiline labels and nested groups. Terminal cells
retain boxes. `FlowchartTriangleTest` checks the proportions.

Notched cards accept `notch-rect`, `card` and `notched-rectangle`; manual-input
rectangles accept `sl-rect`, `manual-input` and `sloped-rectangle`. Both use
clipped native outlines for exports, border routing and hit regions, with cuts
bounded by node width and height. Styles, captions and source links are retained.
As pinned, a card's notch is twelve pixels whatever its size and manual input's
left side is a third of the height shorter; lean shapes and trapezoids slant half
their height across and hexagon points take two sevenths of it, each widening
the shape rather than narrowing its label. Terminal cells retain boxes.
`FlowchartCutCornersTest` checks these against the pinned outlines.

Mindmaps use a two-sided layout: the root in the middle, the first half of its
branches to the right and the rest to the left, each subtree stacked so siblings never
overlap and a parent centers on its children. The pinned default, cose-bilkent, is a
force simulation whose exact geometry is a non-goal; the two-sided tree keeps its
radiating shape deterministic. The pinned build registers no tidy-tree loader, so
`layout: tidy-tree` falls back to the default there and here alike. Sections color
each branch from `cScale`, as `assignSections` does. Plain nodes draw the pinned
rounded box in the section color with a line along its foot; cells keep boxes. Labels
of every kind wrap between words once wider than `maxNodeWidth`, as pinned.
Icon and class declarations are validated and, as pinned, draw nothing: 12.1's
mindmap renderer drops icons, and classes only name CSS the host page supplies.
`MindmapLayoutTest` checks the sides, stacking, centering, margins and source rows.

Quadrant charts keep authored X/Y coordinates, with Y increasing upward.
Inline point styles override class properties; unknown class names use defaults.
Radius and border width are limited to 1,000 pixels each. Quadrant captions move
to the top of each region when points are present; empty charts center them.
The X axis appears below charts with points and above empty charts. Pictures turn the
Y-axis captions to read upward beside the chart, as pinned; cells keep them level where
they fit. Cells use the graph viewer's boxes/markers plus a
wrapped legend. Point hit-testing uses selectable geometry, so decorative
captions cannot occlude a point in either native display mode.

XY charts support `xychart` and `xychart-beta`, signed decimal values, named
series, quoted categories, point labels and accessibility metadata. Bars start
at the first endpoint of the value range; multiple bar series overlap. Excess
values are truncated to the category count before calculating automatic ranges.
Axis declarations resolve over the complete document rather than upstream's
order-sensitive database updates. Pictures turn the side axis title to read upward,
as pinned, while cells keep it level. Line series keep selectable dot markers. Selection summaries shorten long labels;
custom point labels remain visible in graphics.

Packet diagrams preserve inclusive range widths and split continued fields at
configured row boundaries (32 bits by default). Native output places metadata above the fields, wraps field
labels, and limits graphics to 256 label characters and chooser summaries to
240 columns. It rejects gaps, overlaps, reversed ranges, zero-bit fields, more
than 4,096 fragments or more than 131,072 bits before expansion. `packet.showBits` is supported in front matter and leading initialization
directives. It defaults to true; false hides bit numbers and compacts rows,
preserving field proportions, row splits, legends and source navigation.
`packet.bitOrder` accepts `ascending` (default) and `descending`; descending
mirrors fields and endpoint numbers within each configured row, including partial
rows, while declarations and source identity remain in authored order. It
composes with `showBits` and initialization overrides. `packet.bitsPerRow`
accepts integers from 1 to 1,024 in front matter and initialization directives;
field splitting, tick positions, metadata width and fragment limits use that
size. The total 131,072-bit and 4,096-fragment limits remain in force.
`packet.bitWidth` accepts finite numeric values from 1 to 1,000 (default 32).
Native geometry scales these values by one eighth: default bits remain four
logical units wide. Fields, endpoint numbers, metadata width and label wrapping
share that scale, including descending and partial rows. Values outside the
native range are rejected. `packet.rowHeight` accepts finite numbers
from 1 to 1,000 (default 32), scaled by one quarter to native logical units.
It is a minimum: wrapped labels expand rows when necessary, keeping all fields
in a row aligned and following rows below them. `packet.paddingY` accepts finite
numbers from 0 to 1,000 (default 5), scaled by 0.8 to native row gaps. Zero
removes the gap but retains space needed by visible bit labels. Explicit zero
survives initialization merging. The existing outer margin remains fixed.
`packet.paddingX` accepts finite numbers from 0 to 1,000 (default 5), scaled by
one eighth. It trims the right side of each displayed field while retaining
bit-slot positions and overall row width. Wrapping, endpoint labels and hit
regions use the trimmed width, so gaps do not select a field. Native fields
retain at least four logical units when the slot permits, or the full slot for
narrower bits; excessive padding cannot collapse them. This adds small gaps to
unconfigured native packets, whose fields previously touched. Rows wider than
1,024 bits and fractional rows are non-goals (see below): the pinned build draws
2,048-bit rows 65,538 pixels wide and numbers a 7.5-bit row's bits `6.5`, `7.5`.

Kanban uses the first node's indentation as the column level; all deeper nodes
are cards in the latest column. It rejects later declarations above that level.
Repeated authored ids stay separate, avoiding ambiguous source following.
The shared mindmap label grammar accepts shaped and quoted labels, but native
Kanban always draws headers and rounded cards. Metadata accepts scalars, flow
lists of scalars (read as JavaScript joins them, `[x, y]` as `x,y`) and label
overrides, in flow or block form; nested maps are rejected, as the pinned lexer
rejects them. Every title is Markdown, backtick-quoted or not, as pinned: bold
and italic runs draw in their faces and titles wrap between words. Pictures lay cards out as pinned `kanbanItem` does: the title
left-aligned at the top, the ticket at the bottom left, underlined when `ticketBaseUrl`
links it, the assignee at the bottom right, and a 4px bar down the left edge, red, orange,
blue or light blue for `Very High`, `High`, `Low` and `Very Low` (`Medium` draws none).
Icons draw nothing, as pinned; cells and the legend keep every field as text. Class
declarations do not apply CSS, and ticket ids never become executable links.
`KanbanCardsTest` checks the placements and styled titles. Work is bounded to 4,096 declarations and
65,536 bytes per statement.

Sankey normalizes finite values before accumulating weights, so large authored
flows cannot overflow display geometry. Node height uses the greater of incoming
and outgoing totals plus label padding. Duplicate links remain distinct and
share contiguous bands; cycles, negative/non-finite values and malformed CSV
are rejected. Numeric fields must be complete numbers, rather than upstream's
permissive `parseFloat` prefixes. Native labels also accept UTF-8. SVG/PNG uses
sampled filled ribbons; cell mode keeps centerlines and a wrapped legend. Native
layout uses source order within topological layers and aligns them as
`nodeAlignment` sets; the pinned default gradient links run from the source's
color to the target's in both exports. D3's iterative relaxation is browser
geometry, a non-goal (see below). Limits are 4,096 nodes/flows and
65,536 bytes per record. Selection targets nodes; ribbons and label decorations
are not separate selectable elements.

Treemap supports both header aliases, single/double-quoted names, nested sections,
multiple roots, decimal values with thousands commas, metadata and forward class
assignments. Repeated class definitions merge supported fill, stroke, text color,
border width and dash presence. SVG/PNG shows nested tiles and independently
selectable section headers; labels wrap and clip within available tile space.
The chooser and legend retain full text. Cell styling uses the shared box renderer.
Weights are normalized before descendant sums. Positive siblings give zero values
zero area; all-zero sibling groups divide their display area equally. Layout uses
source-ordered binary tiling rather than D3's sorted squarification. Complete
finite non-negative numbers are required instead of permissive numeric prefixes.
Class styles also accept the three opacities and alpha colors, and draw authored
dash lengths; other CSS is rejected. Limits are 4,096 nodes/classes, 128 section levels
and 65,536 bytes per statement.

Tree view accepts the `treeView-beta` header, indentation-based forests and
standard/heavy box-drawing prefixes. Tabs in branch prefixes expand to four
spaces, and branch columns infer depth. Quoted multiline labels, consecutive
spaces, Unicode and trailing directory slashes survive parsing. Source lines are
preserved through skipped decorative lines and multiline input. Metadata must
precede nodes. Native graphics show elbow connectors and `:::highlight` rows;
labels wrap rather than extending an unbounded single row. `##` descriptions
appear beside labels. Explicit icon names are inert text, with empty/none icons
suppressed; other class names remain in the legend. As pinned, every tree hangs from an
implicit bold `/` root, directories draw bold without their slash, descriptions follow in
italics two spaces on, and icons stand before the label (see Icons and images). As pinned,
the grammar has no `classDef` or `style` (such lines are node labels) and a class other
than `highlight` only names CSS the host page supplies (see Non-goals). Mixed indentation/box-drawing children are rejected. Limits are
4,096 nodes, 128 levels and 65,536 bytes per statement.

Ishikawa accepts `ishikawa` and `ishikawa-beta`. The first label is the effect;
cause levels are relative to the first cause and clamp below that baseline, as
in the upstream database. Standalone comments are skipped. Quotes, separators,
metadata words and inline comment markers within labels are literal text; shared
entity and `<br>` decoding applies after lexing. Native graphics draw a curved
effect head, horizontal spine, alternating cause pairs, and horizontal/diagonal
sub-bones. Arrows point toward the parent bone. Iterative traversal allocates
vertical slots by measured label heights and moves later cause pairs leftward.
Cells use the shared graph renderer. Bones, sides, arrows and order match the
pinned pictures; their exact spacing is browser geometry, a non-goal (see below).
An effect label is
required, with limits of 4,096 nodes, 128 levels including the effect and 65,536
bytes per label.

Radar accepts `radar-beta` with an optional colon, named axes and display labels,
ordered or referenced curve entries, multiline bodies, comments and metadata.
References can precede axes; repeated references use their first value. Options
control legend visibility, grid shape, ticks and radial endpoints. Values clip
to the shared range using normalized arithmetic; flat ranges map to the center.
Complete finite non-negative decimals are required. Integer tick counts clamp
to 32. Duplicate/unknown axis ids and incomplete curves produce errors; upstream
can silently skip curves with a different number of ordered values. Native
graphics use the theme's `cScale` colors, point markers, polygon lines or sampled closed
splines for circular grids. As pinned, curves fill at `radar.curveOpacity` and each
graticule ring at `radar.graticuleOpacity` beneath their outlines, a theme without a
`radar` object taking the default theme's values. Exact SVG geometry is a non-goal. Axis
captions wrap and shorten at 256 columns, while semantic summaries keep full
labels and values. Graphic legend visibility does not hide the cell data legend.
Limits are 256 axes, 4,096 points/curves and 65,536 bytes per declaration.

Cynefin accepts `cynefin-beta` with an optional colon, the five named domains,
quoted/multiline items, escaped quotes, metadata and domain transitions with
optional quoted labels. Domain blocks replace earlier contents; self-loops are
dropped. Metadata and transitions close the current block. Native graphics keep
all items visible by expanding the four quadrants and central Confusion ellipse;
upstream's fixed layout caps Confusion at three items plus an overflow badge.
Headers and items remain separately selectable. Exterior transition lanes carry
arrows to the target headers and keep captions below domain contents. As pinned,
the quadrants meet in one field at 40% of their theme colors, the confusion ellipse
is dashed at 50%, and the folds are the pinned dashed paths: seven cubic wobbles
each, drawn from the pinned `seededRandom` (checked number for number against the
browser) with `cynefin.seed`, or the hash of the CLI's `my-svg` id when it is zero,
and `cynefin.boundaryAmplitude`, scaled from the pinned 800-pixel field. The red
cliff runs down the lower fold along the pinned `generateCliffPath`. Domain labels
are bold and descriptions italic, in the pinned wording. Limits count all
authored declarations, including replaced items and dropped self-loops: 4,096
items, domain blocks and transitions each, and 65,536 bytes per declaration.

Requirement diagrams accept `requirementDiagram`, the six requirement types and
`element`, their standard fields, all seven relationship kinds, both arrow
spellings, and TB/BT/LR/RL directions. Names and field values may be double quoted,
including multiline values. The first definition of a name and first value of a
repeated field win. Relationships may precede their endpoints; missing endpoints
produce a located error. Each authored declaration, relationship and style directive
is bounded to 4,096 entries; declarations also have the shared 65,536-byte limit.

Native graphics use ranked rectangular cards drawn as pinned `requirementBox` draws them:
`<<Functional Requirement>>`-style type names and the bold name centered over a divider,
then `ID`, `Text`, `Risk` and `Verification` (`Type` and `Doc Ref` for elements) left-aligned
in that order, risk and verification capitalized. Non-containment relationships are
dashed with an open arrowhead; `contains` is solid with a circled plus at the container.
All kinds keep their labels. `RequirementCardsTest` checks the cards and markers; upstream
layout geometry is a non-goal. Metadata after the header
appears in the inline source-linked legend. `title` and compact bodies are native
extensions. `style`, `classDef`, `class` and `:::` support fill, stroke, text color,
stroke width (0–1,000 pixels) and dash presence. Forward styles resolve after parsing;
inline styles override classes and the default class. Style directives target word identifiers (including Unicode, dots and hyphens); quoted names can receive inline declaration classes. Unsupported
CSS is rejected. ER and requirement styles share a budget of 65,536 authored
node/class pairs per diagram, including repeated assignments. Dash lengths and transparency follow the shared CSS rules.

Info diagrams accept the case-sensitive `info` header and optional `showInfo`
keyword, on the same or a following line, followed by title and accessibility
metadata. Repeated `showInfo`, keywords after metadata, and unrecognized content
are rejected with source locations. Comments, multiline accessibility descriptions
and the shared source/statement limits apply. As in the pinned upstream renderer,
metadata values do not alter the graphic. Native output identifies Porta and its
Mermaid 12.1.0 compatibility target; upstream instead prints its installed
MermaidJS runtime version. Front matter and directives apply as for every family; browser typography is a non-goal.

Entity relationship diagrams accept `erDiagram`, bare or quoted entity names,
first nonempty aliases, repeated attribute blocks and relationships that implicitly
create their endpoints. Attributes preserve authored order across blocks. Types may
use nullable suffixes, arrays, generic tildes, numeric parameters or backtick words;
attributes accept PK/FK/UK lists and literal quoted comments, including braces and
multiline text. Comments outside quoted values are skipped. Symbolic cardinalities,
the pinned grammar's word/numeric alternatives and MD-parent `u` retain their
meaning. Identifying `--`/`to` links are solid; `..`, `.-`, `-.` and `optionally to`
links are dashed. TB/BT/LR/RL directions and cycles are supported.

Native cards retain alias-independent identity. As pinned `erBox` draws them, the
wrapped header sits over one row per attribute, its type, name, keys and comment
each left-aligned in a ruled column as wide as its widest cell; a column no
attribute fills is left out, and a comment's line breaks run together as a
browser's table cell runs them. The legend keeps each attribute as prose. Graphics draw mandatory bars, optional
circles, crow's-foot branches and filled MD-parent diamonds at both endpoints;
glyphs follow horizontal, vertical, reversed and diagonal segments, scaling down
on short segments. These are decorative primitives, never extra selectable entities.
Numeric left/right cardinality captions remain available for terminal legibility.
Self-relations route outside and below their cards, with captions beneath the
loop. Captions contribute to export bounds; rank spacing reserves room for endpoint
glyphs and long vertical captions. Metadata and individual attributes remain in
the inline source-linked legend.

ER node CSS supports `style`, `classDef`, `class` and `:::` assignments after
entity names/aliases or on either relationship endpoint. Definitions and assignments
may precede entities; native resolution occurs after parsing, while pinned upstream
assignments to missing entities are order-sensitive. Assigned classes override the
default class in assignment order; inline node styles override classes. Repeated
properties merge with their latest value. Simple ID/class lists allow Unicode,
dots, hyphens and comma whitespace; quoted entity names can use inline classes.
The supported properties are fill, stroke, text color, stroke width (0–1,000 pixels),
authored dash lengths and transparency (see the shared CSS rules). `stroke:none` hides outlines and dividers in both SVG and PNG.
Unsupported CSS reports a located error instead of being ignored. Styles do not
change identity, source origins, selection or relationship glyphs. Each diagram
allows 4,096 style directives and 65,536 authored node/class pairs, including
repeated assignments.

`subgraph ID[Label]` … `end` frames the entities declared or related inside it,
nesting and taking a `direction` as flowchart subgraphs do, and `style`/`class`
apply to the frame. Aliases are plain text, as pinned: a backtick string keeps its
backticks. Exact upstream ELK/Dagre geometry is a non-goal (see below); crowded or
parallel routes can differ from upstream. Limits are 4,096 entities, relationships,
authored entity declarations and attributes each, plus the shared 65,536-byte
statement limit (an attribute block is one statement).

`sequence.actorMargin` accepts integers from 0 to 1,000 in front matter and
leading initialization directives. Its default of 50 maps to the existing eight
native layout units (6.25 pixels per unit). It changes the shared column spacing
used by participants, messages, group frames, lifelines and mirrored footers.
Native text and activation bounds still impose minimum spacing; group frames
retain a two-unit header inset even with zero margin. This uses Porta's uniform
columns rather than upstream's per-actor spacing and does not implement its
separate note-placement margin rules. Values above 1,000 are rejected as outside the native range.

Sequence participant metadata supports single-line YAML flow maps and multiline
block maps inside `@{…}`, alongside the existing multiline JSON extension.
The supported fields are `type` and `alias`. Aliases use the pinned database's
false-value fallback: null, false, numeric zero, NaN and an empty string leave the
participant name visible. Quoted `"0"`, `!!str 0` and other strings remain visible
labels. A null type uses the declaration keyword. Actor keywords are retained in
the semantic type/legend, and native pictures draw each type's pinned glyph. Unlabeled
redeclarations preserve the earlier description and type; a new label permits
an explicit type or declaration default to replace them. A configured alias replaces
an absent `as` description or one whose raw text equals the participant ID; another
explicit description wins. Entity decoding follows that comparison, and explicit
wrap/nowrap prefixes apply to the selected alias. The same rules apply to creation.
Non-string aliases are validated as renderable labels only when selected: a
different explicit description can override numbers, booleans, arrays and maps,
including cyclic maps. YAML syntax, tags and references remain validated even
when that field is ignored. The alias-override tests add 119 cases for declarations,
creation, raw entity spelling, wrapping, retained types and errors. Eight supported
and two rejected published browser probes and eight assertion-killed mutations
confirm this precedence.
Plain, single/double-quoted,
literal and folded strings retain entity decoding, grouping, creation and source
navigation. YAML comments and quoted or
indented scalar content do not terminate a declaration. Native previews use the
same parser for standalone cells, graphics and Markdown fences.

The YAML parser is Symfony Yaml, with object/constant/custom tag handling disabled
and declaration-local anchors/references supported. Up to 128 collection references
are accepted per declaration, including self-references, mutual references and
root cycles in ignored metadata. Collection references are projected without
walking or serializing their graphs. Original definitions remain for syntax,
tag and duplicate-key validation, and scalar anchors inside cycles remain usable.
Input retains the 65,536-byte statement and 128-level nesting limits. Unknown fields
are ignored, as in the pinned database. Duplicate YAML keys and collections/non-string
values in alias/type outside the fallback cases produce
located errors. Merge spellings (`<<`) remain ordinary ignored keys, including
nested maps and anchored collections, rather than importing alias/type fields.
Literal merge spellings in labels, block scalars and scalar references retain
exactly their authored text. Collision checks include hexadecimal/Unicode escapes
and double-quoted line continuations. Plain dates and timestamps retain their exact
text, including fractional seconds, timezone spelling and invalid calendar dates,
as in upstream's `js-yaml` JSON schema. A bounded lexical pass forces these
plain values to strings before Symfony date resolution; quoted text, block
scalars, comments and explicit tags retain their contents. Quote numbers and
booleans to retain them as labels. Primitive resolution follows the pinned JSON
schema rather than Symfony's implicit scalar resolver: zero spellings such as
`00`, `0b0` and `0_0` omit an alias, while `-.0`, mixed-case boolean/null lookalikes
and other strings retain their exact text. Integer bases, underscores, floating
point overflow/underflow and special values use JavaScript double precision.
Typed quoted/literal/folded values are checked against their explicit tag before
normalization. Plain continuations, including blank-line folding and comments
before a value, retain their resolved string values. Trailing garbage, invalid
plain indicators, decorated alias references and invalid tag values are located
errors. Explicit tags are restricted to the pinned JSON
schema before Symfony parsing, so `!!binary`, `!!timestamp`, foreign URI tags and
custom tags cannot introduce strings that upstream would reject. `!!str`, the
non-specific `!`, full `tag:yaml.org,2002:str` spellings and percent-encoded tag
names preserve string values. A single tag and scalar anchor work in either
order. Quoted/block scalars keep punctuation and literal tag spellings; known
string keys can also be tagged. Duplicate headers and short tags without their
required separation produce located errors. Declaration-local `%YAML` and `%TAG`
directives require a document start marker. Tag handles resolve only to supported
JSON-schema types, including primary/secondary handle overrides and percent-encoded
prefixes/suffixes. Duplicate/malformed directives and undeclared handles are
located errors. As upstream, unknown directives and unsupported minor versions
have no visual effect; they retain JSON-schema scalar resolution. Literal directive
spellings in comments, quotes and block scalars remain text.

`sequence-yaml-tags-reference.json` records 51 cases evaluated with the pinned
database's wrapping rules and `js-yaml` 4.1.0 JSON_SCHEMA. A separate 325-case
tag/anchor/value differential probe had no mismatches. Tests cover source
origins and native SVG/PNG exports, and eighteen targeted mutations fail on
assertions. The current primitive/schema fast path measured 0.061 ms for a 60,000-byte plain
value and 0.631 ms for a similarly sized colon-heavy value (fifteen-run medians).
These measure the prepass, not the complete parser; scaling probes remained
linear. `sequence-scalar-fallbacks.json` adds 82 primary scalar/declaration cases,
and `yaml-primitives.json` adds 80 direct primitive resolver cases. Browser probes
also confirmed false-value aliases and null types. Twenty-five further targeted
mutations fail on assertions. Document/tag directives and bounded collection references are now supported.
YAML features with no diagram meaning, such as literal complex keys, follow Symfony YAML
rather than js-yaml (see Non-goals). `sequence-yaml-directives-reference.json`
adds 47 primary cases; `sequence-yaml-collections-reference.json` covers ignored
metadata, literal merge keys, collection references, scalar anchors in list values
and collision spellings. Eighteen targeted collection mutations fail on assertions.
`sequence-yaml-cyclic-reference.json` adds 38 primary cases covering cyclic graphs,
scalar anchors, node-property indentation, explicit collection tags and literal
reference spellings. Explicit `!!seq`/`!!map` validate their node kinds and construct
empty collections correctly. Duplicate anchor headers are rejected before reference
look-ahead. Eighteen targeted cyclic/tag mutations fail on
assertions; browser probes confirm labels on self-references and scalar anchors
inside cycles.

Scalar alias keys resolve at their point of use in block/flow maps, including
explicit keys, empty values, anchor redefinitions and implicit mapping pairs in
flow lists. Scalar coercion follows JavaScript, including numeric formatting;
map alias keys become the fixed string "[object Object]". Duplicate resolved
keys remain errors. Expanded key text is capped at 256 KiB, and collection keys
count toward the 128-reference limit. Escaped UTF-16 pairs retain their Unicode
identity; unpaired surrogate alias keys receive a located error and remain a
compatibility limitation. Displayed unpaired units use the replacement character.
The sequence-yaml-keys-reference.json fixture adds 64 primary cases and
yaml-key-primitives.json adds 96 scalar-key cases. A separate 9,996-value
numeric differential had no mismatches. Published browser probes cover five
key forms, and seventeen targeted key/Unicode mutations fail on assertions.

Plain flow keys preserve unseparated colons: an unquoted alias:Name is a literal
key with an empty value, whereas a quoted alias key sets the label. Scalar
mapping pairs in flow lists receive their own mapping scopes. Duplicate keys
are rejected even if the first value is null, including the JSON fast path,
escaped key spellings, numeric coercion and nested maps. Quoted UTF-16 pairs
retain key identity. The sequence-yaml-flow-keys-reference.json fixture adds
75 primary cases; fifteen further targeted mutations fail on assertions.
Six published browser probes confirm plain-key, quoted-key, URI-key and
list-pair behavior. Native parsing also accepts literal closing braces inside
quoted keys/list values; published Mermaid rejects those examples in its
statement grammar before YAML decoding. Those cases are native extensions.
Documents without flow collections skip this pass. Fifteen-run medians for the
pass on 60,000-byte values were 0.003 ms for a block map and about 51 ms for flow
maps, including colon-heavy text.

Flat array alias keys join their scalar elements with commas, render map elements
as "[object Object]", and retain empty text for null elements. Nested arrays are
rejected as in the pinned loader. References inside arrays retain their original
bindings across later anchor redefinitions. Keys used inside a collection while
it is being constructed see only completed items; only completed definitions are
cached. Joined text is bounded before encoding as well as by the existing expanded
key limit. Empty list-item anchors remain null, and compact keys immediately after
a list indicator resolve as keys. The sequence-yaml-array-keys-reference.json
fixture adds 51 primary cases. Five published browser probes confirm labels and
fallbacks; eighteen further targeted mutations fail on assertions.

Literal flow collection keys and anchored/tagged scalar keys retain their original
node once as a hidden value, then use the same bounded key conversion as authored
references. Generated references do not consume the author's 128-reference limit;
authored references to the same anchor still do. Collision-free symbols account
for escaped marker text. List mapping pairs retain their original item count.
The 49-case complex-flow fixture and 166-case property fixture cover tags, comments,
document markers, parent indentation, tabs and compact list mappings. Flow-line
adaptation preserves physical line breaks and caps added indentation at 256 KiB.
On the opening metadata line, a closing brace terminates metadata before its
YAML comment is decoded, matching the published grammar. Comment braces on
subsequent metadata lines remain shielded.
Five supported published browser probes, native PNG inspection, nineteen assertion-killed
mutations and 32 IDE cases cover this increment.

Literal block array/map keys and explicit scalar keys use the same bounded
coercion, including properties on separate lines, cyclic map values, block scalars,
compact list mappings and empty non-specific tags. Property headers move with
their authored node; generated references retain the authored reference budget.
Block roots are validated before late dedenting, so invalid sibling indentation
and flow-property indentation are not repaired. Plain keys starting with angle
brackets or parentheses retain their true parent and flow-key spelling.

The block-key fixture has 94 cases and the root-indentation fixture has 115.
A further 732 generated combinations match the pinned loader without differences.
Three published block examples, native PNG review, 28 assertion-killed mutations
and 33 IDE cases cover this increment. Explicit block-key extents are indexed in
one pass; native block-key nesting is limited to 128 and synthesized indentation
to 256 KiB before allocation. The 128-level local probe reduced the block pass
from about 1.4 seconds to about 0.1 seconds. The checked corpora define the supported metadata;
other js-yaml edge cases are a non-goal.

Block plain keys use the pinned JSON-schema primitive coercion, including null,
booleans, numeric spellings and punctuation. Quoted keys retain their string
identity. Later implicit tag/anchor keys preserve their node properties and
physical separator spacing; first-node properties retain their root/value role.
The primitive-key fixture has 313 cases, including 274 generated combinations.
The earlier 732-case block matrix still matches the pinned loader. Multiline root
nulls, scalars and arrays use the default participant label; the root-value fixture
covers 32 valid and invalid documents. Three published key examples and three
root-value examples confirm rendering. Ten targeted mutations fail assertions;
native PNG inspection and 35 IDE cases cover rendering and unsaved exports.
Lone UTF-16 surrogate keys retain their original JavaScript code units through
YAML quoting, including scalar/array references and equivalent escape spellings.
They stay distinct from replacement characters and other surrogate units; valid
pairs still resolve to their supplementary character. The surrogate fixture has
70 cases, and 6,144 generated comparisons cover all 2,048 surrogate units without
differences. Three published browser examples, eight assertion-killed mutations and 36 IDE
cases cover this increment. Raw malformed UTF-8 is rejected with a located
metadata error before Symfony decoding. Other js-yaml edge cases are a non-goal.

Root `logLevel` accepts the documented integer levels 0–5 and names `trace`,
`debug`, `info`, `warn`, `error`, `fatal` in front matter and leading directives.
Native rendering has no browser console, so this option leaves the scene unchanged
and applies to every family. Invalid values receive a located configuration error.

Leading YAML front matter supports a string `title` for all native families.
The opener and closer must be `---` lines at the same indentation; LF and CRLF
are accepted. An indented `---` inside a block scalar remains title text. The
header is masked byte-for-byte before family detection, keeping original byte
offsets and source lines intact. Titles wrap cooperatively above native graphics
and cells and also appear in the source-linked inline legend; the caption is
excluded from selection and hit testing. Existing nodes, routes, arrow tips,
filled ribbons, labels and cluster frames move together. Body `title`
declarations take precedence; Info continues to ignore title metadata.

Front matter is bounded to 65,536 bytes, within the shared source-size limit.
Malformed YAML, references, non-mapping roots and non-string titles produce a
located error. Empty or null titles omit the caption. Unknown metadata keys are
ignored, matching upstream. `displayMode: compact` sets gantt's compact mode. Supported `config`
options and leading initialization directives are described below. A leading byte-order
mark and LF, CRLF or CR line endings read as pinned; full js-yaml schema equivalence
is a non-goal.

`MermaidFrontMatterTest` exercises titles across eighteen native families, Info's
metadata behavior, title precedence, indented fences, Unicode/CRLF source offsets,
primitive translation, bounds and error handling. Standalone viewer tests check
source origins, non-selectable captions and retention after invalid edits.

Native configuration supports `sequence.showSequenceNumbers` and
`sequence.mirrorActors`, `sequence.hideUnusedParticipants` and `sequence.wrap`
as booleans, root `wrap` as a boolean for sequence diagrams, and
`pie.highlightSlice` as a string label. Configuration can come from the front
matter `config` map or leading `init` / `initialize` directives. Directive maps
accept JSON or YAML flow syntax, including single quotes and multiline objects.
Front matter is merged first, then directives in authored order, matching the
pinned preprocessor. Missing fields and empty maps preserve previous options;
explicit `false` and empty strings remain overrides. A configuration option must
belong to the document's family. Other fields, non-mapping sections, invalid
scalar types, references and custom tags produce located errors.

Sequence numbering uses the configured value OR authored autonumber visibility,
matching the pinned renderer: `autonumber off` cannot hide numbers when the
configuration enables them. Message-only counters, explicit starts/increments and
source origins retain their existing behavior. Explicit `mirrorActors: true`
repeats surviving participant headers below the timeline, including participants
created by messages, with declaration navigation and space reserved before menus.
Mirroring is on by default, as pinned, and `mirrorActors: false` turns it off. A
destroyed participant gets its footer where it dies, centered on the destroying
message, instead of a mirrored one; a created participant's header centers on the
creating message too, and both messages stop at the header's side. A group box
encloses the mirrored footers.

Native pictures draw the pinned participant glyphs, scaled from the 65px actor box:
the stick actor, boundary, control, entity and database stand above their label,
`collections` stacks its box and `queue` lies as a horizontal cylinder. Lifelines are
solid and message text stands on its arrow without a box. Every endpoint takes its
pinned marker: the filled arrowhead (`->>`, `<<->>`), the cross (`-x`), the open
arrow's chevron (`-)`), solid and stick half-arrows on the pinned side of the line,
reversed forms included, and the central-connection dots. Terminal cells keep their
boxes and arrows. `SequenceGlyphsTest` and `SequenceTest` check the shapes, the
marker sides and the lifecycle geometry.

`hideUnusedParticipants: true` projects participants referenced by sequence events
(messages, notes, activation and lifecycle events), retaining declaration order.
Unused columns, headers, lifelines, menu panels and participant legend entries
are omitted. Group members are filtered and empty groups disappear; surviving
groups retain their source links. This also applies to mirrored footers. The
parsed model and chronology are preserved, and projection yields between events
and group members so cancellation remains cooperative. Missing or false retains
all declarations.

Sequence `wrap:` / `nowrap:` prefixes (also `:wrap:` / `:nowrap:`) override the
`sequence.wrap` default before entity decoding. A root `wrap` setting takes
precedence over `sequence.wrap`, including explicit `false`; inline prefixes
still take precedence over both. Root and family options merge independently
across front matter and leading directives. Wrapped native labels use 60
terminal display columns, retain authored line breaks and blank lines, and split
long words without cutting Unicode graphemes. This covers participant aliases,
messages, notes, control labels and group titles; configured aliases are decoded
only once. Mirrored headers share the resulting multiline geometry, and source
origins remain the authored declarations/events. Exact upstream pixel widths,
`sequence.width`, wrap padding and browser text measurement are geometry non-goals. Pie highlighting matches the
decoded slice label exactly, expands its sector by 5%, uses full opacity and a `*` marker, and
preserves slice order, numeric values, zero slices and source selection. `hover`
is browser interaction, a non-goal. Native pies draw ordered circular sectors and donuts, with
shared SVG/PNG/hit-test polygons. `pie.donutHole` falls back to zero outside
`(0, 0.9]`, `textPosition` accepts `0..1`, and `legendPosition` supports top,
bottom, left, right and center. Zero overlays survive directive merging. Slices
below 1% retain their searchable legends while visible sectors are renormalized
to fill the circle, matching the pinned renderer. Native cells retain readable
labels.

### Themes

Every family paints from the pinned theme model. `theme` accepts all eleven registered
themes (`default`, `base`, `dark`, `forest`, `neutral`, `neo`, `neo-dark`, `redux`,
`redux-dark`, `redux-color`, `redux-dark-color`), and `themeVariables` overrides any
variable. A port of khroma 2.1.0 and of the pinned theme classes derives the dependent
colors exactly as `getThemeVariables()` does, including JavaScript number formatting and
values a browser rejects (a derived `hsl(…, NaN%)` falls through as an invalid declaration
does). Front matter and `init` directives merge, sanitize and resolve as the pinned
`updateCurrentConfig` does: a family section's `theme`, `look` and `layout` win over the
root's, the 12.1 defaults give flowchart, sequence, class, state, ER, requirement, use case,
Venn, swimlane and agentflow `redux-color` with the `neo` look, and variables are re-derived
only when a theme was chosen. A section holding only appearance keys, such as
`venn: {theme: default}`, needs no CLI.

Layouts name colors as theme roles and the picture resolves them once: family variables
(`git0`–`git7`, `cScale*`, `pie*`, `quadrant*`, `xyChart`, `radar`, `packet`, `cynefin`,
`treeView`, `wardley`, `em*` and the rest), palette slots of the color themes in declaration
order, element opacities and, where the pinned styles merge the default theme under the
current one, that fallback too. The neo look strokes outlines with `nodeBorder` and casts
the pinned drop shadows; graph families take the theme `strokeWidth`. Fixed pinned literals
stay literal: the C4 role palette, Sankey's Tableau10 nodes and 50% links, architecture's
blue icon boxes, Wardley's stage dividers and sourcing overlays. Terminal cells draw the
opaque color of an alpha paint.

`census-reference.json` records the computed paint of 33 families under fourteen theme
variants from the pinned build; `MermaidFamilyPaintTest` checks 224 native element roles
against it, channels within one unit for the browser's float HSL conversion. Gradient neo
strokes are approximated by the node border, and the state composite title band is not
drawn.

### Hand-drawn look

`look: handDrawn` sketches the fourteen families whose pinned renderers draw with roughjs:
flowchart, class, state, ER, requirement, C4, mindmap, block, kanban, Venn, Ishikawa, use
case, swimlane and agentflow. The other families ignore the look, as they do upstream. A
port of roughjs 4.6.6 (MIT, `resources/licenses/roughjs.txt`) draws with the options the
pinned `userNodeOverrides` passes. Each outline segment becomes two bowed cubic strokes,
1.3px wide at roughness 0.7, and fills become 1.5px hachure lines at -41°, two pixels apart,
in the fill color, with no solid fill beneath. Frames hatch 3px wide, and edges are rough at
roughness 0.3 in their own color and width. Text, arrow tips and label boxes stay crisp.
Every shape restarts the seeded generator, so a `handDrawnSeed` draws the same picture each
time. The pinned default seed of zero draws differently on every browser render; native
rendering stays reproducible and draws it as seed 1. A seed's low 32 bits count, as
`Math.imul` reads them.

`rough-reference.json` holds the generator's output for seven polygons and three paths
under four seeds, from the build's own roughjs chunk (`rough-oracle.mjs`); `MermaidRoughTest`
matches it within 1e-9. The roughness applies to the native layout's geometry: outlines
follow native shapes, and an edge is sketched along the polyline its route draws. Upstream
sketches the curve itself. Terminal cells keep the classic look, because a sketch does not
survive a cell grid.

### Configuration

Every option the pinned build defines is accepted, per family and at the root, and checked
against the pinned schema (`resources/mermaid-options.json`, generated by
`tests/fixtures/mermaid/themes/options-schema.py` from the 12.1.0 config interfaces and
`defaultConfig`). As pinned, an unknown key is ignored and numeric text counts as a number;
a value of the wrong kind is a located error. Front
matter and `init` directives merge as the pinned preprocessor merges them, and native
layouts read the result, falling back to the pinned defaults.

Options that change the native picture:

- flowchart: `curve`, spacing, padding and subgraph title margins.
- sequence: `showSequenceNumbers`, `mirrorActors`, `hideUnusedParticipants`, `wrap`,
  `actorMargin`, `messageAlign`, and `rightAngles` (a self message is otherwise the pinned
  cubic loop).
- gantt: `axisFormat`, `tickInterval`, `topAxis` (source directives win), `displayMode:
  compact` (also from front matter `displayMode`), using pinned row packing, and
  `numberSectionStyles`.
- class: `hideEmptyMembersBox`, and `hierarchicalNamespaces: false` (only declared namespaces
  under their full names, unnested).
- xyChart: `chartOrientation` (the header keyword wins), `showTitle`, `showLegend`,
  `showDataLabel`, `showDataLabelOutsideBar`, and the axis `showLabel`, `showTitle` and
  `showAxisLine`.
- quadrantChart: `xAxisPosition` (empty charts only, as pinned), `yAxisPosition` and `pointRadius`.
- sankey: `nodeAlignment` (d3-sankey left, right, center, justify), `showValues`, `prefix`,
  `suffix`, `labelStyle`, `nodeColors` and `linkColor` (a gradient blends source into target; cells keep the source color).
- treemap: `showValues` and `valueFormat`, through a d3-format port checked against the
  copy bundled in the pinned build (`fixtures/mermaid/options/d3-format-reference.json`),
  including the pinned `$` handling.
- timeline: `disableMulticolor`.
- kanban: `ticketBaseUrl`; the link appears in the card's legend entry.
- cynefin: `showDomainDescriptions`.
- radar: `axisScaleFactor`, `axisLabelFactor` and `curveTension`.
- C4: every `<type>_bg_color` and `<type>_border_color`.
- railroad: the terminal, non-terminal, special, line, marker and rule-name colors.
- usecase: `colorScheme: rotate`, plus the existing font and spacing options.
- pie, packet, gitGraph and architecture: the options listed in their sections.

Accepted options with no native effect fall into four groups:

- Options the pinned renderers never read, or read without effect, as each was checked by
  rendering it changed in the pinned build: ER `layoutDirection`, `minEntityHeight`, `fill` and
  `stroke`; requirement `rect_*` and `line_height`; C4 `c4ShapeInRow` and `c4BoundaryInRow`,
  which only `UpdateLayoutConfig` sets; gantt `weekday`, which the db default always shadows;
  railroad `compactMode` and `showMarkers`; journey and timeline `actorColours`,
  `sectionColours`, `sectionFills` and `textPlacement`, captured before configuration
  applies; journey `faceColor`, which theme variables cannot set; sequence `actorFontSize`;
  class and state pixel factors such as `dividerMargin`, `forkWidth` and `sizeUnit`.
- Browser concerns: `useMaxWidth`, `useWidth`, `htmlLabels`, `securityLevel`,
  `deterministicIds`, `forceMenus`, Venn `useDebugLayout`, and the secure `maxTextSize` and
  `maxEdges`, which documents cannot set and which native rendering replaces with its own
  documented bounds.
- Pixel geometry of the browser layouts: margins, paddings, widths, heights, chart sizes,
  font families, and layout-algorithm tuning such as the swimlane, ELK and fcose options and
  mindmap `layoutAlgorithm`. Native geometry is laid out for terminal cells; see the non-goals.

Text sizes and weights that change what a picture says are native: C4 `<type>`, boundary
and message `FontSize`/`FontWeight` (the name bold, as pinned); sequence message and note
sizes and weights, actor weight and `noteAlign`; ER, requirement and state `fontSize`, under
any authored class; gantt `fontSize` and `sectionFontSize`; quadrant title, label, axis and
point sizes; journey `titleFontSize` and `taskFontSize`. Each scales from its pinned default,
which draws as native text's own size. Quadrant border widths and tree view `rowIndent` and
`lineThickness` are native too. `MermaidFamilyOptionsTest`, `C4Test`, `QuadrantTest`,
`GanttTest`, `JourneyTest` and `TreeViewTest` check them.

Leading directives may follow front matter, whitespace and ordinary comments.
Quoted `}%%` text does not close a directive. Their spans are masked byte-for-byte,
retaining CRLF/Unicode source origins. Scanning assembles the result from spans
and advances positions over disjoint segments. Each directive yields a cancellable
work step before family parsing, splitting long prefixes across request slices. Limits are 4,096 directives,
65,536 bytes per directive and 128 nesting levels. As pinned `detectInit` finds
them, directives apply wherever they stand, after the header, mid-line or inside a
quoted label, and each is blanked in place; `%%{wrap}%%` wraps every label. A
leading byte-order mark reads as leading space, as a browser drops it, so front
matter after one is rejected in both. Native configuration is local to one
rendering request.

`MermaidInitializationTest` covers merging, numbering, highlighting, escaping,
source locations, bounds, cancellation/replacement and SVG/PNG output. Viewer tests cover configured pie
selection and retained scenes after invalid configuration; Markdown fallback
tests include a configured pie.

Native `venn-beta` supports sets, explicitly sized overlaps, labels, indented and
explicit text nodes, and exact-target styles. The eighteen pinned bodies are
checked against the executable Jison parser/database. Circles preserve area
ratios; deterministic pair-distance fitting approximates multi-set geometry.
Text uses exclusive-region clearance with outside callouts for impossible regions.
Authored overlap fills are clipped to the contributing circles; SVG and PNG
preserve alpha. Captions and text retain source selection/search, while decorative
circles and clipped fills are excluded from navigation. Bounds are sixteen circles,
4,096 declarations and 8,192 tokens per declaration. Other CSS
properties are rejected, as for every family (see Non-goals).

Native railroad diagrams support all four pinned headers: `railroad-beta`,
`railroad-ebnf-beta`, `railroad-abnf-beta` and `railroad-peg-beta`. The constructor
notation, EBNF postfix/ISO groups and exception transforms, ABNF numeric terminals
and bounded repetitions, and PEG ordered alternatives/lookahead match the pinned
parser transformations. Lookahead is descriptive, as in upstream's railroad
renderer; grammars are never executed. Rule headings and operands are selectable;
tracks, loop arrows and markers are decorative. Finite repetition counts are
shown as annotations rather than expanded into repeated boxes. Duplicate rule
declarations remain separate. Limits are 4,096 rules, 8,192 tokens per rule,
131,072 lexer steps, 128 syntactic/tree levels and repetition bounds of one million.
Exact upstream track geometry is a non-goal.

## Coverage scope

Each registered built-in family has a native parser and layout, and every pinned
upstream end-to-end diagram renders natively (see Acceptance corpus). Syntax,
configuration, styling and the pictures' content follow the pinned build within the
family limits above; what a terminal-native renderer leaves to the browser is listed
under Non-goals. Configuration reads as the pinned build reads it: a key the build does
not define is ignored, numeric text counts as its number, and an init directive's
`config` map is read as root options; a value of the wrong kind for a known option,
and a section for a different diagram family, remain located errors.

## Non-goals (served by the CLI path)

These are what the browser does that a terminal-native renderer does not attempt; a
document that needs them renders through the Mermaid CLI path instead.

- Browser-exact geometry: Dagre, ELK, fcose and cose-bilkent placement and routing, D3's
  sankey relaxation, pixel margins, paddings, widths, heights and chart sizes, the
  swimlane lane-ordering heuristics, and browser text measurement. Native geometry is laid
  out for terminal cells and drawn from it; pictures follow the pinned shapes, proportions,
  colors and typography, not its pixels.
- Math: KaTeX in labels.
- HTML labels and arbitrary CSS: `htmlLabels`, host-page CSS that class names only name,
  and CSS properties beyond the shared subset (colors, widths, dash lengths, opacities and
  font size, weight and style).
- Remote images and icon packs a page registers: native rendering never fetches; remote
  sources draw a placeholder and unregistered icons the pinned unknown-icon tile.
- Browser behaviour: callbacks, links, hover effects, `forceMenus`, edge animation and the
  contract validator's console warnings. Callbacks and links stay descriptive metadata,
  never executable content.
- Degenerate configuration whose pinned output is not a layout: packet rows wider than
  1,024 bits or fractional, and values outside each option's documented native range.
- js-yaml edge cases with no diagram meaning, such as literal complex keys; the checked
  YAML corpora define the supported metadata.
- Third-party registrations outside the pinned built-in registry, such as ZenUML.

## Acceptance corpus

CLI map acceptance includes seven browser-generated Mermaid 12.1.0 SVG fixtures
(flowchart, modern declarations, class, state, sequence, ER and use-case), with
browser-measured bounds. The fixture provenance distinguishes the published npm
build from the pinned source commit. Text bounds use conservative font estimates.
The actual installed CLI browser was also exercised and its PNG reviewed after a
converter-only probe exposed missing HTML labels. CLI orchestration covers same
snapshot/refinement, unsupported native syntax, bounded caching, source following,
private cleanup, cancellation and native cell switching. Thirty-five targeted
mutations fail on assertions.

`tests/fixtures/mermaid/swimlane/` contains 19 verbatim pinned swimlane examples.
`SwimlaneTest` renders each body after masking initialization/configuration and checks
LR/RL/TB/BT/TD, lane ownership, shared temporal extents, nested frames, whole-group
dependencies, empty lanes, styles, origins and SVG/PNG export. Loose nodes receive
an anonymous lane; alias collisions preserve authored nodes. Headers are compact
source targets, while relationships to a group use its complete frame. Nested and
node self-loops reserve space without enlarging visible cards. Root lanes keep
source order. Swimlanes read the flowchart options, as pinned; the lane-ordering and
crossing heuristics and browser-exact routing are non-goals. Syntax shares the native flowchart parser and its remaining gaps.

`tests/fixtures/mermaid/flowchart/` contains 261 verbatim pinned flowchart examples,
including Dagre and hand-drawn variants. `FlowchartCorpusTest` renders every body
to rows and SVG after masking front matter and initialization; it does not claim
configured, hand-drawn, icon/image, Markdown formatting or full shape equivalence.
Separate tests cover optional headers, root directions, compact/open/dotted links,
multiline continuations, invisible placement constraints, repeated subgraph merging,
source origins and first-closed declaration metadata. Extended links reserve extra
rank space; label openers do not contribute to their length. Circles, crosses,
reverse arrows and thick shafts preserve authored edge styles and semantic counts.

Native CSS recognizes all 148 CSS Color 4 names, background color aliases and
font sizes of 1–1,000 pixels. Font-size changes resize cards and headers and scale
SVG/PNG glyphs relative to the default 16 pixels. Terminal cell text keeps its
terminal font within the resized geometry. Font faces, sizes and caption colors
survive titles and geometry translations. Large captions grow the export canvas;
compound self-loop allocation includes styled caption dimensions. Shared source-row maps reject heights above 1,000,000 logical units before
allocating the full map. Other CSS properties,
browser layout and math are non-goals.

`tests/fixtures/mermaid/block/` contains 38 verbatim pinned block examples.
`BlockTest` renders their bodies after masking configuration, and separately checks
column spans, explicit spaces, nested width allocation, typed endpoints, source
origins, CSS/classes, PNG output and all 15 pinned arrow-direction combinations.
This does not claim configured-corpus equivalence. Each grid keeps its declaration
order; relationships do not change placement. Spans and explicit column/space
counts are limited to 256, nesting to 128, retained items to 4,096 and declarations
to 16,384. Generic compound placement and block grids reserve self-loop/caption
space without enlarging visible cards. Block `padding` and exact graphical
geometry are geometry non-goals.

`tests/fixtures/mermaid/c4/` contains 25 verbatim pinned C4 examples.
`C4Test` renders their bodies and checks named/empty arguments, declaration updates,
style precedence, dynamic numbering, reverse/bidirectional links, deployment frames,
nested grids, frame identity, self-loops, caption offsets, bounds and source locations.
Boundary links route against the entire frame while selection uses compact headers.
The declaration and argument budgets are 4,096; nesting is limited to 128 and row
settings to 64. C4 `accTitle` follows the upstream visible-title behavior. Unknown
relationship endpoints produce errors. As pinned, `$shape` takes person, queue or any
registered flowchart shape, an unknown name being a located error, and `$sprite`
reserves 48 pixels above the text without drawing anything there. Names are bold over
the role and description, at the configured per-type sizes and weights. Pinned
`$shadowing` is stored but never drawn, so it draws nothing here either. Exact browser
geometry is a non-goal (see below).

`tests/fixtures/mermaid/wardley/` contains all six pinned upstream Wardley examples.
`WardleyTest` checks their rows and SVG, plus PNG, percentage normalization, quoted
names, forward pipeline references, component/anchor precedence, reverse/bidirectional
flows, custom stage widths, sourcing/inertia symbols, annotations, source origins,
hit regions and canvas bounds. Native coordinates retain the authored
`[visibility, evolution]` order; components accept fractional or percentage values.
Component label offsets and custom canvas dimensions are adapted to native units.
The declaration budget is 4,096, including pipeline bodies; individual statements
retain the shared 65,536-byte limit. Canvas dimensions are bounded to 100–10,000 pixels.
Browser-exact Wardley typography/geometry is a
non-goal. Positioned labels and notes can overlap when the authored map is dense.

`tests/fixtures/mermaid/git/` contains 105 verbatim pinned upstream Git examples.
`GitTest` renders 104 bodies with configuration removed; the custom-main-branch
fixture is also rendered with its original front matter in `GitConfigurationTest`.
Native configuration supports `mainBranchName`, `mainBranchOrder`, `showBranches`,
`showCommitLabel`, `parallelCommits` and `rotateCommitLabel`. As pinned
`drawCommitLabel` does, a commit is labelled with its id alone, a merge only when its id
was authored, a cherry-pick never; messages stay in the semantic label and legend.
Left-to-right pictures slant labels 45° by default, ending just below the commit, while
`rotateCommitLabel: false`, the other directions and cells keep them level. Tags fly as
flags stacked above the commit, the first highest, in `tagLabelBackground`/`Border`/`Color`
(the node colors on generated themes) with a hole in `textColor`. Arrows follow pinned
`drawArrow`: a branching child leaves along its parent's time, a merged parent runs its own
lane to the merge, each turning once with a rounded corner, and one that would cross a
commit on the turning lane detours through a lane midway. Arrows are 8px, 2px under redux;
branch tracks are dashed. `GitLabelsTest` and `RotatedTextTest` check these.
Generated commit ids use deterministic collision-safe native suffixes instead of
upstream random suffixes. Duplicate explicit commit ids replace earlier map values,
matching the pinned database; invalid merges and cherry-picks produce located errors.
Tags remain visible when commit labels are hidden. Both labels and branch names wrap
within the canvas. Commit glyphs distinguish normal, reverse, highlight, merge and
cherry-pick, including custom merge type overrides.

`tests/fixtures/mermaid/state/` contains 69 verbatim pinned upstream state examples.
`StateTest` renders their diagram bodies to rows and SVG after removing front matter
and initialization directives; this does not claim configured-corpus equivalence.
Separate tests cover PNG, source navigation, scoped pseudostates, concurrent regions,
composite loops, note identities, typography and resource limits. Exact browser geometry
is a non-goal.

`tests/fixtures/mermaid/class/` contains 43 verbatim unconfigured examples from
the pinned upstream `e2e/diagrams/class-diagram/` directory. `ClassTest` renders
them to native rows and SVG, and separately covers PNG, member origins, generic
identity, namespace ancestry/redeclaration, glyph bounds and resource limits.
Cards follow pinned `classBox`: the first annotation in guillemets over the bold name, then
members left-aligned as pinned `ClassMember` displays them, static members underlined and
abstract ones in italics; an authored font style sets the whole card. `members-reference.json`
holds that display for 23 members, computed by the pinned functions themselves
(`members-oracle.mjs`). Notes draw as note boxes, dotted to their class, after the classes so
these keep their color slots. UML and ER end glyphs size and aim along the routed line, so
curved edges keep full-size glyphs. `ClassCardsTest` checks these. The configured upstream
corpus renders with the rest of the pinned corpus (see Acceptance corpus).

`tests/fixtures/mermaid/timeline/` contains seven verbatim upstream `.mmd`
examples from the pinned commit's `e2e/diagrams/timeline/`: examples 1, 2, 3,
11, 12, 13 and 14. Their original names are preserved. The MIT license is in
`tests/fixtures/mermaid/LICENSE`.

`tests/fixtures/mermaid/mindmap/` contains the pinned upstream
`simple-mindmap.mmd` and
`formatted-label-with-linebreak-and-a-wrapping-label-and-emojis.mmd` examples.
`MindmapTest` checks those sources without a CLI, plus hierarchy, repeated labels,
shapes, multiline source positions, cancellation, limits and SVG export.

`tests/fixtures/mermaid/quadrant/` contains nine verbatim upstream examples
from `e2e/diagrams/quadrant-chart/` without initialization directives, including
both point-style examples. `QuadrantTest` checks coordinates, metadata, classes,
style precedence, source locations, limits, cancellation and SVG/PNG exports;
viewer interaction tests cover selection/search and source following.

`tests/fixtures/mermaid/xy/` contains fourteen verbatim upstream XY chart
examples without initialization directives or front matter. `XyTest` covers these,
numeric extremes, clipping, bar baselines, source locations, cancellation,
selection over bars and SVG/PNG exports.

`tests/fixtures/mermaid/packet/` contains four verbatim upstream packet examples
without front matter. `PacketTest` checks these sources without the CLI, plus
count/range equivalence, word boundaries, source positions, malformed input,
resource limits, cancellation, hit regions and SVG/PNG output. Viewer interaction
tests cover fragment selection, search and source following.

`tests/fixtures/mermaid/kanban/` contains all eleven pinned upstream Kanban
examples verbatim. `KanbanTest` checks these without the CLI, plus hierarchy,
quoted and multiline labels, flat metadata, source locations, repeated ids,
column alignment, resource limits, cancellation and SVG/PNG exports. Shared
mindmap label tests also guard the node syntax used by both families.

`tests/fixtures/mermaid/sankey/` contains the pinned upstream simple `.mmd`
example and verbatim parser `energy.csv` corpus. The latter retains its comments,
blank lines, spaces and doubled quotes. `SankeyTest` checks native rendering,
CSV/source locations, proportional and packed bands, duplicate flows, zero and
extreme values, cycles, limits, cancellation and SVG/PNG output. Viewer tests
cover node selection, search and source following.

`tests/fixtures/mermaid/treemap/` contains 20 verbatim upstream `.mmd` sources
without front matter or initialization directives. `TreemapTest` checks parsing,
source locations, proportional areas and descendant sums, class merging, zero and
extreme values, clipped labels, selection, limits, cancellation and SVG/PNG export.
These fixtures exercise source syntax, including class colors, rather than upstream
theme configuration.

`tests/fixtures/mermaid/tree-view/` contains ten verbatim pinned upstream `.mmd`
examples without configuration. `TreeViewTest` covers these, annotations,
indentation and branch hierarchies, original source lines, repeated labels,
multiline/Unicode text, row and connector geometry, limits, cancellation and
SVG/PNG exports. Viewer interaction tests cover selection, search and source
following. Configuration and icon-pack rendering are not validated by this corpus.

`tests/fixtures/mermaid/ishikawa/` contains seven verbatim pinned upstream `.mmd`
examples without configuration, including root-only and deeply nested sources.
`IshikawaTest` checks effect-independent cause indentation, source locations,
repeated/literal labels, alternating sides, separate cause pairs, nested bone
attachment, wrapping, bounds, cancellation and SVG/PNG exports. Viewer tests
exercise selection, search and source following. A nested native PNG was also
inspected visually; this corpus does not validate themes or exact upstream layout.

`tests/fixtures/mermaid/radar/` contains the two verbatim pinned upstream `.mmd`
examples without configuration. `RadarTest` covers labels, references and source
positions, options, grid and curve closure, polar coordinates, clipping, flat and
extreme ranges, limits, cancellation and SVG/PNG export. Viewer tests cover
selection, search and source following. A two-series native PNG was inspected
visually. This corpus does not validate configured colors or transparent fills.

`tests/fixtures/mermaid/cynefin/` contains seven verbatim pinned upstream `.mmd`
examples without configuration. `CynefinTest` checks source positions, metadata,
quoted labels, block replacement, self-loop filtering, expanding regions,
retained Confusion items, transition direction and bounds, limits, cancellation
and SVG/PNG export. Viewer tests cover selection, search and source following.
A native framework with transitions was inspected visually. Radar regression
tests also exercise the shared declaration scanner.

`TimelineTest` checks these examples without a CLI, plus malformed syntax,
source locations, resource limits, narrow rendering and cancellation.
`MermaidOutlineTest`, `MermaidDrawingTest` and `MermaidInteractionTest` cover
classic outlines, SVG/PNG output, allocation limits, scheduling and native
selection. Other native family tests remain in `tests/Mermaid/`.

`tests/fixtures/mermaid/upstream/` holds all 1,237 end-to-end diagrams of the pinned
upstream repository (`e2e/diagrams`, every family, configured ones included), verbatim
under the corpus MIT license, with their checksums in `upstream-provenance.json`; only
ZenUML (outside the registry) and KaTeX (math, a non-goal) are left out. `UpstreamCorpusTest`
renders each to SVG and to terminal rows; family tests check what the pictures show.

`tests/fixtures/mermaid/requirement/sample.mmd` is the verbatim pinned upstream
requirement sample, under the corpus MIT license. `RequirementTest` checks its
fields and reversed relationship, all requirement and relationship kinds, source
locations, forward styles, directions, limits, errors, terminal rows and SVG/PNG
exports. `MermaidInteractionTest` exercises card search, selection and source following.

`tests/fixtures/mermaid/info/` contains both verbatim pinned upstream info examples.
`InfoTest` checks their native rows, optional keyword ordering, metadata/comments,
located errors, statement limits and SVG/PNG exports. `MermaidInteractionTest`
checks diagnostic search, selection and source following.

`tests/fixtures/mermaid/er/` contains fifteen verbatim `mermaid-example` bodies from
the pinned `packages/mermaid/src/docs/syntax/entityRelationshipDiagram.md`, named
by their zero-based source-block index (1, 2, 4, 6–13 and 20–23). `ErTest` checks these examples,
cardinality aliases, identifying links, attributes/keys/comments, source locations,
directions/cycles, invalid input, limits, cancellation and SVG/PNG exports.
`MermaidInteractionTest` exercises entity search, selection and source following.

`ErMarkersTest` checks endpoint geometry, mirrored/rotated glyphs, optional-circle
paint order, filled MD-parent diamonds, short/degenerate segments and primitive
bounds. `ErTest` also checks wrapped compartments, self-loop bounds, caption/glyph
separation, long caption viewports, source selection and graphic exports.

The four additional ER styling examples cover direct styles, entity classes,
relationship endpoint classes and the default class. `ErTest` checks forward
resolution, precedence, styled borders, Unicode/quoted identities, budgets,
located CSS errors and exports. `MermaidNodeStylesTest` checks shared requirement/ER
style composition and preservation of node geometry and presentation. A raster
pixel regression verifies that `stroke:none` preserves fills while omitting strokes.

The per-stage records of how each family was brought up, verified and measured are in
[the compatibility history](mermaid-compatibility-history.md).
