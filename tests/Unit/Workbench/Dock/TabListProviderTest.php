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
        $buffers->openPane('/p/docs/README.md', 'help', '# docs');
        $graph = $buffers->openPane('/p/.porta/architecture.dot', 'graph', 'digraph {}');
        $graph->title = 'Project Architecture';
        $buffers->openPane('/p/other.dot', 'graph', 'digraph {}'); // untitled graph tab keeps its basename

        $names = array_column(new TabListProvider($buffers, new Translator('en'))->tabs(), 'name');

        $this->assertSame(['Demo.php', 'Documentation', 'Project Architecture', 'other.dot'], $names);
    }
}
