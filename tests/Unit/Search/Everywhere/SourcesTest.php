<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Search\Everywhere;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\OpenFocus;
use Porta\Search\Everywhere\ActionsSource;
use Porta\Search\Everywhere\FilesSource;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Search\Everywhere\TextSource;
use Porta\Search\Everywhere\WorkspaceSymbolSource;
use Porta\Search\FileIndex;
use Porta\Search\ProjectSearch;
use Porta\Support\ProjectPaths;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\TempProject;

final class SourcesTest extends TestCase
{
    use PreservesPath;

    protected function setUp(): void
    {
        // No grep on PATH → ProjectSearch takes its synchronous PHP fallback, so
        // the TextSource emit is deterministic in-test.
        \putenv('PATH=/nonexistent');
    }

    /** @return list<SearchHit> */
    private function collect(callable $run): array
    {
        $hits = null;
        $run(static function (array $emitted) use (&$hits): void {
            $hits = $emitted;
        });
        \assert(null !== $hits, description: 'the run invoked the emit callback with a batch of hits');

        return $hits;
    }

    public function testFilesSourceRanksBasenamesAndActivatesOpen(): void
    {
        $project = new TempProject(['src/Entity/User.php' => '', 'src/Controller/UserController.php' => '', 'other.txt' => '']);
        $opened = null;
        $source = new FilesSource(new FileIndex($project->dir), new ProjectPaths($project->dir), static function (string $path, OpenFocus $focus) use (&$opened): void {
            $opened = [$path, OpenFocus::Editor === $focus];
        });

        $this->assertSame(SearchCategory::Files, $source->category());
        $hits = $this->collect(static fn (\Closure $emit) => $source->query('user', $emit));

        $this->assertSame(SearchCategory::Files, $hits[0]->category);
        $this->assertSame('User.php', $hits[0]->label, 'shortest basename match first');
        $this->assertSame('src/Entity', $hits[0]->detail);
        ($hits[0]->activate)();
        $this->assertSame([$project->dir.'/src/Entity/User.php', true], $opened);
        $source->cancel(); // no-op for a synchronous source

        // A root-level match has no directory detail.
        $rootHits = $this->collect(static fn (\Closure $emit) => $source->query('other', $emit));
        $this->assertSame('', $rootHits[0]->detail);
    }

    public function testActionsSourceFuzzyFiltersSortsAndRuns(): void
    {
        $registry = new CommandRegistry();
        $ran = null;
        $registry->add(
            'a',
            static function () use (&$ran): void {
                $ran = 'save';
            },
            label: 'Save File',
        );
        $registry->add('b', static function (): void {}, label: 'Reformat File');
        $registry->add('c', static function (): void {}, label: 'Nothing Here');
        $source = new ActionsSource($registry);

        $this->assertSame(SearchCategory::Actions, $source->category());
        $hits = $this->collect(static fn (\Closure $emit) => $source->query('save', $emit));

        $labels = \array_map(static fn (SearchHit $h): string => $h->label, $hits);
        $this->assertContains('Save File', $labels);
        $this->assertNotContains('Nothing Here', $labels, 'non-subsequence dropped');
        ($hits[0]->activate)();
        $this->assertSame('save', $ran);
        $source->cancel(); // no-op for a synchronous source
    }

    /** What can run is listed first; a greyed command follows, even when it scores higher. */
    public function testActionsSourceListsGreyedCommandsLast(): void
    {
        $registry = new CommandRegistry();
        $registry->add('a', static function (): void {}, label: 'Step Over', when: static fn (): bool => false);
        $registry->add('b', static function (): void {}, label: 'Save Stepwise');
        $source = new ActionsSource($registry);

        $hits = $this->collect(static fn (\Closure $emit) => $source->query('step', $emit));

        $this->assertSame(['Save Stepwise', 'Step Over'], \array_map(static fn (SearchHit $h): string => $h->label, $hits));
        $this->assertSame([true, false], \array_map(static fn (SearchHit $h): bool => $h->enabled, $hits));
    }

