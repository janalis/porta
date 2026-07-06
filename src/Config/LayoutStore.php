<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the user-adjusted pane proportions (sidebar width and bottom-panel
 * height, as percentages of the terminal size) to `<root>/.porta/layout.json`,
 * so a project reopens with the same layout. The file may also be hand-edited.
 */
final readonly class LayoutStore
{
    private string $file;
    private Filesystem $fs;

    public function __construct(string $rootDir)
    {
        $this->file = $rootDir.'/.porta/layout.json';
        $this->fs = new Filesystem();
    }

    public function save(?int $sidebarWidthPercent, ?int $bottomPanelHeightPercent): bool
    {
        $data = [];
        if (null !== $sidebarWidthPercent) {
            $data['sidebarWidthPercent'] = $sidebarWidthPercent;
        }
        if (null !== $bottomPanelHeightPercent) {
            $data['bottomPanelHeightPercent'] = $bottomPanelHeightPercent;
        }

        try {
            $this->fs->dumpFile(
                $this->file,
                json_encode($data, JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
            );
        } catch (IOExceptionInterface) {
            return false;
        }

        return true;
    }

    /**
     * @return array{sidebarWidthPercent: ?int, bottomPanelHeightPercent: ?int}|null null when no file exists
     */
    public function load(): ?array
    {
        $data = JsonFile::read($this->file);
        if (null === $data) {
            return null;
        }

        return [
            'sidebarWidthPercent' => $this->percent($data['sidebarWidthPercent'] ?? null),
            'bottomPanelHeightPercent' => $this->percent($data['bottomPanelHeightPercent'] ?? null),
        ];
    }

    private function percent(mixed $value): ?int
    {
        return \is_int($value) || \is_float($value) || \is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
