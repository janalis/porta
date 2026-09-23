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
 * A list's states read without colour: under the monochrome theme, with every
 * SGR stripped, the cursor in a focused list, the cursor in an unfocused one,
 * a marked row and an ordinary row are four different first columns — the
 * bug this replaced drew the tree's parked cursor and its second pick alike.
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

    public function testTheCursorAndTheMarkAreShapes(): void
    {
        $this->assertSame(['▌', ' ', ' '], $this->bars(), 'the cursor in the list holding the keys');

        $this->tree->handleInput("\x1b[1;2B"); // Shift+↓: a.php marked, the cursor on b.php

        $this->assertSame(['│', '▌', ' '], $this->bars(), 'the second pick is not the cursor');

        $this->tree->setFocused(false);

        $this->assertSame(['│', '▎', ' '], $this->bars(), 'nor the cursor where the keys are not');
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