    /**
     * A command that does not match does not end the search for ones that do.
     *
     * The loop skips a non-matching command, and turning that `continue` into a
     * `break` left every test here green — each registered its matching command
     * FIRST, so the hit was already collected before the loop gave up. Register
     * it second, which is the ordinary case for any query, and the palette goes
     * empty.
     */
    public function testAMatchAfterANonMatchIsStillOffered(): void
    {
        $registry = new CommandRegistry();
        $registry->add('a', static function (): void {}, label: 'Nothing Here');
        $registry->add('b', static function (): void {}, label: 'Save File');
        $source = new ActionsSource($registry);

        $hits = $this->collect(static fn (\Closure $emit) => $source->query('save', $emit));

        $labels = \array_map(static fn (SearchHit $h): string => $h->label, $hits);
        $this->assertSame(['Save File'], $labels, 'the non-matching first entry was skipped, not treated as the end');
    }

    public function testActionsSourceOrdersMultipleMatchesByScoreDescending(): void
    {
        // Both labels match 'b', but with clearly different fuzzy scores (a
        // word-boundary hit outscores a mid-word one) — with only a single
        // match, "best first" is trivially true regardless of the sort
        // direction, so this needs two matches to actually prove it.
        $registry = new CommandRegistry();
        $registry->add('low', static function (): void {}, label: 'Alpha Beta');
        $registry->add('high', static function (): void {}, label: 'Bravo');
        $source = new ActionsSource($registry);

        $hits = $this->collect(static fn (\Closure $emit) => $source->query('b', $emit));

        $this->assertSame(['Bravo', 'Alpha Beta'], \array_map(static fn (SearchHit $h): string => $h->label, $hits));
    }

    public function testAnActionCarriesItsKeyAsTheHint(): void
    {
        // How anyone learns a shortcut: beside the thing they just searched
        // for. The resolver is asked by ID, not by label — two commands can
        // read alike and only one of them is bound.
        $registry = new CommandRegistry();
        $registry->add('save_file', static function (): void {}, label: 'Save File');
        $registry->add('toggle_format', static function (): void {}, label: 'Save Formatting Toggle');
        $source = new ActionsSource($registry, static fn (string $id): string => 'save_file' === $id ? 'Ctrl+S' : '');

        $hits = $this->collect(static fn (\Closure $emit) => $source->query('save f', $emit));
        $hints = [];
        foreach ($hits as $hit) {
            $hints[$hit->label] = $hit->detail;
        }

        $this->assertSame('Ctrl+S', $hints['Save File'] ?? null);
        $this->assertSame('', $hints['Save Formatting Toggle'] ?? null, 'an unbound action shows no key rather than someone else\'s');
    }

    public function testWorkspaceSymbolSourceFiltersByKindAndJumps(): void
    {
        $canned = [
            ['path' => 'src/User.php', 'line' => 9, 'name' => 'User', 'kind' => 5, 'label' => '[class] User'],
            ['path' => 'src/f.php', 'line' => 2, 'name' => 'helper', 'kind' => 12, 'label' => '[fn] helper'],
        ];
        $search = static function (string $query, \Closure $onResult) use ($canned): void {
            $onResult($canned);
        };
        $opened = null;
        $jumped = null;
        $classes = new WorkspaceSymbolSource(
            $search,
            SearchCategory::Classes,
            [5, 10, 11, 23],
            new ProjectPaths('/proj'),
            static function (string $p, OpenFocus $f) use (&$opened): void {
                $opened = [$p, OpenFocus::Editor === $f];
            },
            static function (int $l, int $c) use (&$jumped): void {
                $jumped = [$l, $c];
            },
        );

        $this->assertSame(SearchCategory::Classes, $classes->category());
        $hits = $this->collect(static fn (\Closure $emit) => $classes->query('u', $emit));

        $this->assertCount(1, $hits, 'only the class kind passes');
        $this->assertSame('User', $hits[0]->label);
        $this->assertSame('src/User.php:10', $hits[0]->detail, '1-based line in the detail');
        ($hits[0]->activate)();
        $this->assertSame(['/proj/src/User.php', true], $opened);
        $this->assertSame([9, 0], $jumped);

        // Symbols (empty kind filter) keeps everything.
        $symbols = new WorkspaceSymbolSource($search, SearchCategory::Symbols, [], new ProjectPaths('/proj'), static fn (): null => null, static fn (): null => null);
        $this->assertCount(2, $this->collect(static fn (\Closure $emit) => $symbols->query('u', $emit)));
    }

