<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Ui\Glyphs;
use Porta\Workbench\Dock\TabListProvider;

final class TabListProviderTest extends TestCase
{
    public function testTitlesPerBufferKind(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/p/src/Demo.php', '<?php');
        $buffers->openPane('/p/docs/README.md', 'help', '# docs');
        $graph = $buffers->openPane('/p/.porta/architecture.dot', 'graph', 'digraph {}');
        $graph->title = 'Project Architecture';
        $buffers->openPane('/p/other.dot', 'graph', 'digraph {}'); // untitled graph tab keeps its basename

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['Demo.php', 'Documentation', 'Project Architecture', 'other.dot'], $names);
    }

    public function testAPinnedTabCarriesThePinGlyphBeforeItsName(): void
    {
        // The marker rides the NAME, so the strip's width arithmetic measures
        // it without a fourth field and a second set of sums.
        $buffers = new BufferManager();
        $buffers->open('/p/kept.php', '<?php');
        $buffers->open('/p/other.php', '<?php');
        $buffers->get('/p/kept.php')->pinned = true;

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame([Glyphs::PINNED.' kept.php', 'other.php'], $names);
    }

    public function testDirtyAndActiveFlagsPerBuffer(): void
    {
        $buffers = new BufferManager();
        $a = $buffers->open('/p/a.php', '<?php');
        $buffers->open('/p/b.php', '<?php'); // opening a second buffer makes it the active one
        $a->document->insert('x'); // dirty the first, now-inactive buffer

        $tabs = new TabListProvider($buffers, new Translator('en'))->tabs();

        $this->assertSame(
            [
                ['name' => 'a.php', 'dirty' => true, 'active' => false],
                ['name' => 'b.php', 'dirty' => false, 'active' => true],
            ],
            $tabs,
        );
    }
}
