<?php

declare(strict_types=1);

namespace Porta\Tests\E2e;

use PHPUnit\Framework\TestCase;
use Porta\Config\CommandRegistry;
use Porta\Editor\BufferManager;
use Porta\Event\TreeDropRequested;
use Porta\Ide;
use Porta\Tests\Support\ClearsPortaLsp;
use Porta\Tests\Support\PreservesPath;
use Porta\Tests\Support\RunsIde;
use Porta\Tests\Support\ScreenReads;
use Porta\Tests\Support\Services;
use Porta\Tests\Support\TempProject;
use Porta\Workbench\DropAction;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;

/**
 * A PHP file moved in the tree takes its namespace and the `use` statements
 * naming it along — when its server registered for such moves, as phpactor
 * does. The fixture server answers the way phpactor answered a probe.
 */
final class IdeFileMoveFollowTest extends TestCase
{
    use ClearsPortaLsp;
    use PreservesPath;
    use RunsIde;
    use ScreenReads;

    protected function setUp(): void
    {
        \putenv('PATH=/nonexistent');
    }

    private static function project(string $mode): TempProject
    {
        return new TempProject([
            '.porta.json' => \json_encode(['lsp' => \PHP_BINARY.' '.\dirname(__DIR__).'/Support/fixtures/file-rename-lsp-server.php '.$mode], \JSON_THROW_ON_ERROR),
            'src/Foo.php' => "<?php\nnamespace App;\nfinal class Foo {}\n",
            'src/Bar.php' => "<?php\nnamespace App;\nuse App\\Foo;\nfinal class Bar {}\n",
            'src/Sub/.keep' => '',
            'src/Old.php/view.php' => "<?php\n", // a folder named like a PHP file
            'notes.txt' => "plain\n",
        ]);
    }

    private static function move(Ide $ide, TempProject $project, string ...$paths): void
    {
        Services::resolve($ide, EventDispatcherInterface::class)->dispatch(new TreeDropRequested(
            \array_map($project->path(...), \array_values($paths)),
            $project->path('src/Sub'),
            action: DropAction::Move,
        ));
    }

    private static function requests(TempProject $project): string
    {
        $log = $project->path('requests.log');

        return \is_file($log) ? (string) \file_get_contents($log) : '';
    }

    public function testTheMovedClassAndWhatUsesItFollow(): void
    {
        $project = self::project('edit');

        $this->runIde(
            $project,
            $project->path('src/Bar.php'),
            [
                100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project): void {
                    $this->awaitLspReady($tui, $terminal, 'file-rename-lsp-server');

                    // Neither is announced: a text file is not one the server
                    // registered for, nor is a folder, even one named like a PHP file.
                    self::move($ide, $project, 'notes.txt', 'src/Old.php');
                    $this->assertFileExists($project->path('src/Sub/notes.txt'));
                    $this->assertStringNotContainsString('willRenameFiles', self::requests($project));

                    self::move($ide, $project, 'src/Foo.php');
                    $this->awaitPainted($tui, $terminal, 'Updated 2 files for the move');
                    $this->assertSame(
                        "<?php\nnamespace App\\Sub;\nfinal class Foo {}\n",
                        (string) \file_get_contents($project->path('src/Sub/Foo.php')),
                        'the moved file, not open, is rewritten on disk',
                    );
                    $bar = Services::resolve($ide, BufferManager::class)->get((string) \realpath($project->path('src/Bar.php')));
                    $this->assertSame("<?php\nnamespace App;\nuse App\\Sub\\Foo;\nfinal class Bar {}\n", $bar?->document->getText(), 'the open one is edited, unsaved');
                    $this->assertSame(1, \substr_count(self::requests($project), needle: 'willRenameFiles'), 'asked once');

                    // One refactoring, taken back whole.
                    Services::resolve($ide, CommandRegistry::class)->run('ide_undo_refactoring');
                    $this->assertSame("<?php\nnamespace App;\nfinal class Foo {}\n", (string) \file_get_contents($project->path('src/Sub/Foo.php')));
                    $this->assertSame("<?php\nnamespace App;\nuse App\\Foo;\nfinal class Bar {}\n", $bar?->document->getText());
                },
            ],
        );
    }

    public function testASilentOrFailingServerLeavesTheMoveAsItIs(): void
    {
        foreach (['none' => null, 'empty' => null, 'error' => 'Foo.php moved;', 'refused' => 'Refused:'] as $mode => $said) {
            $project = self::project($mode);

            $this->runIde(
                $project,
                $project->path('src/Bar.php'),
                [
                    100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $said): void {
                        $this->awaitLspReady($tui, $terminal, 'file-rename-lsp-server');
                        self::move($ide, $project, 'src/Foo.php');
                        $this->await(static fn (): bool => \str_contains(self::requests($project), 'willRenameFiles'), what: 'the server to be asked');
                        if (null !== $said) {
                            $this->awaitPainted($tui, $terminal, $said);
                        }
                        $this->pump(0.2); // a genuine negative: the answer, if any, has landed
                        $this->assertSame("<?php\nnamespace App;\nfinal class Foo {}\n", (string) \file_get_contents($project->path('src/Sub/Foo.php')), 'moved, and nothing else');
                        $this->assertStringNotContainsString('Updated 0', \implode("\n", $this->screenGrid($tui, $terminal)), 'an edit of nothing says nothing');
                    },
                ],
            );
        }
    }

    /**
     * The protocol has the edit applied before the move, so a server may
     * address the moved file by its old path: that edit lands on the file
     * where it now is. One that cannot be written is said, not hidden.
     */
    public function testAnEditForTheOldPathLandsAndOneThatCannotIsSaid(): void
    {
        $moved = [
            'old' => "<?php\nnamespace App\\Sub;\nfinal class Foo {}\n",
            'partial' => "<?php\nnamespace App\\Sub;\nfinal class Foo {}\n",
            'changes' => "<?php\nnamespace App\\Sub;\n// moved\nfinal class Foo {}\n",
        ];
        foreach (['old' => 'Updated 2 files for the move', 'partial' => 'Applied to 1 file, 1 could not be', 'changes' => 'Updated 2 files for the move'] as $mode => $said) {
            $project = self::project($mode);

            $this->runIde(
                $project,
                $project->path('src/Bar.php'),
                [
                    100 => function (Tui $tui, VirtualTerminal $terminal, Ide $ide) use ($project, $said, $moved, $mode): void {
                        $this->awaitLspReady($tui, $terminal, 'file-rename-lsp-server');
                        self::move($ide, $project, 'src/Foo.php');
                        $this->awaitPainted($tui, $terminal, $said);
                        $this->assertSame(
                            $moved[$mode],
                            (string) \file_get_contents($project->path('src/Sub/Foo.php')),
                            'the moved file took its new namespace — every edit of it, whichever path named it',
                        );
                    },
                ],
            );
        }
    }
}
