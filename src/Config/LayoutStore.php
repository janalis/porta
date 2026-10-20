<?php

declare(strict_types=1);

namespace Porta\Config;

use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the user-adjusted pane proportions (sidebar width and bottom-panel
 * height, as percentages of the terminal size, and the editor's share of a
 * preview split) to `<root>/.porta/layout.json`,
 * so a project reopens with the same layout. The file may also be hand-edited.
 */
final readonly class LayoutStore
{
    private string $file;

    public function __construct(
        string $rootDir,
        private Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/layout.json';
    }

    public function save(LayoutPrefs $prefs): bool
    {
        $data = [];
        if (null !== $prefs->sidebarWidthPercent) {
            $data['sidebarWidthPercent'] = $prefs->sidebarWidthPercent;
        }
        if (null !== $prefs->bottomPanelHeightPercent) {
            $data['bottomPanelHeightPercent'] = $prefs->bottomPanelHeightPercent;
        }
        if ([] !== $prefs->viewSidebarWidthPercent) {
            $data['viewSidebarWidthPercent'] = $prefs->viewSidebarWidthPercent;
        }
        if (null !== $prefs->previewSplitPercent) {
            $data['previewSplitPercent'] = $prefs->previewSplitPercent;
        }
        if ([] !== $prefs->viewSplitPercent) {
            $data['viewSplitPercent'] = $prefs->viewSplitPercent;
        }

        return JsonFile::write($this->fs, $this->file, $data);
    }

    /** The persisted prefs, or null when no file exists. */
    public function load(): ?LayoutPrefs
    {
        $data = JsonFile::read($this->file);
        if (null === $data) {
            return null;
        }

        return new LayoutPrefs(
            sidebarWidthPercent: $this->percent($data['sidebarWidthPercent'] ?? null),
            bottomPanelHeightPercent: $this->percent($data['bottomPanelHeightPercent'] ?? null),
            viewSidebarWidthPercent: $this->viewWidths($data),
            previewSplitPercent: $this->percent($data['previewSplitPercent'] ?? null),
            viewSplitPercent: $this->percents($data['viewSplitPercent'] ?? null),
        );
    }

    /**
     * The per-view sidebar widths, adopting the two pre-move keys for a file
     * written before views were contributed.
     *
     * Adopted rather than migrated in place: this file is hand-editable, so the
     * old key keeps working until the next save rewrites the map. A view that
     * has a value under BOTH spellings takes the map's — that is the one the
     * user's last drag wrote.
     *
     * @param array<array-key, mixed> $data
     *
     * @return array<string, int>
     */
    private function viewWidths(array $data): array
    {
        $widths = [];
        foreach (['git' => 'gitSidebarWidthPercent', 'settings' => 'settingsSidebarWidthPercent'] as $id => $legacy) {
            $percent = $this->percent($data[$legacy] ?? null);
            if (null !== $percent) {
                $widths[$id] = $percent;
            }
        }

        return [...$widths, ...$this->percents($data['viewSidebarWidthPercent'] ?? null)];
    }

    /**
     * A hand-editable map of percents, keeping the entries that are one.
     *
     * @return array<string, int>
     */
    private function percents(mixed $map): array
    {
        $percents = [];
        /** @var mixed $value */
        foreach (\is_array($map) ? $map : [] as $key => $value) {
            $percent = $this->percent($value);
            if (\is_string($key) && null !== $percent) {
                $percents[$key] = $percent;
            }
        }

        return $percents;
    }

    private function percent(mixed $value): ?int
    {
        return \is_int($value) || \is_float($value) || (\is_string($value) && \is_numeric($value)) ? (int) $value : null;
    }
}
