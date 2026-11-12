<?php

declare(strict_types=1);

namespace Porta\Install;

use Porta\Config\PortaHome;
use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/** Machine-local cadence note, separate from the project's configuration. */
#[Autoconfigure(constructor: 'forHome')]
final readonly class IdeUpdateState
{
    public function __construct(
        private ?string $file,
        private Filesystem $filesystem = new Filesystem(),
    ) {}

    public static function forHome(): self
    {
        $home = PortaHome::base();

        return new self(null === $home ? null : $home.'/.porta/ide-updates.json');
    }

    public function due(int $now, int $every): bool
    {
        $last = null === $this->file ? null : JsonFile::read($this->file);
        /** @var mixed $checked */
        $checked = $last['checkedAt'] ?? null;

        return null !== $this->file && (!\is_int($checked) || $checked > $now || ($now - $checked) >= $every);
    }

    public function latest(): ?string
    {
        $data = null === $this->file ? null : JsonFile::read($this->file);

        return \is_string($data['latest'] ?? null) ? $data['latest'] : null;
    }

    public function checked(int $now, ?string $latest = null): void
    {
        if (null !== $this->file) {
            JsonFile::write($this->filesystem, $this->file, ['checkedAt' => $now, 'latest' => $latest ?? $this->latest()]);
        }
    }
}
