<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Porta\Support\ComposerLayout;
use Porta\Tests\Support\TempProject;

/** Where Composer puts a project's packages and binaries, as Composer itself resolves it. */
final class ComposerLayoutTest extends TestCase
{
    /** @return iterable<string, array{string|null, string, string}> */
    public static function layouts(): iterable
    {
        yield 'no composer.json' => [null, 'vendor', 'vendor/bin'];
        yield 'no config' => ['{"name":"a/b"}', 'vendor', 'vendor/bin'];
        yield 'not JSON' => ['{', 'vendor', 'vendor/bin'];
        yield 'config not an object' => ['{"config":"lib"}', 'vendor', 'vendor/bin'];
        yield 'a blank or non-string dir is no dir' => ['{"config":{"vendor-dir":"  ","bin-dir":7}}', 'vendor', 'vendor/bin'];
        yield 'bin-dir set' => ['{"config":{"bin-dir":"bin"}}', 'vendor', 'bin'];
        yield 'the default bin-dir follows vendor-dir' => ['{"config":{"vendor-dir":"lib/deps"}}', 'lib/deps', 'lib/deps/bin'];
        yield '{$vendor-dir} in bin-dir' => ['{"config":{"vendor-dir":"lib","bin-dir":"{$vendor-dir}/tools"}}', 'lib', 'lib/tools'];
        yield 'spelled loosely' => ['{"config":{"vendor-dir":" ./lib/ ","bin-dir":"lib/../bin/"}}', 'lib', 'bin'];
    }

    #[DataProvider('layouts')]
    public function testTheLayoutAComposerJsonGives(?string $json, string $vendor, string $bin): void
    {
        $project = new TempProject(null === $json ? [] : ['composer.json' => $json]);

        $layout = ComposerLayout::of($project->dir);

        $this->assertSame($project->path($vendor), $layout->vendorDir);
        $this->assertSame($project->path($bin), $layout->binDir);
    }

    public function testAnAbsoluteDirIsKeptAsItIs(): void
    {
        $project = new TempProject(['composer.json' => '{"config":{"vendor-dir":"/opt/deps","bin-dir":"/opt/bin"}}']);

        $layout = ComposerLayout::of($project->dir);

        $this->assertSame('/opt/deps', $layout->vendorDir);
        $this->assertSame('/opt/bin', $layout->binDir);
    }
}
