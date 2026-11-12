<?php

declare(strict_types=1);

namespace Porta\Console;

use Porta\Install\PortaBinary;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/** Prepare a dependency-free helper before any installed files change. */
final readonly class RestartLauncher
{
    /**
     * @param list<string> $launch
     * @param list<string> $update
     */
    public function prepare(string $directory, string $root, array $launch, array $update, string $lock): ?string
    {
        $filesystem = new Filesystem();
        $helper = $directory.'/restart.php';
        $filesystem->copy(PortaBinary::packageDir().'/bin/porta-restart', $helper);
        $written = JsonFile::write($filesystem, $directory.'/launch.json', [
            'root' => $root,
            'directory' => $directory,
            'launch' => $launch,
            'update' => $update,
            'lock' => $lock,
        ]);

        return $written ? $helper : null;
    }
}
