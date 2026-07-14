<?php

declare(strict_types=1);

namespace Porta\Tests\Support;

use Porta\Editor\ActiveFileProvider;

/** A canned "current file" for services that ask the editor which file is active. */
final class FixedActiveFile implements ActiveFileProvider
{
    public function __construct(
        private ?string $path = null,
    ) {}

    public function set(?string $path): void
    {
        $this->path = $path;
    }

    public function getFilePath(): ?string
    {
        return $this->path;
    }
}
