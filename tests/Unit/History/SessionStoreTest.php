<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\History;

use PHPUnit\Framework\TestCase;
use Porta\History\SessionStore;
use Porta\Tests\Support\TempProject;

final class SessionStoreTest extends TestCase
{
    public function testSaveAndLoadRoundTrip(): void
    {
        $project = new TempProject();
        $store = new SessionStore($project->dir);

        $buffers = [
            ['path' => '/p/a.php', 'line' => 3, 'col' => 5],
            ['path' => '/p/b.php', 'line' => 0, 'col' => 0],
        ];
        $this->assertTrue($store->save($buffers, '/p/b.php'));

        $loaded = $store->load();
        $this->assertSame($buffers, $loaded['buffers']);
        $this->assertSame('/p/b.php', $loaded['active']);
    }

    public function testLoadWhenNoSessionReturnsNull(): void
    {
        $project = new TempProject();
        $this->assertNull(new SessionStore($project->dir)->load());
    }

    public function testEmptyBufferListRemovesTheFile(): void
    {
        $project = new TempProject();
        $store = new SessionStore($project->dir);
        $store->save([['path' => '/p/a.php', 'line' => 0, 'col' => 0]], '/p/a.php');
        $this->assertNotNull($store->load());

        $this->assertTrue($store->save([], null));
        $this->assertNull($store->load());
    }

    public function testSaveFailsWhenDirCannotBeCreated(): void
    {
        $project = new TempProject();
        // Make .porta a file so mkdir of the session dir fails.
        file_put_contents($project->path('.porta'), data: 'not a dir');

        $store = new SessionStore($project->dir);
        $this->assertFalse($store->save([['path' => '/p/a.php', 'line' => 0, 'col' => 0]], '/p/a.php'));
    }

    public function testLoadIgnoresCorruptSession(): void
    {
        $project = new TempProject();
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('.porta/session.json'), data: '{not json');

        $this->assertNull(new SessionStore($project->dir)->load());
    }

    public function testLoadRejectsValidJsonWithoutBuffers(): void
    {
        // Valid JSON but not a session shape (no buffers array).
        $project = new TempProject();
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents($project->path('.porta/session.json'), data: '{"active": "x"}');

        $this->assertNull(new SessionStore($project->dir)->load());
    }

    public function testLoadSkipsMalformedBufferEntries(): void
    {
        $project = new TempProject();
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents(
            $project->path('.porta/session.json'),
            json_encode([
                'buffers' => [
                    ['path' => '/p/ok.php', 'line' => 2],
                    ['noPath' => true],
                    'not an array',
                ],
                'active' => 42, // wrong type -> null
            ]),
        );

        $loaded = new SessionStore($project->dir)->load();
        $this->assertCount(1, $loaded['buffers']);
        $this->assertSame(['path' => '/p/ok.php', 'line' => 2, 'col' => 0], $loaded['buffers'][0]);
        $this->assertNull($loaded['active']);
    }
}
