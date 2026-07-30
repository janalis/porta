<?php

declare(strict_types=1);

namespace Porta\Todo;

use Porta\Plugin\Api\PluginApi;
use Porta\Search\ProjectSearch;
use Porta\Search\SearchOptions;
use Porta\Workbench\Dock\ToolRun;

/**
 * One project-wide scan for the configured comment tags, published into this
 * plugin's dock tab. Case-sensitive on purpose, so prose "todo" stays out; the
 * regex ranges bold the keyword in each row.
 *
 * It lives here rather than in `plugin.php` because a plugin file only WIRES:
 * everything under `src/` is analysed by mago and held at 100% coverage by this
 * plugin's own tests, and a scan with an async completion is exactly the logic
 * that must be.
 *
 * **This owns its own {@see ProjectSearch}**, which reverses a trade core used
 * to make: when the scanner was core's it shared the find-in-project engine's
 * single process slot, so starting either search cancelled the other. Two
 * engines means a TODO scan and a find now run concurrently — better for the
 * user, and worth saying out loud because the old behaviour was deliberate.
 */
final class TodoScan
{
    public const string TOOL = 'todo';

    public function __construct(
        private readonly PluginApi $api,
        private readonly ProjectSearch $engine,
    ) {}

    /** Open (or F5-refresh) the TODO tab and kick off the scan. */
    public function run(): void
    {
        $this->api->showList(self::TOOL, [], ToolRun::Running);
        $this->engine->search(
            TodoTags::pattern((string) $this->api->setting('tags')),
            function (array $results): void {
                // No staleness check here: publishing by id is what makes a
                // late answer to a ×'d tab a silent no-op.
                $this->api->updateList(self::TOOL, $results);
            },
            new SearchOptions(regex: true, caseSensitive: true),
        );
    }
}
