<?php

declare(strict_types=1);

namespace Porta\Support;

/**
 * The one definition of a results-list row, as the type alias `ResultRow`.
 *
 * A path, a line, the text to draw, and two optional extras: `ranges` are
 * display-column match spans (search results only) and `tail` is a suffix the
 * panel keeps whole when it has to squeeze the row — the Problems panel's rule
 * column. Everything that feeds a results list speaks it: project search,
 * problems, bookmarks, recent locations, usages, the log tools, and any plugin
 * calling `PluginApi::showList()`.
 *
 * It carries no code because it cannot. This shape is part of the PLUGIN API,
 * so it has to stay a plain array — a readonly class beside `DisplayRow` and
 * `SearchHit` would be the natural home otherwise, and was the first thing
 * tried here; it would break every plugin that builds rows.
 *
 * What it can stop being is fourteen copies of one docblock. Adding `tail`
 * meant editing all of them across eight files before the analyzer went quiet,
 * and the only thing keeping them in agreement was that somebody noticed.
 *
 * Named apart from the alias it holds on purpose: a class and a type with
 * the same name collide, and the `use` of the class wins — every import
 * silently resolved to the empty class instead of the shape.
 *
 * @phpstan-type ResultRow array{path: string, line: int, text: string, tail?: string, ranges?: list<array{start: int, length: int}>}
 */
final class ResultRowShape {}
