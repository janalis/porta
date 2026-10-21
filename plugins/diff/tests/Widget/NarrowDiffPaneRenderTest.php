<?php

declare(strict_types=1);

namespace Porta\DiffPlugin\Tests\Widget;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\DiffPlugin\DiffDocument;
use Porta\DiffPlugin\Widget\DiffViewWidget;
use Porta\I18n\Translator;
use Porta\Tests\Support\AttachedStyles;
use Porta\Tests\Support\RendersExactWidth;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * No diff widget may render a row wider than the pane it was given — the
 * diff pane's share of the git plugin's `NarrowGitPaneRenderTest`, moved here
 * with it. What that sweep first found in this pane: the toolbar's two display
 * toggles drawn at a fixed twenty-two columns whatever the pane could seat,
 * and the row gutters, unified and split, clamping the TEXT and leaving the
 * row unbounded.
 */
final class NarrowDiffPaneRenderTest extends TestCase
{
    use RendersExactWidth;

    private const string RAW = <<<'DIFF'
        diff --git a/src/demo.php b/src/demo.php
        --- a/src/demo.php
        +++ b/src/demo.php
        @@ -1,3 +1,4 @@
         context
        -$old = 2;
        +$new = 2;
        +$extra = 3;
        DIFF;

    /**
     * Three states, because they are three different assemblers: the
     * placeholder path draws only the toolbar, and split builds each row from
     * two halves rather than one gutter — and the two with rows, wrapped.
     *
     * @return iterable<string, array{AbstractWidget}>
     */
    public static function diffWidgets(): iterable
    {
        $unified = new DiffViewWidget(new Translator('en'));
        $unified->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        yield 'diff (unified)' => [$unified];

        $split = new DiffViewWidget(new Translator('en'));
        $split->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        $split->useSplitView();
        yield 'diff (split)' => [$split];

        // Wrapped, each row is several segments cut at a body width that a
        // narrow pane takes below one: the same gutters, more rows of them.
        $wrapped = new DiffViewWidget(new Translator('en'));
        $wrapped->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        $wrapped->useSoftWrap();
        yield 'diff (unified, wrapped)' => [$wrapped];

        $splitWrapped = new DiffViewWidget(new Translator('en'));
        $splitWrapped->setDocument(DiffDocument::parse(self::RAW), 'src/demo.php');
        $splitWrapped->useSplitView();
        $splitWrapped->useSoftWrap();
        yield 'diff (split, wrapped)' => [$splitWrapped];

        $empty = new DiffViewWidget(new Translator('en'));
        $empty->setDocument(null, null);
        yield 'diff (no selection)' => [$empty];
    }

    #[DataProvider('diffWidgets')]
    public function testADiffWidgetFitsEveryWidthDownToOne(AbstractWidget $widget): void
    {
        AttachedStyles::attach($widget);

        self::assertFitsEveryWidth($widget);
    }

    /** Every widget in this plugin is in the provider above; a hand-written "everything" goes stale. */
    public function testEveryDiffWidgetIsInTheProvider(): void
    {
        $swept = [];
        foreach (self::diffWidgets() as [$widget]) {
            $swept[$widget::class] = true;
        }

        $missing = [];
        $found = \glob(\dirname(__DIR__, levels: 2).'/src/Widget/*.php');
        foreach (false === $found ? [] : $found as $file) {
            $class = 'Porta\\DiffPlugin\\Widget\\'.\basename($file, suffix: '.php');
            if (\class_exists($class) && \is_a($class, AbstractWidget::class, allow_string: true) && !\array_key_exists($class, $swept)) {
                $missing[] = $class;
            }
        }
        \sort($missing);

        $this->assertSame([], $missing, 'a host mounts these and nothing renders them at a hostile width');
    }
}
