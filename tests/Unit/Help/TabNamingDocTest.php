<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\I18n\Translator;
use Porta\Support\ProjectPaths;
use Porta\Workbench\Dock\TabListProvider;

/**
 * The tab-naming examples in `docs/editor.md` are the real output.
 *
 * A worked example is the part of a doc a reader trusts most and the part
 * nothing checks: `plugin.php (git)` is a promise about a format, and the
 * format lives in {@see TabListProvider}. The sibling doc gates here ask
 * whether a vocabulary is MENTIONED; this one asks whether a demonstration is
 * still true, which is the failure the others cannot see.
 */
final class TabNamingDocTest extends TestCase
{
    private const string DOC = __DIR__.'/../../../docs/editor.md';

    /** @param list<string> $paths @return list<string> */
    private static function titlesFor(array $paths): array
    {
        $buffers = new BufferManager();
        foreach ($paths as $path) {
            $buffers->open($path, '<?php');
        }

        return \array_column(new TabListProvider($buffers, new Translator('en'), new ProjectPaths('/'))->tabs(), 'name');
    }

    public function testTheDocumentedCollisionExampleIsWhatTheCodeProduces(): void
    {
        $doc = (string) \file_get_contents(self::DOC);

        $titles = self::titlesFor(['/p/plugins/git/plugin.php', '/p/plugins/symfony-lsp/plugin.php']);

        $this->assertSame(['plugin.php (git)', 'plugin.php (symfony-lsp)'], $titles);
        foreach ($titles as $title) {
            $this->assertStringContainsString($title, $doc, 'the doc shows this example, so it has to still be the output');
        }
    }

    public function testTheDocumentedDeeperExampleIsToo(): void
    {
        $doc = (string) \file_get_contents(self::DOC);

        $titles = self::titlesFor(['/p/plugins/git/src/View/GitSidebar.php', '/p/plugins/other/src/View/GitSidebar.php']);

        $this->assertSame(['GitSidebar.php (git/src/View)', 'GitSidebar.php (other/src/View)'], $titles, 'one directory up is not enough here');
        $this->assertStringContainsString('GitSidebar.php (git/src/View)', $doc);
        $this->assertStringContainsString('(other/src/View)', $doc);
    }
}
