<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench;

use PHPUnit\Framework\TestCase;
use Porta\Editor\EditorPanes;
use Porta\Layout\LayoutWidgets;
use Porta\Layout\PreviewSplit;
use Porta\Tests\Support\EmptyFolder;
use Porta\Tests\Support\TestTui;
use Porta\Ui\Theme\Styles;
use Porta\Widget\CodeEditorWidget;
use Porta\Widget\EditorPaneWidget;
use Porta\Widget\FileTreeWidget;
use Porta\Workbench\EditorSlot;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\WidgetContext;

/**
 * The editor body's content slot: which of the editor, a pane, or the editor
 * beside a preview fills it — every candidate taken out first, the panes that
 * left told so, and the keys handed back to whoever is still on screen.
 */
final class EditorSlotTest extends TestCase
{
    private Tui $tui;
    private ContainerWidget $body;
    private CodeEditorWidget $editor;
    private EditorPaneWidget $image;
    private EditorPaneWidget $markdown;

    /** @var list<string> which pane was hidden, in order */
    private array $hidden = [];

    /** @var list<string> what each pane was told of its text, in order: `id:beside` / `id:alone` */
    private array $told = [];

    private function pane(string $id): EditorPaneWidget
    {
        $hidden = &$this->hidden;
        $told = &$this->told;

        return new class($id, $hidden, $told) extends EditorPaneWidget {
            /**
             * @param list<string> $hidden
             * @param list<string> $told
             */
            public function __construct(
                private readonly string $id,
                private array &$hidden,
                private array &$told,
            ) {}

            // @mago-expect lint:no-boolean-flag-parameter -- an override: the signature is EditorPaneWidget's
            public function textBeside(bool $beside): void
            {
                $this->told[] = $this->id.':'.($beside ? 'beside' : 'alone');
            }

            public function paneId(): string
            {
                return $this->id;
            }

            public function show(string $path, mixed $payload): void {}

            public function clear(): void {}

            public function statusRight(): string
            {
                return '';
            }

            public function hide(): void
            {
                $this->hidden[] = $this->id;
            }

            public function handleInput(string $data): void {}

            public function render(RenderContext $context): array
            {
                return [];
            }
        };
    }

    private PreviewSplit $split;
    private EditorSlot $slot;

    protected function setUp(): void
    {
        $this->tui = TestTui::create();
        $this->body = LayoutWidgets::container('editor-body', new Style());
        $this->tui->add($this->body);
        $this->editor = new CodeEditorWidget();
        $this->image = $this->pane('image');
        $this->markdown = $this->pane('markdown');
        $panes = new EditorPanes();
        $panes->register($this->image, static fn (string $path): string => '');
        $panes->register($this->markdown, static fn (string $path): string => '');
        $this->split = new PreviewSplit(Styles::dark());
        $this->slot = new EditorSlot($this->editor, $panes, $this->body, $this->split, $this->tui);
        $this->slot->showEditor();
    }

    /** @return list<AbstractWidget> */
    private function content(): array
    {
        return $this->body->all();
    }

    public function testEachArrangementLeavesExactlyItsOwnContentInTheSlot(): void
    {
        $this->assertSame([$this->editor], $this->content());

        $this->slot->showPane($this->image);
        $this->assertSame([$this->image], $this->content());

        $this->slot->showBeside($this->markdown);
        $this->assertSame([$this->split->area], $this->content());
        $this->assertSame($this->markdown, $this->split->preview());
        $this->assertInstanceOf(WidgetContext::class, $this->editor->getContext(), 'the editor is on screen, lent to the split');

        $this->slot->showEditor();
        $this->assertSame([$this->editor], $this->content());
        $this->assertNull($this->split->preview(), 'the split handed its widgets back');
    }

    public function testAPaneThatLeavesTheScreenIsToldAndOneThatStaysIsNot(): void
    {
        $this->slot->showPane($this->markdown);
        $this->hidden = [];

        $this->slot->showBeside($this->markdown);

        $this->assertSame(['image'], $this->hidden, 'the preview only moved beside the editor');
    }

    /** A preview beside its text leaves the text's errors to the editor; shown alone, it may have to say them. */
    public function testAPaneIsToldWhetherItsTextIsBesideIt(): void
    {
        $this->slot->showBeside($this->markdown);
        $this->slot->showPane($this->markdown);
        $this->slot->showPane($this->image);

        $this->assertSame(['markdown:beside', 'markdown:alone', 'image:alone'], $this->told);
    }

    public function testShowingWhatIsShownChangesNothing(): void
    {
        $this->slot->showBeside($this->markdown);
        $this->hidden = [];

        $this->slot->showBeside($this->markdown);
        $this->slot->showPane($this->image);
        $this->hidden = [];
        $this->slot->showPane($this->image);

        $this->assertSame([], $this->hidden, 'no swap happened, so nobody was hidden');
        $this->assertSame([$this->image], $this->content());
    }

    public function testTheKeysStayWithAWidgetStillOnScreenAndGoToTheFirstShownOtherwise(): void
    {
        $this->tui->setFocus($this->editor);
        $this->slot->showBeside($this->markdown);
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the editor moved beside the preview and kept them');

        $this->tui->setFocus($this->markdown);
        $this->slot->showPane($this->markdown);
        $this->assertSame($this->markdown, $this->tui->getFocus(), 'the preview alone keeps them');

        $this->slot->showEditor();
        $this->assertSame($this->editor, $this->tui->getFocus(), 'the preview left: the editor takes them');

        $this->slot->showPane($this->image);
        $this->assertSame($this->image, $this->tui->getFocus());
    }

    public function testKeysHeldOutsideTheAreaAreNotPulledIntoIt(): void
    {
        $tree = new FileTreeWidget(EmptyFolder::path());
        $this->tui->add($tree);
        $this->tui->setFocus($tree);

        $this->slot->showBeside($this->markdown);
        $this->slot->showPane($this->image);

        $this->assertSame($tree, $this->tui->getFocus());
    }
}
