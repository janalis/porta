<?php

declare(strict_types=1);

namespace Porta\GraphViewer\Mermaid;

use Porta\GraphViewer\Graph\GraphFont;
use Porta\Support\Files;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/** FreeType needs a real file, even when Porta and its font live inside a PHAR. */
final class MermaidFont
{
    public const string FILE = __DIR__.'/../../resources/fonts/IBMPlexMono-Regular.ttf';

    private ?string $temporary = null;

    public function __construct(
        private readonly string $source = self::FILE,
        private readonly Filesystem $files = new Filesystem(),
    ) {}

    public function path(): string
    {
        return \str_starts_with($this->source, 'phar://') ? $this->temporary ?? $this->extract() : $this->source;
    }

    public static function file(GraphFont $face, string $source = self::FILE): string
    {
        return \str_replace('-Regular.ttf', '-'.$face->value.'.ttf', $source);
    }

    private function extract(): string
    {
        $bytes = Files::read($this->source);
        if ('' === $bytes) {
            throw new \InvalidArgumentException('Cannot read the bundled Mermaid font');
        }
        try {
            $this->temporary = $this->files->tempnam(\sys_get_temp_dir(), prefix: 'porta-mermaid-font-');
            $this->files->dumpFile($this->temporary, $bytes);
        } catch (IOExceptionInterface $exception) {
            throw new \InvalidArgumentException('Cannot prepare the bundled Mermaid font: '.$exception->getMessage(), previous: $exception);
        }

        return $this->temporary;
    }

    public function close(): void
    {
        if (null !== $this->temporary) {
            $this->files->remove($this->temporary);
            $this->temporary = null;
        }
    }
}
