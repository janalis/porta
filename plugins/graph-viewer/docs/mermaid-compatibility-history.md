# Mermaid compatibility history

How each native family stage was brought up and verified against the pinned Mermaid
12.1.0 build (commit `21f72f07`). The living reference is
[the compatibility checklist](mermaid-compatibility.md); these records keep the
evidence each stage was accepted on.

## Agent flow stage

The 19 pinned bodies in `tests/fixtures/mermaid/agentflow/` render natively.
Global declarations release nodes and flows from enclosing ownership; nested flow
children retain their direct container. Inline flow metadata applies after its
body closes. Collapsed flows hide descendants, redirect crossings to the outermost
summary and preserve genuine authored self-links. Expanded headers have compact
source-linked selection regions.

Metadata mappings merge shallowly, remove prototype keys recursively, preserve
quoted/block-scalar punctuation and retry multiline trailing commas without
removing content commas. Parsing rejects object tags and aliases and bounds map
items and nesting. The pinned contract validator's console warnings, exact
js-yaml scalar/alias equivalence and automatic ELK placement are non-goals. Named edge metadata is retained separately from
vertices; named edges take their CSS classes, as pinned; edge animation is browser CSS, a non-goal. Removed shapes
use the rounded task fallback; unknown shape names produce located errors.

## Gantt stage

The 43 verbatim pinned bodies in `tests/fixtures/mermaid/gantt/` render natively.
Twelve deterministic cases captured from the pinned upstream database check
fractional/calendar durations, month-end clamping, millisecond/second epochs,
implicit starts, inclusive dates, multiple dependencies and excluded-day completion.
Logical completion and drawn completion remain separate, so a dependent task can
start after an excluded-day gap. Includes override exclusions, and Friday/Saturday
weekends are supported.

Task bars and milestones share a proportional axis. Vertical markers consume no
ordinary task row. Section headings, task bars and markers retain source origins;
short horizontal bars take precedence over vertical markers in inline row maps.
Calendar resolution, exclusion shading and source-map assembly yield to the event
loop and can be abandoned before publishing a partial schedule. Task, date, tick,
calendar-iteration and scene bounds limit retained work; overly dense tick requests
fall back to automatic calendar-aligned ticks.

The today marker draws as pinned `.today` does, 2px in `todayLineColor`, and `todayMarker`
CSS styles it with commas for semicolons: `stroke`, `stroke-width` and a non-zero
`stroke-dasharray` apply, and a declaration a browser would drop leaves the default.
Compact mode and the gantt configuration are covered under Configuration. `dateFormat`
reads the dayjs `customParseFormat` tokens `YYYY` to `ZZ`, `SSS`, `X` and `x`; the rarer
`Do`, `S` and `SS` read as literal text. `axisFormat` covers every d3-time-format directive. Browser callbacks are descriptive
metadata. The ordinary native palette and terminal-adapted spacing are used.

## Use-case stage

All twenty pinned `e2e/platform/dev-diagrams/diagrams/use-case` bodies parse and
export natively. The reference fixture captures the pinned executable parser's
actors, use cases, groups, members, relations and notes. References can be refined
by later declarations; conflicting kinds, generated IDs, labels, shapes,
stereotypes, metadata and boundary ownership produce located errors.

Native glyphs cover normal, hollow, awesome and business actors, ellipse and
rectangular use cases, business slashes, package boundaries, association endpoints,
hollow generalization triangles, include/extend links and note connectors. JSON
cards preserve source property order, nested paths, scalar-array rows and
ECMAScript numeric display. Graphics use shared SVG/PNG primitives; cell previews
retain readable labels and semantic source selection.

`config.usecase` supports actor/use-case font sizes and weights, node/rank spacing
and diagram padding, with explicit zero surviving overlays. Spacing converts
upstream units to terminal scene units. Font-family requests use the bundled font
fallback; `useMaxWidth` remains governed by the preview/export caller's viewport.
Backtick labels on actors, use cases, notes and relationships draw their Markdown
runs, as pinned; exact upstream routing is a non-goal. Unregistered icons draw the pinned unknown-icon tile and keep the
authored icon name in the legend.

The parser, lexer, metadata, symbol, relationship, JSON and style limits have
focused regressions. Eighteen hand mutations were killed, including role checks,
resource limits, generated UTF-16 IDs, JSON exponents, overlay precedence, named
edge styles and marker stroke widths.

## Architecture stage

All eighteen pinned architecture bodies match semantic data captured with the
pinned Langium grammar and value converters. Full-document SVG exports include
the pinned `seed` and `randomize` configuration examples. IDs, declared-parent
ordering, duplicate names, endpoint roles, group traversal and alignment members
are validated before layout. Titles retain the grammar's quote escaping and raw
entity text; native drawing decodes entities safely.

The native constraint grid aligns opposing ports, applies authored row/column
hints and separates colliding peers. Groups are placed as opaque boxes, then
expanded into nested frames. Service and group-boundary ports feed orthogonal
routes with optional arrowheads at either end; self-loops use exterior paths.
Contradictory alignments relax collision placement to keep glyphs separate.

Built-in server, database, disk, internet and cloud glyphs, text icons, missing
icons and junction dots share native SVG/PNG primitives. Label masks keep links
from obscuring text. Selection targets service/junction bounds and compact group
headers, rather than the full group frame. Source data stays unchanged by layout.