    public function testWorkspaceSymbolSourceDropsSupersededAndCancelledResponses(): void
    {
        $stashed = [];
        $search = static function (string $query, \Closure $onResult) use (&$stashed): void {
            $stashed[] = $onResult; // don't answer yet
        };
        $source = new WorkspaceSymbolSource($search, SearchCategory::Symbols, [], new ProjectPaths('/proj'), static fn (): null => null, static fn (): null => null);

        $seen = [];
        $emit = static function (array $hits) use (&$seen): void {
            $seen[] = $hits;
        };

        $source->query('a', $emit); // stashed[0]
        $source->query('b', $emit); // stashed[1], token bumped
        $stashed[0]([['path' => 'x', 'line' => 0, 'name' => 'a', 'kind' => 5, 'label' => 'a']]); // stale → dropped
        $this->assertSame([], $seen, 'the superseded response is ignored');

        $stashed[1]([['path' => 'y', 'line' => 0, 'name' => 'b', 'kind' => 5, 'label' => 'b']]); // current
        $this->assertCount(1, $seen, 'the current response is delivered');

        $source->query('c', $emit); // stashed[2]
        $source->cancel(); // token bumped again
        $stashed[2]([['path' => 'z', 'line' => 0, 'name' => 'c', 'kind' => 5, 'label' => 'c']]);
        $this->assertCount(1, $seen, 'a cancelled response is ignored');
    }

    public function testTextSourceGrepsAndJumps(): void
    {
        $project = new TempProject(['a.php' => "<?php\n\$needle = 1;\n"]);
        $opened = null;
        $jumped = null;
        $source = new TextSource(
            new ProjectSearch($project->dir),
            new ProjectPaths($project->dir),
            static function (string $p, OpenFocus $f) use (&$opened): void {
                $opened = [$p, OpenFocus::Editor === $f];
            },
            static function (int $l, int $c) use (&$jumped): void {
                $jumped = [$l, $c];
            },
        );

        $this->assertSame(SearchCategory::Text, $source->category());
        $hits = $this->collect(static fn (\Closure $emit) => $source->query('needle', $emit));

        $this->assertNotEmpty($hits);
        $this->assertSame(SearchCategory::Text, $hits[0]->category);
        $this->assertStringContainsString('needle', $hits[0]->label);
        ($hits[0]->activate)();
        $this->assertSame($project->dir.'/a.php', $opened[0]);
        $this->assertSame(1, $jumped[0], 'the match is on line index 1');
        // The DETAIL is what the picker prints beside the line, and nothing
        // asserted it: seven mutants lived on that one expression — the concat,
        // both its operands, and every way of shifting the `+ 1`. Lines are held
        // 0-based and shown 1-based, so an off-by-one here is wrong on every
        // result in the list.
        $this->assertSame('a.php:2', $hits[0]->detail, 'project-relative path, and the line as a reader counts it');
        $source->cancel(); // idempotent; delegates to ProjectSearch::cancel
    }

    /** With no server to ask, the source asks nothing and answers with why, not an empty "no results". */
    public function testWorkspaceSymbolSourceSaysWhenThereIsNoServer(): void
    {
        $asked = 0;
        $search = static function () use (&$asked): void {
            ++$asked;
        };
        $up = false;
        $source = new WorkspaceSymbolSource($search, SearchCategory::Symbols, [], new ProjectPaths('/proj'), static fn (): null => null, static fn (): null => null, static function () use (
            &$up,
        ): ?string {
            return $up ? null : 'No language server';
        });
        $answers = [];
        $emit = static function (array $hits, ?string $note = null) use (&$answers): void {
            $answers[] = [$hits, $note];
        };

        $source->query('Gre', $emit);
        $this->assertSame([[[], 'No language server']], $answers);
        $this->assertSame(0, $asked, 'nobody was asked');

        $up = true;
        $source->query('Gre', $emit);
        $this->assertSame(1, $asked, 'with a server, it is asked');
    }
}
