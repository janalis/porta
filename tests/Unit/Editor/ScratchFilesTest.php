<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Config\LanguageCatalog;
use Porta\Editor\FileOpener;
use Porta\Editor\ModalHost;
use Porta\Editor\OpenFocus;
use Porta\Editor\ScratchFiles;
use Porta\I18n\Translator;
use Porta\Tests\Support\PreservesPortaHome;
use Porta\Tests\Support\StatusReads;
use Porta\Tests\Support\TempProject;
use Porta\Tests\Support\TestTui;
use Porta\Ui\TextEcho;
use Porta\Widget\StatusBarWidget;
use Porta\Workbench\Notifier;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A buffer to try something in that belongs to no project. The language pick
 * IS the interaction, so most of what there is to get wrong is the list.
 */
final class ScratchFilesTest extends TestCase
{
    use PreservesPortaHome;
    use StatusReads;

    /** @var list<array{string, OpenFocus}> */
    private array $opened = [];

    /** @var list<array{label: string, hint: string, value: string}> */
    private array $items = [];

    private ?\Closure $onPick = null;

    private StatusBarWidget $statusBar;

    private function scratches(): ScratchFiles
    {
        $items = &$this->items;
        $onPick = &$this->onPick;
        $opened = &$this->opened;
        $this->statusBar = new StatusBarWidget();

        return new ScratchFiles(
            new class($items, $onPick) implements ModalHost {
                /** @param list<array{label: string, hint: string, value: string}> $items */
                public function __construct(
                    private array &$items,
                    private ?\Closure &$onPick,
                ) {}

                public function openListOverlay(string $title, array $items, callable $onPick): void
                {
                    $this->items = $items;
                    $this->onPick = $onPick(...);
                }

                public function withdrawListOverlay(callable $onPick): void {}

                public function openPrompt(string $label, string $initial, callable $onSubmit, bool $danger = false, TextEcho $echo = TextEcho::Visible): void {}
            },
            new class($opened) implements FileOpener {
                /** @param list<array{string, OpenFocus}> $opened */
                public function __construct(
                    private array &$opened,
                ) {}

                public function openFile(string $path, OpenFocus $focus = OpenFocus::Keep): void
                {
                    $this->opened[] = [$path, $focus];
                }
            },
            new Notifier($this->statusBar, TestTui::create(80, 24)),
            new Translator(),
        );
    }

    public function testTheListIsTheCATALOGUEDLanguagesPlusPlainText(): void
    {
        $this->scratches()->open();

        $labels = \array_column($this->items, 'label');
        $this->assertSame('PHP', $labels[0], 'PHP first: this is a PHP IDE');
        $this->assertContains('Markdown', $labels);
        $this->assertSame(
            ['label' => 'Plain text', 'hint' => '.txt', 'value' => 'txt'],
            \end($this->items),
            'the pick that is not a language comes last, and still names its extension',
        );
        $this->assertCount(\count(LanguageCatalog::EXTENSIONS) + 1, $labels);

        // The hint is the extension the file will actually get, so what you
        // read in the list is what lands on disk.
        $this->assertSame(['.php', '.md'], [
            $this->items[0]['hint'],
            $this->items[\array_search('Markdown', $labels, strict: true)]['hint'],
        ]);
    }

    public function testPickingALanguageMintsAnEmptyFileAndOpensIt(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $scratches = $this->scratches();
        $scratches->open();

        ($this->onPick)('php');

        $path = $home->path('.porta/scratches/scratch_1.php');
        $this->assertFileExists($path);
        $this->assertSame('', (string) \file_get_contents($path), 'a scratch starts empty');
        $this->assertSame([[$path, OpenFocus::Editor]], $this->opened, 'and the caret lands in it');
    }

    public function testTheNUMBERCountsPerExtension(): void
    {
        // Two PHP scratches and a Markdown one: the PHP pair is 1 and 2, and
        // the Markdown file is 1 rather than 3 — the number belongs to the
        // language, which is what makes it readable in a tab strip.
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        $scratches = $this->scratches();
        $scratches->open();

        ($this->onPick)('php');
        ($this->onPick)('php');
        ($this->onPick)('md');

        $this->assertSame(
            [
                $home->path('.porta/scratches/scratch_1.php'),
                $home->path('.porta/scratches/scratch_2.php'),
                $home->path('.porta/scratches/scratch_1.md'),
            ],
            \array_column($this->opened, 0),
        );
    }

    public function testWithNoHomeItSaysSoRatherThanWritingSomewhereElse(): void
    {
        // Neither PORTA_HOME nor HOME: the environment in which the global
        // config layer goes away too. The working directory is not a home.
        $home = \getenv('HOME');
        \putenv('PORTA_HOME=');
        \putenv('HOME=');

        try {
            $scratches = $this->scratches();
            $scratches->open();
            ($this->onPick)('php');
        } finally {
            // PreservesPortaHome covers only its own variable; HOME is the
            // developer's and putenv is process-global.
            \putenv(false !== $home ? 'HOME='.$home : 'HOME');
        }

        $this->assertSame('No home directory for scratch files', $this->flashText($this->statusBar));
        $this->assertSame([], $this->opened, 'nothing was opened');
    }

    public function testAnUnWRITABLEScratchDirIsReportedNotThrown(): void
    {
        $home = new TempProject();
        \putenv('PORTA_HOME='.$home->dir);
        // A FILE where the scratches directory needs to be: dumpFile cannot
        // mkdir through it, and the failure must not take the IDE down.
        new Filesystem()->dumpFile($home->path('.porta/scratches'), '');
        $scratches = $this->scratches();
        $scratches->open();

        ($this->onPick)('php');

        $this->assertSame('Cannot create scratch_1.php', $this->flashText($this->statusBar));
        $this->assertSame([], $this->opened);
    }
}
