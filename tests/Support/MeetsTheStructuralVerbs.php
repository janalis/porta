<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Filesystem\RemoteTransport;

/**
 * {@see ExercisesTransport}'s structural verbs as tests of their own, for a
 * transport over a fake server: each verb its own test, since a fake server is
 * a process per command and one test running them all came near the
 * ten-second limit on a loaded box.
 */
trait MeetsTheStructuralVerbs
{
    use ExercisesTransport;

    /** The transport under test. */
    abstract private function transportUnderTest(): RemoteTransport;

    /** The server it talks to, holding `README.md` and `src/App.php`. */
    abstract private function serverUnderTest(): TempProject;

    public function testDirectoriesAreMade(): void
    {
        $this->assertDirectoriesAreMade($this->transportUnderTest(), $this->serverUnderTest());
    }

    public function testADirectoryIsMoved(): void
    {
        $this->assertADirectoryIsMoved($this->transportUnderTest(), $this->serverUnderTest());
    }

    public function testDirectoriesAreRemovedWithWhatTheyHold(): void
    {
        $this->assertDirectoriesAreRemovedWithWhatTheyHold($this->transportUnderTest(), $this->serverUnderTest());
    }
}
