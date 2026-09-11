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

    /**
     * Two open files with the same basename are two identical tabs, and this
     * project has fourteen `plugin.php`. Closing or editing the wrong one of a
     * matched pair is the cost, and it is not an edge case: per-directory
     * conventions (a plugin.php per plugin, a composer.json per package) make
     * collisions the normal way a repo is laid out.
     */
    public function testTabsSharingABasenameSayWhichOneTheyAre(): void
    {
        $buffers = new BufferManager();
        // The UNIQUE name is opened first on purpose. Collisions are walked in
        // the order their titles were first seen, and a walk that stopped at
        // the first title with nothing to resolve — rather than skipping it —
        // left every collision behind it ambiguous. With the pair opened first
        // the two behave identically, which is how that went unnoticed.
        $buffers->open('/p/src/Ide.php', '<?php');
        $buffers->open('/p/plugins/git/plugin.php', '<?php');
        $buffers->open('/p/plugins/symfony-lsp/plugin.php', '<?php');

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['Ide.php', 'plugin.php (git)', 'plugin.php (symfony-lsp)'], $names, 'and a name nothing collides with is left alone');
    }

    /**
     * One directory up is not always enough. Two `GitSidebar.php` under
     * `<plugin>/src/View/` agree on `View` AND on `src/View`; only the plugin
     * name separates them, so the walk has to keep going — and stop as soon as
     * it has, rather than always spelling the whole path.
     */
    public function testTheQualifierGrowsUntilItActuallyDistinguishes(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/p/plugins/git/src/View/GitSidebar.php', '<?php');
        $buffers->open('/p/plugins/other/src/View/GitSidebar.php', '<?php');

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['GitSidebar.php (git/src/View)', 'GitSidebar.php (other/src/View)'], $names);
    }

    /**
     * The shared names are shared on purpose.
     *
     * Every help page is one "Documentation" tab by design, and a titled
     * buffer shows the title it was given. Neither is a path, so neither has a
     * parent directory to be qualified with — and qualifying them would undo a
     * decision rather than resolve an accident.
     */
    public function testDeliberatelySharedTitlesAreNotTakenForACollision(): void
    {
        $buffers = new BufferManager();
        $buffers->openPane('/p/docs/README.md', 'help', '# docs');
        $buffers->openPane('/p/docs/internals/README.md', 'help', '# more');
        $one = $buffers->openPane('/p/a/architecture.dot', 'graph', 'digraph {}');
        $two = $buffers->openPane('/p/b/architecture.dot', 'graph', 'digraph {}');
        $one->title = 'Project Architecture';
        $two->title = 'Project Architecture';

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['Documentation', 'Documentation', 'Project Architecture', 'Project Architecture'], $names);
    }

    /** The pin glyph still leads: it marks the tab, the qualifier describes the file. */
    public function testAPinnedTabKeepsItsGlyphInFrontOfTheQualifiedName(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/p/plugins/git/plugin.php', '<?php');
        $buffers->open('/p/plugins/todo/plugin.php', '<?php');
        $buffers->get('/p/plugins/git/plugin.php')->pinned = true;

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame([Glyphs::PINNED.' plugin.php (git)', 'plugin.php (todo)'], $names);
    }

    /**
     * A file with no parent directory to offer has to answer with its bare
     * name — an empty `()` says less than the name alone, and a collision
     * where NEITHER side has a parent must still produce two titles rather
     * than reaching for a qualifier that was never computed.
     */
    public function testAFileWithNoParentDirectoryKeepsItsBareName(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/notes.md', 'a');
        $buffers->open('/p/notes.md', 'b');

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['notes.md', 'notes.md (p)'], $names);

        $rootOnly = new BufferManager();
        $rootOnly->open('/notes.md', 'a');
        $rootOnly->open('notes.md', 'b');

        $this->assertSame(
            ['notes.md', 'notes.md'],
            array_column(new TabListProvider($rootOnly, new Translator('en'))->tabs(), 'name'),
            'indistinguishable, but named rather than fatal',
        );
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
