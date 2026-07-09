<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search\Everywhere;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\SearchOverlay;
use Porta\Search\Everywhere\SearchRow;
use Porta\Search\Everywhere\SearchSource;
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

    private function overlay(): SearchOverlay
    {
        return new class implements SearchOverlay {
            public ?\Closure $onQuery = null;
            public ?\Closure $onPick = null;
            public ?\Closure $onClose = null;
            /** @var list<SearchRow> */
            public array $rows = [];
            public int $closes = 0;

            public function openSearch(string $title, \Closure $onQuery, \Closure $onPick, \Closure $onClose): void
            {
                $this->onQuery = $onQuery;
                $this->onPick = $onPick;
                $this->onClose = $onClose;
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
    private function controller(SearchOverlay $overlay, array $sync, array $async, BufferManager $buffers): SearchEverywhereController
    {
        return new SearchEverywhereController(
            $overlay,
            $sync,
            $async,
            $buffers,
            new Tui(terminal: new VirtualTerminal(columns: 80, rows: 24)),
            '/proj',
            function (string $path, bool $focus): void {
                $this->opened[] = [$path, $focus];
            },
            function (float $seconds, \Closure $callback): string {
                $this->scheduled[] = $callback;

                return (string) \count($this->scheduled);
            },
            function (string $handle): void {
                $this->cancelled[] = $handle;
            },
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

    public function testOpenSeedsRecentFilesAndOpensTheOverlay(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/proj/src/A.php', '');
        $buffers->open('/proj/B.php', '');
        $overlay = $this->overlay();

        $this->controller($overlay, [], [], $buffers)->open();

        $this->assertNotNull($overlay->onQuery, 'the overlay was opened with callbacks');
        $this->assertTrue($overlay->rows[0]->isHeader());
        $this->assertSame(SearchCategory::Files, $overlay->rows[0]->header);
        $this->assertSame(['B.php', 'A.php'], $this->labels($overlay->rows), 'most-recent first');
        $this->assertSame('', $overlay->rows[1]->hit?->detail, 'B.php is at the root → no dir');
        $this->assertSame('src', $overlay->rows[2]->hit?->detail, 'A.php shows its relative dir');

        ($overlay->rows[2]->hit->activate)(); // opening a recent file
        $this->assertSame([['/proj/src/A.php', true]], $this->opened);
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
        $hit = new SearchHit(SearchCategory::Files, 'X', '', function () use (&$activated): void {
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
