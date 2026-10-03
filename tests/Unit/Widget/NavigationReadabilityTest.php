<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\TempProject;
use Porta\Ui\Theme\MonochromeTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\FileTreeWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;

/**
 * A list's states read without colour. Under the monochrome theme the tree's
 * cursor is its reversed shade — a sidebar's cursor wears no bar
 * (SelectionBar::sidebar) — and a row marked without being the cursor keeps
 * a `│` in its first column: the bug this replaced drew the tree's parked
 * cursor and its second pick alike.
 */
final class NavigationReadabilityTest extends TestCase
{
    private FileTreeWidget $tree;

    protected function setUp(): void
    {
        $project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n", 'c.php' => "<?php\n"]);
        $this->tree = new FileTreeWidget($project->dir, styles: new Styles(MonochromeTheme::definition()));
        AttachedStyles::attach($this->tree, MonochromeTheme::definition());
        $this->tree->setFocused(true);
    }

    /** @return list<string> the first column of each entry row, colour stripped */
    private function bars(): array
    {
        $this->tree->invalidate();
        $lines = $this->tree->render(new RenderContext(30, 5));

        return \array_map(static fn (string $line): string => \mb_substr(AnsiUtils::stripAnsiCodes($line), start: 0, length: 1), \array_slice($lines, offset: 1, length: 3));
    }

    public function testTheCursorIsItsShadeAndTheMarkAShape(): void
    {
        $this->assertSame([' ', ' ', ' '], $this->bars(), 'the cursor in a sidebar wears no bar');
        $this->assertSame([1], $this->reversed(), 'its reversed shade says where it is');

        $this->tree->handleInput("\x1b[1;2B"); // Shift+↓: a.php marked, the cursor on b.php

        $this->assertSame(['│', ' ', ' '], $this->bars(), 'the second pick is a shape');
        $this->assertSame([1, 2], $this->reversed(), 'the cursor moved on; the mark is shaded too, but only it wears the │');

        $this->tree->setFocused(false);

        $this->assertSame(['│', ' ', ' '], $this->bars(), 'focus elsewhere changes neither');
    }

    /** @return list<int> the entry rows (1-based, past the header) drawn reversed */
    private function reversed(): array
    {
        $this->tree->invalidate();
        $lines = $this->tree->render(new RenderContext(30, 5));

        return \array_keys(\array_filter(\array_slice($lines, offset: 1, length: 3, preserve_keys: true), static fn (string $line): bool => \str_contains($line, "\x1b[7m")));
    }

    /** Hover has no glyph: the monochrome theme underlines it, and only it. */
    public function testHoverIsAnUnderlineOnlyTheHoveredRowWears(): void
    {
        $this->tree->hoverRow(3);
        $this->tree->invalidate();
        $lines = $this->tree->render(new RenderContext(30, 5));

        $this->assertStringContainsString("\x1b[4m", $lines[3]);
        $this->assertStringNotContainsString("\x1b[4m", $lines[2]);
    }
}
