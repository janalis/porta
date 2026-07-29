<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Workbench\Dock;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Workbench\Dock\TabListProvider;

final class TabListProviderTest extends TestCase
{
    public function testTitlesPerBufferKind(): void
    {
        $buffers = new BufferManager();
        $buffers->open('/p/src/Demo.php', '<?php');
        $buffers->openHelp('/p/docs/README.md', '# docs');
        $graph = $buffers->openGraph('/p/.porta/architecture.dot', 'digraph {}');
        $graph->title = 'Project Architecture';
        $buffers->openGraph('/p/other.dot', 'digraph {}'); // untitled graph tab keeps its basename

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['Demo.php', 'Documentation', 'Project Architecture', 'other.dot'], $names);
    }
}