Nonzero seeds produce reproducible native tie ordering; seed zero uses the
injected random generator. Explicit false and zero survive document overlays.
This grid is an adaptation to terminal cells, not the upstream fcose solver.
The fcose solver, its tuning options and obstacle-aware
routing are non-goals; other icons draw the unknown tile, as pinned. Twenty meaningful hand
mutations guard the stage; architecture's own focused tests cover every line.

## Event-modeling stage

Thirty-three pinned documentation, rendering and parser examples match semantic
data captured from the executable Langium grammar and event-modeling database.
The fixtures preserve time/reset frame IDs (including significant zero padding),
role aliases, qualified names, explicit sources, data blocks and references,
notes, and given/when/then clauses. Source provenance is recorded alongside them.

Inference follows the pinned database: an ordinary frame without explicit inputs
links from the nearest preceding frame in another lane. A reset frame suppresses
that inference. Explicit input references disable inference even when unresolved;
resolved inputs deduplicate in source declaration order, and forward frames do
not become available early. Qualified lane identities retain the pinned
source-order allocation. Native placement additionally keeps returning cards
apart so their text remains readable in terminal cells.

Native graphics show role colors, ordered lane bands, source-linked frame IDs,
data compartments and input arrows. Referenced data uses the first declaration
and overrides inline data. Opaque JSON, HTML, URI, Markdown and embedded-DSL
examples remain text, never executable content. Notes, declared entities and
criteria remain source-linked readable legends rather than extra graph nodes. Synthesized lane headers are excluded from selection.

Lexing yields trivia as well as visible fields, so long comment streams remain
cancellable. Located errors and bounds cover duplicate frames, malformed fields,
64 KiB tokens, 131,072 lexical work steps, 8,192 fields per declaration, 4,096
declarations and 99 qualified lanes per role band. Twenty meaningful hand
mutations guard inference, reset/explicit-input behavior, ordering, resource
limits, data precedence, locations, role colors, non-overlap and selection.

Pixel configuration (`padding`, `rowHeight`), browser typography and exact
upstream geometry are non-goals. These are not implied by corpus acceptance
or full line coverage.

## Venn stage

The eighteen pinned rendering examples in `tests/fixtures/mermaid/venn/` are
compared with `venn-reference.json`, captured from the pinned Jison grammar and
original database. Front matter is stripped for these body comparisons. Geometry
regressions verify area ratios, requested pair overlap, clipping area/containment,
determinism, source coordinates across CRLF comments, UTF-16 identifier ordering,
relative geometry after title translation, styles, degenerate regions and bounds.
Eighteen targeted mutations were killed, including the clipping interpolation
mutation after adding an intersection-area assertion. PNG examples were inspected.
The combined Mermaid run passed 2,483 tests / 23,183 assertions with full Mermaid
source line coverage; fifteen IDE render/export cases passed 105 assertions, and
all seven static gates passed. A clean final full repository verification remains
pending with the rest of the compatibility work.

## Railroad stage

The 86 verbatim accepted documentation, rendering and parser bodies in
`tests/fixtures/mermaid/railroad/` are compared with `railroad-reference.json`.
`railroad-provenance.json` records each upstream source. The executable reference
uses the four pinned Langium grammars, original token builders/value converters
and original parser-to-renderer tree transformations. Undefined/Infinity values
serialize to JSON null, represented by a nullable repetition maximum natively.
All bodies render through native rows and SVG. Additional tests cover finite
counts, escaped strings, metadata-named rules, reserved constructors, title-token
prefix behavior, comments/CRLF, exception/lookahead transforms, branch non-overlap,
optional bypasses, return loops, source links and malformed/excessive input.
Twenty meaningful mutations were killed; the finite-maximum mutation was rerun
without its earlier warning-producing harness variant. PNG tracks were inspected.
The combined Mermaid run passed 2,604 tests / 23,903 assertions with full Mermaid
source line coverage; nineteen IDE render/export cases passed 133 assertions.
All seven static gates passed. Full repository verification is recorded separately.

The combined `composer verify` after railroad passed with 100% core source line
coverage (32,040/32,040) and own-plugin line coverage (24,844/24,844).

## Modern flowchart shape stage

`flowchart-shapes.json` records the 53 ordinary shape definitions and their 150
unique names/aliases from the pinned `rendering-elements/shapes.ts` registry.
All map to native silhouettes and internal marks, including documents/stacks,
open data stores, storage cylinders/buckets, windows, persons, braces, tags and
label-free symbols. Curves are bounded sampled terminal geometry. The same
silhouettes drive edge intersections and pointer picking. Labels on label-free
symbols remain in semantic search/source data. Unknown authored shapes produce
located errors; Porta's earlier `round`/`ellipse` extensions are retained.
Authored fill/stroke, width and dash presence propagate to internal marks.

Regressions compare alias SVGs, check fill:none, decorative boundaries, stacked
layers, empty corners, cylindrical cap alignment/ring clearance, circular person
heads and long styled labels. All 53 PNGs were reviewed as a contact sheet and
braces/cylinder captions corrected. Twenty-three meaningful mutations were killed.
The combined Mermaid run passed 2,767 tests / 28,091 assertions with full Mermaid
source line coverage. Twenty IDE render/export cases passed 140 assertions, and
all seven static gates passed. The preceding full repository verify through the
railroad stage is green; a final full verify remains pending after later work.
