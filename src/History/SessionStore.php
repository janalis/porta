<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the open-buffer set and cursor positions to
 * `<root>/.porta/session.json`, so a project reopens where it was left.
 */
#[AsIdeService]
final readonly class SessionStore
{
    private string $file;

    public function __construct(
        string $rootDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/session.json';
    }

    /**
     * @param list<array{path: string, line: int, col: int}> $buffers
     */
    public function save(array $buffers, ?string $activePath): bool
    {
        if ([] === $buffers) {
            $this->fs->remove($this->file);

            return true;
        }

        return JsonFile::write($this->fs, $this->file, ['buffers' => $buffers, 'active' => $activePath]);
    }

    /**
     * @return array{buffers: list<array{path: string, line: int, col: int}>, active: ?string}|null
     */
    public function load(): ?array
    {
        $data = JsonFile::read($this->file);
        if (null === $data || !\is_array($data['buffers'] ?? null)) {
            return null;
        }

        $buffers = [];
        /** @var list<array<string, mixed>|scalar|null> $entries */
        $entries = $data['buffers'];
        foreach ($entries as $buffer) {
            if (!(\is_array($buffer) && \is_string($buffer['path'] ?? null))) {
                continue;
            }

            $buffers[] = [
                'path' => $buffer['path'],
                'line' => (int) ($buffer['line'] ?? 0),
                'col' => (int) ($buffer['col'] ?? 0),
            ];
        }

        return ['buffers' => $buffers, 'active' => \is_string($data['active'] ?? null) ? $data['active'] : null];
    }
}
