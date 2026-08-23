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

    public function testLoadDefaultsMissingLineColAndStringifiesTypedOnes(): void
    {
        // The invalid entry comes FIRST — a loop that wrongly stops scanning
        // (instead of skipping just that one) would drop everything after
        // it. The second entry has no line/col at all (exercises the `?? 0`
        // default's exact value); the third has them as numeric STRINGS
        // (exercises the (int) cast — a missing cast would leak a string
        // through instead of an int).
        $project = new TempProject();
        if (!is_dir($project->path('.porta'))) {
            mkdir($project->path('.porta'), permissions: 0o777, recursive: true);
        }
        file_put_contents(
            $project->path('.porta/session.json'),
            json_encode([
                'buffers' => [
                    ['noPath' => true],
                    ['path' => '/p/a.php'],
                    ['path' => '/p/b.php', 'line' => '9', 'col' => '3'],
                ],
                'active' => null,
            ]),
        );

        $loaded = new SessionStore($project->dir)->load();
        $this->assertSame(
            [
                ['path' => '/p/a.php', 'line' => 0, 'col' => 0],
                ['path' => '/p/b.php', 'line' => 9, 'col' => 3],
            ],
            $loaded['buffers'],
        );
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

    public function testAnUnencodablePayloadDegradesToFalseInsteadOfThrowing(): void
    {
        // A path can carry invalid UTF-8 (byte-wise edits produce them), and
        // json_encode(JSON_THROW_ON_ERROR) then throws where dumpFile never
        // ran — only the widened catch turns that into the same best-effort
        // false an IO error produces.
        $project = new TempProject();

        $saved = new SessionStore($project->dir)->save([['path' => "/p/\xB1.php", 'line' => 1, 'col' => 0]], null);

        $this->assertFalse($saved);
        $this->assertFalse(is_file($project->path('.porta/session.json')), 'nothing half-written');
    }
}
