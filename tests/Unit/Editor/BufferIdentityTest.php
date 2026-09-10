<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Editor;

use PHPUnit\Framework\TestCase;
use Porta\Editor\BufferManager;
use Porta\Tests\Support\TempProject;

/**
 * One file is one buffer, however its path was spelled.
 *
 * Buffers are keyed by `realpath()`, which looks like a canonical name and is
 * not one: on a case-insensitive filesystem — macOS and Windows, so most
 * developer machines — `realpath()` returns the case it was GIVEN. Ask for
 * `src/ide.php` when the file is `src/Ide.php` and it answers `src/ide.php`,
 * `is_file()` agrees, and the editor opens a second buffer over the same bytes.
 *
 * That is reachable by typing: `porta src/ide.php` on the command line, then
 * reaching the same file through Go to File, which offers the on-disk spelling.
 * Two tabs, two documents, one file — edit both and the second save silently
 * discards the first.
 *
 * Identity is asked of the FILESYSTEM rather than of the string: same device,
 * same inode. That needs no guess about which platform is case-insensitive, it
 * stays correct on one that is not (the two names are different inodes there,
 * so nothing merges), and it answers for hard links too, which are the same
 * question wearing a different hat.
 */
final class BufferIdentityTest extends TestCase
{
    /** Held so the destructor does not remove the fixture mid-test. */
    private TempProject $project;

    public function testTheSameFileUnderTwoSpellingsIsOneBuffer(): void
    {
        $this->project = new TempProject(['Ide.php' => "<?php\n// one\n"]);
        $manager = new BufferManager();
        $stored = $this->project->path('Ide.php');
        $lowered = $this->project->dir.'/ide.php';

        if (!is_file($lowered) || fileinode($stored) !== fileinode($lowered)) {
            $this->markTestSkipped('this filesystem is case-sensitive, so the two names are two files');
        }

        $manager->open($stored, "<?php\n// one\n");
        $manager->open($lowered, "<?php\n// one\n");

        $this->assertCount(1, $manager->all(), 'one file, one buffer');
        $this->assertSame($stored, $manager->all()[0]->path, 'and it keeps the spelling it was opened with first');
    }

    /** Two genuinely different files stay two buffers, whatever they are called. */
    public function testDifferentFilesStayDifferentBuffers(): void
    {
        $this->project = new TempProject(['a.php' => "<?php\n", 'b.php' => "<?php\n"]);
        $manager = new BufferManager();

        $manager->open($this->project->path('a.php'), "<?php\n");
        $manager->open($this->project->path('b.php'), "<?php\n");

        $this->assertCount(2, $manager->all());
    }

    /** A path with no file behind it — a new file — cannot be merged with anything. */
    public function testAPathThatIsNotOnDiskOpensItsOwnBuffer(): void
    {
        $this->project = new TempProject(['real.php' => "<?php\n"]);
        $manager = new BufferManager();

        $manager->open($this->project->path('real.php'), "<?php\n");
        $manager->open($this->project->dir.'/not-created-yet.php', '');

        $this->assertCount(2, $manager->all());
    }
}
