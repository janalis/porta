<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Scratch project directory for tests; removed on destruct.
 */
final class TempProject
{
    public readonly string $dir;
    private readonly Filesystem $fs;

    /** @param array<string, string> $files relative path => content */
    public function __construct(array $files = [], string $prefix = 'porta-test-')
    {
        $this->fs = new Filesystem();
        $this->dir = sys_get_temp_dir().'/'.$prefix.getmypid().'-'.bin2hex(random_bytes(4));
        $this->fs->mkdir($this->dir);
        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }
    }

    public function write(string $relativePath, string $content): string
    {
        $abs = $this->dir.'/'.$relativePath;
        $this->fs->dumpFile($abs, $content);

        return $abs;
    }

    public function path(string $relativePath = ''): string
    {
        return '' === $relativePath ? $this->dir : $this->dir.'/'.$relativePath;
    }

    public function __destruct()
    {
        $this->fs->remove($this->dir);
    }
}
