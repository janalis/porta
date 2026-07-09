<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget\Overlay;

use PHPUnit\Framework\TestCase;
use Porta\Widget\Overlay\FindInFilesModal;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Input\Keybindings;

final class FindInFilesModalTest extends TestCase
{
    private Keybindings $kb;

    protected function setUp(): void
    {
        // Kitty protocol stays inactive (the app default), so legacy alt/CSI
        // sequences resolve (\x1bs → alt+s, \x1b\r → alt+enter, \x1b[A → up).
        $this->kb = new Keybindings([]);
    }

    private function modal(): FindInFilesModal
    {
        return new FindInFilesModal(static fn () => null);
    }

    /** @return list<array{path: string, line: int, text: string, ranges: list<array{start: int, length: int}>}> */
    private function results(): array
    {
        return [
            ['path' => 'src/A.php', 'line' => 4, 'text' => 'foo one', 'ranges' => [['start' => 0, 'length' => 3]]],
            ['path' => 'src/A.php', 'line' => 9, 'text' => 'foo two', 'ranges' => []],
            ['path' => 'other/B.php', 'line' => 2, 'text' => 'foo three', 'ranges' => []],
        ];
    }

    private function screen(FindInFilesModal $modal): string
    {
        $lines = array_fill(0, 24, str_repeat(' ', 100));

        return AnsiUtils::stripAnsiCodes(implode("\n", $modal->composite($lines, 100, 24, true)));
    }

    public function testRendersTitleQueryScopeOptionsAndGroupedPreview(): void
    {
        $modal = $this->modal();
        $blank = array_fill(0, 24, '');
        $this->assertSame($blank, $modal->composite($blank, 100, 24, true), 'closed → lines unchanged');

        $modal->open('Find in Files');
        $modal->setScopes(['Whole Project', 'Directory'], 0);
        $modal->setOptions(['regex' => false, 'case' => true, 'word' => false, 'preserve' => false, 'tokens' => 'any']);
        $modal->setPreview($this->results());

        $screen = $this->screen($modal);
        $this->assertStringContainsString('Find in Files', $screen);
        $this->assertStringContainsString('3 results', $screen);
        $this->assertStringContainsString('Scope: Whole Project', $screen);
        $this->assertStringContainsString('Aa', $screen, 'option tags render');
        $this->assertStringContainsString('src/A.php', $screen, 'grouped file header');
        $this->assertStringContainsString('other/B.php', $screen);
        $this->assertStringContainsString('foo one', $screen, 'match rows render');
        $this->assertStringContainsString('Alt+Enter Find window', $screen, 'footer hint');
    }

    public function testEmptyAndSearchingStates(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $this->assertStringContainsString('type to search', $this->screen($modal));

        $modal->setSearching();
        $this->assertStringContainsString('searching…', $this->screen($modal));

        // A typed query with no matches shows "no results".
        $modal->handleInput('z', $this->kb);
        $modal->setPreview([]);
        $this->assertStringContainsString('no results', $this->screen($modal));
    }

    public function testQueryTypingFiresOnQuery(): void
    {
        $modal = $this->modal();
        $queries = [];
        $modal->onQuery(function (string $q) use (&$queries): void {
            $queries[] = $q;
        });
        $modal->open('Find in Files');

        $modal->handleInput('f', $this->kb);
        $modal->handleInput('o', $this->kb);
        $modal->handleInput("\x7f", $this->kb); // backspace
        $modal->handleInput("\x1b[13;2u", $this->kb); // shift+enter → newline in the query
        $modal->handleInput('o', $this->kb);
        $this->assertSame(['f', 'fo', 'f', "f\n", "f\no"], $queries, 'multiline query via shift+enter');
        $this->assertStringContainsString('f⏎o', $this->screen($modal), 'the newline renders as ⏎');
    }

    public function testQueryHistoryRecallCyclesWithAltArrows(): void
    {
        $modal = $this->modal();
        $recalled = [];
        $modal->onQuery(function (string $q) use (&$recalled): void {
            $recalled[] = $q;
        });
        $modal->open('Find in Files');
        $modal->setHistory(['recent', 'older']); // most-recent-first

        $modal->handleInput("\x1bn", $this->kb); // alt+down at the start: nothing newer → no-op
        $modal->handleInput("\x1bp", $this->kb); // alt+up → 'recent'
        $modal->handleInput("\x1bp", $this->kb); // alt+up → 'older'
        $modal->handleInput("\x1bp", $this->kb); // alt+up past the end → no-op
        $modal->handleInput("\x1bn", $this->kb); // alt+down → 'recent'
        $modal->handleInput("\x1bn", $this->kb); // alt+down → '' (back to a blank query)

        $this->assertSame(['recent', 'older', 'recent', ''], $recalled);
    }

