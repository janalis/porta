<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search\Everywhere;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Editor\FileOpener;
use Porta\Editor\OpenFocus;
use Porta\History\RecentFilesStore;
use Porta\I18n\Translator;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchOverlay;
use Porta\Search\Everywhere\SearchRow;
use Porta\Search\Everywhere\SearchSource;
use Porta\Support\ProjectPaths;
use Porta\Support\Scheduler;
use Porta\Tests\Support\TempProject;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

final class SearchEverywhereControllerTest extends TestCase
{
    /** @var list<\Closure> */
    private array $scheduled = [];
    /** @var list<string> */
    private array $cancelled = [];
    /** @var list<array{string, bool}> */
    private array $opened = [];

    /** Set by the one test that cares what the recent-files tray remembers. */
    private ?RecentFilesStore $recentFiles = null;

    private function overlay(): SearchOverlay
    {
        return new class implements SearchOverlay {
            public ?\Closure $onQuery = null;
            public ?\Closure $onPick = null;
            public ?\Closure $onClose = null;
            public ?string $title = null;
            public ?string $emptyMessage = null;
            /** @var list<SearchRow> */
            public array $rows = [];
            public int $closes = 0;

            public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose, string $emptyMessage = ''): void
            {
                $this->title = $title;
                $this->onQuery = $onQuery;
                $this->onPick = $onPick;
                $this->onClose = $onClose;
                $this->emptyMessage = $emptyMessage;
            }

            public function setSearchRows(array $rows): void
            {
                $this->rows = $rows;
            }

            public function closeSearch(): void
            {
                ++$this->closes;
            }
        };
    }

    private function source(SearchCategory $category): SearchSource
    {
        return new class($category) implements SearchSource {
            /** @var list<\Closure> */
            public array $emits = [];
            public int $cancels = 0;

            public function __construct(
                private readonly SearchCategory $category,
            ) {}

            public function category(): SearchCategory
            {
                return $this->category;
            }

            public function query(string $query, \Closure $emit): void
            {
                $this->emits[] = $emit;
            }

            public function cancel(): void
            {
                ++$this->cancels;
            }
        };
    }

    /**
     * @param list<SearchSource> $sync
     * @param list<SearchSource> $async
     */
    private function controller(SearchOverlay $overlay, array $sync, array $async, BufferManager $buffers, string $root = '/proj'): SearchEverywhereController
    {
        $opened = &$this->opened;
        $scheduled = &$this->scheduled;
        $cancelled = &$this->cancelled;

        return new SearchEverywhereController(
            $overlay,
            $sync,
            $async,
            $buffers,
            new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)),
            new ProjectPaths($root),
            $this->recentFiles ?? new RecentFilesStore($root),
            new class($opened) implements FileOpener {
                /** @param list<array{string, bool}> $opened */
                public function __construct(
                    private array &$opened,
                ) {}

                public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
                {
                    $this->opened[] = [$path, OpenFocus::Editor === $focus];
                }
            },
            new class($scheduled, $cancelled) implements Scheduler {
                /**
                 * @param list<\Closure> $scheduled
                 * @param list<string>   $cancelled
                 */
                public function __construct(
                    private array &$scheduled,
                    private array &$cancelled,
                ) {}

                public function delay(float $seconds, \Closure $callback): string
                {
                    $this->scheduled[] = $callback;

                    return (string) \count($this->scheduled);
                }

                public function cancel(string $timerId): void
                {
                    $this->cancelled[] = $timerId;
                }
            },
            new Translator('en'),
        );
    }

    private function hit(SearchCategory $category, string $label): SearchHit
    {
        return new SearchHit($category, $label, '', static fn (): mixed => null);
    }

    /** @param list<SearchRow> $rows */
    private function labels(array $rows): array
    {
        return array_values(array_filter(array_map(static fn (SearchRow $r): ?string => $r->hit?->label, $rows)));
    }

    /** @return list<SearchHit> $count hits in one category, labelled f0…fN */
    private static function hits(SearchCategory $category, int $count): array
    {
        return array_map(
            static fn (int $n): SearchHit => new SearchHit($category, 'f'.$n, '', static function (): void {}),
            range(start: 0, end: $count - 1),
        );
    }

    public function testACappedCategoryOffersARowThatGrowsIt(): void
    {
        // The cap used to be the end of the list: eight files, five shown, and
        // no way at all to reach the sixth from here.
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();

        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 8));

        $this->assertSame(['f0', 'f1', 'f2', 'f3', 'f4', '3 more results'], $this->labels($overlay->rows));
    }

    public function testGrowingACategoryShowsTheRestAndKeepsTheModalOpen(): void
    {
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();
        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 8));

        // Picked the way any row is picked — the overlay does not know this one
        // is different.
        ($overlay->onPick)($overlay->rows[array_key_last($overlay->rows)]->hit);

        $this->assertSame(['f0', 'f1', 'f2', 'f3', 'f4', 'f5', 'f6', 'f7'], $this->labels($overlay->rows));
        $this->assertSame(0, $overlay->closes, 'a row that grows the list must not close it');
    }

    public function testAVeryLongCategoryGrowsByStepsRatherThanAllAtOnce(): void
    {
        // A grep or a workspace-symbol answer is not bounded by its source, so
        // "show the rest" would render thousands of rows in one go.
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();
        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 100));

        ($overlay->onPick)($overlay->rows[array_key_last($overlay->rows)]->hit);

        $labels = $this->labels($overlay->rows);
        $this->assertSame(25, \count($labels) - 1, 'five, then one step of twenty');
        $this->assertSame('75 more results', $labels[array_key_last($labels)], 'and it can be grown again');
    }

    public function testASingleHiddenResultIsCountedInTheSingular(): void
    {
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();

        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 6));

        $this->assertContains('1 more result', $this->labels($overlay->rows));
    }

    public function testACategoryThatFitsOffersNoGrowRow(): void
    {
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();

        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 5));

        $this->assertSame(['f0', 'f1', 'f2', 'f3', 'f4'], $this->labels($overlay->rows));
    }

    public function testANewQueryCollapsesAGrownCategoryAgain(): void
    {
        // Carrying the expansion over would show one category swollen for a
        // search that never asked for it.
        $overlay = $this->overlay();
        $files = $this->source(SearchCategory::Files);
        $controller = $this->controller($overlay, [$files], [], new BufferManager());
        $controller->open();
        ($overlay->onQuery)('f');
        $files->emits[0](self::hits(SearchCategory::Files, 8));
        ($overlay->onPick)($overlay->rows[array_key_last($overlay->rows)]->hit);

        ($overlay->onQuery)('g');
        $files->emits[1](self::hits(SearchCategory::Files, 8));

        $this->assertSame(['f0', 'f1', 'f2', 'f3', 'f4', '3 more results'], $this->labels($overlay->rows));
    }

    public function testOpenSeedsRecentFilesAndOpensTheOverlay(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/proj/src/A.php', '');
        $buffers->open('/proj/B.php', '');
        $overlay = $this->overlay();

        $this->controller($overlay, [], [], $buffers)->open();

        $this->assertNotNull($overlay->onQuery, 'the overlay was opened with callbacks');
        $this->assertSame('Search Everywhere', $overlay->title, 'the title is translated (English identity here)');
        $this->assertSame('no results', $overlay->emptyMessage, 'the no-hit empty message is threaded through');
        $this->assertTrue($overlay->rows[0]->isHeader());
        $this->assertSame('Files', $overlay->rows[0]->header);
        $this->assertSame(['B.php', 'A.php'], $this->labels($overlay->rows), 'most-recent first');
        $this->assertSame('', $overlay->rows[1]->hit?->detail, 'B.php is at the root → no dir');
        $this->assertSame('src', $overlay->rows[2]->hit?->detail, 'A.php shows its relative dir');

        ($overlay->rows[2]->hit->activate)(); // opening a recent file
        $this->assertSame([['/proj/src/A.php', true]], $this->opened);
    }

    public function testTheTrayAlsoLISTSFilesThatAreNoLongerOpen(): void
    {
        // The half a BufferManager cannot answer: closing the tab erases the
        // file from `recent()`, and it is usually the one you want back.
        $project = new TempProject(['src/Closed.php' => '<?php', 'Gone.php' => '<?php']);
        $this->recentFiles = new RecentFilesStore($project->dir);
        $this->recentFiles->record($project->path('Gone.php'));
        $this->recentFiles->record($project->path('src/Closed.php'));
        $this->recentFiles->record($project->path('src/Open.php'));
        unlink($project->path('Gone.php'));

        $buffers = new BufferManager();
        $buffers->open($project->path('src/Open.php'), '');
        $overlay = $this->overlay();

        $this->controller($overlay, [], [], $buffers, $project->dir)->open();

        $this->assertSame(
            ['Open.php', 'Closed.php'],
            $this->labels($overlay->rows),
            'the open buffer first, then the closed file — and never the deleted one',
        );

        ($overlay->rows[2]->hit?->activate)();
        $this->assertSame([[$project->path('src/Closed.php'), true]], $this->opened, 'activating it opens it');
    }

    public function testQueryRunsSyncSourcesNowAndAsyncOnTheDebounce(): void
    {
        $files = $this->source(SearchCategory::Files);
        $classes = $this->source(SearchCategory::Classes);
        $overlay = $this->overlay();
        $this->controller($overlay, [$files], [$classes], new BufferManager())->open();

        ($overlay->onQuery)('user');

        $this->assertCount(1, $files->emits, 'sync source queried immediately');
        $this->assertCount(0, $classes->emits, 'async source waits for the debounce');
        $this->assertCount(1, $this->scheduled, 'the async debounce is scheduled');

        $files->emits[0]([$this->hit(SearchCategory::Files, 'User.php')]);
        $this->assertSame(['User.php'], $this->labels($overlay->rows));

        $this->scheduled[0](); // fire the debounce
        $this->assertCount(1, $classes->emits, 'async source queried after the debounce');
        $classes->emits[0]([$this->hit(SearchCategory::Classes, 'User')]);

        $this->assertSame(['User.php', 'User'], $this->labels($overlay->rows), 'Files then Classes, grouped by category order');
    }

    public function testStaleResponsesAreDropped(): void
    {
        $files = $this->source(SearchCategory::Files);
        $overlay = $this->overlay();
        $this->controller($overlay, [$files], [], new BufferManager())->open();

        ($overlay->onQuery)('a'); // generation G1 → files->emits[0]
        ($overlay->onQuery)('b'); // generation G2 → files->emits[1]

        $files->emits[0]([$this->hit(SearchCategory::Files, 'OLD')]); // stale
        $this->assertNotContains('OLD', $this->labels($overlay->rows), 'the superseded response is dropped');

        $files->emits[1]([$this->hit(SearchCategory::Files, 'NEW')]); // current
        $this->assertContains('NEW', $this->labels($overlay->rows));
    }

    public function testStaleDebounceFiringAfterANewQueryIsAnInertNoOp(): void
    {
        $classes = $this->source(SearchCategory::Classes);
        $overlay = $this->overlay();
        $this->controller($overlay, [], [$classes], new BufferManager())->open();

        ($overlay->onQuery)('a'); // schedules debounce[0] for G1
        ($overlay->onQuery)('b'); // G2, schedules debounce[1], cancels debounce[0] handle

        $this->assertNotEmpty($this->cancelled, 'the first debounce handle is cancelled');
        $this->scheduled[0](); // the stale debounce fires anyway → guard drops it
        $this->assertCount(0, $classes->emits, 'a stale debounce does not query');

        $this->scheduled[1]();
        $this->assertCount(1, $classes->emits, 'the current debounce queries');
    }

    public function testEmptyQueryShowsRecentFilesAgain(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/proj/A.php', '');
        $files = $this->source(SearchCategory::Files);
        $overlay = $this->overlay();
        $this->controller($overlay, [$files], [], $buffers)->open();

        ($overlay->onQuery)('x');
        $files->emits[0]([$this->hit(SearchCategory::Files, 'match.php')]);
        $this->assertSame(['match.php'], $this->labels($overlay->rows));

        ($overlay->onQuery)(''); // back to recents
        $this->assertSame(['A.php'], $this->labels($overlay->rows));
    }

    public function testPickActivatesTheHitAndClosesTheOverlay(): void
    {
        $overlay = $this->overlay();
        $this->controller($overlay, [], [], new BufferManager())->open();

        $activated = false;
        $hit = new SearchHit(SearchCategory::Files, 'X', '', static function () use (&$activated): void {
            $activated = true;
        });
        ($overlay->onPick)($hit);

        $this->assertTrue($activated, 'the hit was activated');
        $this->assertSame(1, $overlay->closes);
    }

    public function testCloseCancelsTheDebounceAndAsyncSources(): void
    {
        $classes = $this->source(SearchCategory::Classes);
        $overlay = $this->overlay();
        $this->controller($overlay, [], [$classes], new BufferManager())->open();

        ($overlay->onQuery)('x'); // schedules a debounce
        ($overlay->onClose)();

        $this->assertNotEmpty($this->cancelled, 'the pending debounce is cancelled');
        $this->assertSame(1, $classes->cancels, 'the async source is cancelled');
        $this->assertSame(1, $overlay->closes);
    }
}
