<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Widget;

use PHPUnit\Framework\TestCase;
use Porta\Ui\CaretVisibility;
use Porta\Ui\Role;
use Porta\Ui\TextField;
use Porta\Ui\Theme\DarkTheme;
use Porta\Ui\Theme\Styles;
use Porta\Widget\SearchFieldRenderer;
use Symfony\Component\Tui\Ansi\AnsiUtils;

final class SearchFieldRendererTest extends TestCase
{
    public function testTheRowStartsWithALeadingSpaceBeforeTheLabel(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $line = SearchFieldRenderer::row('Find', new TextField('x'), CaretVisibility::Hidden, '', 40, Role::Toolbar, $styles);

        $plain = AnsiUtils::stripAnsiCodes($line);
        $this->assertStringStartsWith(' Find: ', $plain, 'a leading space precedes the label');
    }

    public function testANonEmptyHintIsAppended(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $line = SearchFieldRenderer::row('Find', new TextField('x'), CaretVisibility::Hidden, 'no results', 40, Role::Toolbar, $styles);

        $this->assertStringContainsString('no results', AnsiUtils::stripAnsiCodes($line));
    }

    public function testAnEmptyHintContributesNothing(): void
    {
        $styles = new Styles(DarkTheme::definition());
        $withHint = AnsiUtils::stripAnsiCodes(SearchFieldRenderer::row('Find', new TextField('x'), CaretVisibility::Hidden, 'H', 40, Role::Toolbar, $styles));
        $withoutHint = AnsiUtils::stripAnsiCodes(SearchFieldRenderer::row('Find', new TextField('x'), CaretVisibility::Hidden, '', 40, Role::Toolbar, $styles));

        $this->assertNotSame($withHint, $withoutHint, 'a real hint changes the row');
        $this->assertSame(' Find: x'.\str_repeat(' ', 40 - \strlen(' Find: x')), $withoutHint, 'an empty hint appends nothing beyond padding');
    }
}