    public function testNavigateAndJump(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $modal->onJump(function (string $p, int $l) use (&$jumped): void {
            $jumped = [$p, $l];
        });
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // rows: [hdr A, match, match, hdr B, match]; selection on first match

        $modal->handleInput("\r", $this->kb); // enter → jump first match
        $this->assertSame(['src/A.php', 4], $jumped);

        $modal->handleInput("\x1b[B", $this->kb); // down → second match (skips nothing)
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 9], $jumped);

        $modal->handleInput("\x1b[B", $this->kb); // down → skips the other/B.php header to its match
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['other/B.php', 2], $jumped);

        $modal->handleInput("\x1b[A", $this->kb); // up → back to the second A match
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 9], $jumped);
    }

    public function testScopeCycleOptionsTabMaskAndActions(): void
    {
        $modal = $this->modal();
        $scopeIdx = [];
        $toggled = [];
        $masks = [];
        $opened = false;
        $closed = false;
        $modal->onScopeCycle(function (int $i) use (&$scopeIdx): void {
            $scopeIdx[] = $i;
        });
        $modal->onToggleOption(function (string $n) use (&$toggled): void {
            $toggled[] = $n;
        });
        $modal->onMaskChanged(function (string $m) use (&$masks): void {
            $masks[] = $m;
        });
        $modal->onOpenInFindWindow(function () use (&$opened): void {
            $opened = true;
        });
        $modal->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $modal->open('Find in Files');
        $modal->setScopes(['Whole Project', 'Directory', 'Current File'], 0);

        $modal->handleInput("\x1bs", $this->kb); // alt+s → scope index 1
        $modal->handleInput("\x1br", $this->kb); // alt+r regex
        $modal->handleInput("\x1bc", $this->kb); // alt+c case
        $modal->handleInput("\x1bw", $this->kb); // alt+w word
        $modal->handleInput("\x1bv", $this->kb); // alt+v preserve
        $modal->handleInput("\x1bt", $this->kb); // alt+t tokens
        $this->assertSame([1], $scopeIdx);
        $this->assertSame(['regex', 'case', 'word', 'preserve', 'tokens'], $toggled);
        $this->assertStringContainsString('Scope: Directory', $this->screen($modal), 'scope label advanced');

        $modal->handleInput("\t", $this->kb); // tab → replace field
        $modal->handleInput("\t", $this->kb); // tab → mask field
        $modal->handleInput('p', $this->kb);
        $this->assertSame(['p'], $masks);

        $modal->handleInput("\t", $this->kb); // tab → wraps back to query
        $modal->handleInput('q', $this->kb);
        $this->assertSame([1], $scopeIdx, 'still no re-scope'); // sanity: the wrap edited the query, not scope

        $modal->handleInput("\x1b\r", $this->kb); // alt+enter → open in find window
        $this->assertTrue($opened);

        $modal->handleInput("\x1b", $this->kb); // esc → close
        $this->assertTrue($closed);
    }

    public function testReplacePreviewExcludeAndReplaceAll(): void
    {
        $modal = $this->modal();
        $replaced = null;
        $modal->onReplaceAll(function (string $r, array $excluded) use (&$replaced): void {
            $replaced = [$r, $excluded];
        });
        $modal->setPreviewTransformer(static fn (string $old, string $repl): string => str_replace('foo', $repl, $old));
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // rows: [hdr A, match(4), match(9), hdr B, match(2)]

        // Type a replacement → the preview flips to old → new with checkboxes.
        $modal->handleInput("\t", $this->kb); // query → replace
        foreach (str_split('bar') as $ch) {
            $modal->handleInput($ch, $this->kb);
        }
        $screen = $this->screen($modal);
        $this->assertStringContainsString('→', $screen, 'preview shows old → new');
        $this->assertStringContainsString('[x]', $screen, 'kept matches are checked');
        $this->assertStringContainsString('bar one', $screen, 'the replacement is previewed');

        // Exclude the first match (Del on the selected row).
        $modal->handleInput("\x1b[3~", $this->kb);
        $this->assertStringContainsString('(excluded)', $this->screen($modal));

        // Alt+A replaces the kept matches (index 0 excluded).
        $modal->handleInput("\x1ba", $this->kb);
        $this->assertSame(['bar', [0]], $replaced);
    }

    public function testEdges(): void
    {
        $modal = $this->modal();

        // Mouse on a closed modal is a no-op.
        $this->assertFalse($modal->clickAt(0, 0));
        $this->assertFalse($modal->hoverAt(0, 0));

        $modal->open('Find in Files');
        $this->assertTrue($modal->isOpen());
        $this->assertFalse($modal->hoverAt(0, 0), 'hover before the first composite (no box yet)');
        $modal->setMaskValue('*.php');
        $this->assertStringContainsString('Mask: *.php', $this->screen($modal));

        $modal->close();
        $this->assertFalse($modal->isOpen());

        // Navigation clamps at the ends (no wrap).
        $modal->open('Find in Files');
        $modal->setPreview($this->results()); // selection on the first match (display index 1)
        $modal->handleInput("\x1b[A", $this->kb); // up past the file header → stays put
        $jumped = null;
        $modal->onJump(function (string $p, int $l) use (&$jumped): void {
            $jumped = [$p, $l];
        });
        $modal->handleInput("\r", $this->kb);
        $this->assertSame(['src/A.php', 4], $jumped, 'up at the top kept the first match selected');

        // Many matches scroll the preview window.
        $many = [];
        for ($i = 0; $i < 14; ++$i) {
            $many[] = ['path' => 'src/A.php', 'line' => $i, 'text' => "hit {$i}", 'ranges' => []];
        }
        $modal->setPreview($many);
        for ($i = 0; $i < 12; ++$i) {
            $modal->handleInput("\x1b[B", $this->kb); // page down through the matches
        }
        $box = null;
        $screen = (function () use ($modal, &$box): string {
            $lines = array_fill(0, 24, str_repeat(' ', 100));
            $out = $modal->composite($lines, 100, 24, true);
            $box = $modal->lastBox();

            return AnsiUtils::stripAnsiCodes(implode("\n", $out));
        })();
        $this->assertNotNull($box);
        $this->assertGreaterThan(0, $box['windowStart'], 'the window scrolled to keep the selection visible');
        $this->assertStringContainsString('hit 11', $screen);

        // Clicking the options row off any tag, and hovering headers / outside, are inert.
        $modal->clickAt($box['previewRow'] - 2, $box['col'] + $box['width'] - 1); // options row, past the tags
        $this->assertFalse($modal->hoverAt($box['previewRow'] - 5, $box['col'] + 2), 'above the preview');
        $this->assertFalse($modal->hoverAt($box['previewRow'] + 99, $box['col'] + 2), 'below the preview');
    }

    public function testTinyTerminalDoesNotOverflow(): void
    {
        $modal = $this->modal();
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        // A 4-row terminal can't fit the box; compositing must clip, not error.
        $out = $modal->composite(array_fill(0, 4, str_repeat(' ', 40)), 40, 4, true);
        $this->assertCount(4, $out);
    }

    public function testMouseClickTogglesOptionJumpsAndDismisses(): void
    {
        $modal = $this->modal();
        $jumped = null;
        $toggled = [];
        $closed = false;
        $modal->onJump(function (string $p, int $l) use (&$jumped): void {
            $jumped = [$p, $l];
        });
        $modal->onToggleOption(function (string $n) use (&$toggled): void {
            $toggled[] = $n;
        });
        $modal->onClose(function () use (&$closed): void {
            $closed = true;
        });
        $modal->open('Find in Files');
        $modal->setPreview($this->results());
        $this->screen($modal); // composite → sets the box geometry

        $box = $modal->lastBox();
        $this->assertNotNull($box);

        // Click the first option tag (options row is previewRow - 2, inner col 1).
        $modal->clickAt($box['previewRow'] - 2, $box['col'] + 1);
        $this->assertSame(['regex'], $toggled);

        // Hover the second match row (previewRow + 2; the first match is already selected).
        $this->assertTrue($modal->hoverAt($box['previewRow'] + 2, $box['col'] + 3));
        // Click the first match row (previewRow + 1 skips the file header) → jump.
        $modal->clickAt($box['previewRow'] + 1, $box['col'] + 3);
        $this->assertSame(['src/A.php', 4], $jumped);

        // A click outside the box dismisses.
        $modal->clickAt($box['previewRow'], $box['col'] + $box['width'] + 5);
        $this->assertTrue($closed);
    }
}
