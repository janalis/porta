<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Porta\Ui\ScopeKind;
use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Persists the find-in-project scope and file mask to
 * `<root>/.porta/search-prefs.json` so a reopened panel restores the last-used
 * scope + mask, and remembers recently committed masks (most-recent-first,
 * deduped and capped) for Alt+↑/↓ recall on the Mask field.
 */
final class SearchPrefsStore
{
    private const int MAX_MASKS = 20;

    private readonly string $file;
    private readonly Filesystem $fs;
    private string $scope;
    private string $mask;
    /** @var list<string> most-recent-first */
    private array $recentMasks;

    public function __construct(string $rootDir)
    {
        $this->file = $rootDir.'/.porta/search-prefs.json';
        $this->fs = new Filesystem();
        $data = JsonFile::read($this->file) ?? [];
        $this->scope = \is_string($data['scope'] ?? null) ? $data['scope'] : ScopeKind::Project->value;
        $this->mask = \is_string($data['mask'] ?? null) ? $data['mask'] : '';
        $this->recentMasks = $this->loadMasks($data['recentMasks'] ?? null);
    }

    /** The last-used scope kind (defaults to the whole project). */
    public function lastScope(): ScopeKind
    {
        return ScopeKind::tryFrom($this->scope) ?? ScopeKind::Project;
    }

    public function lastMask(): string
    {
        return $this->mask;
    }

    /** @return list<string> most-recent-first */
    public function recentMasks(): array
    {
        return $this->recentMasks;
    }

    /** Record the active scope kind (on each Alt+S cycle). */
    public function setScope(ScopeKind $scope): void
    {
        $this->scope = $scope->value;
        $this->save();
    }

    /** Record the live mask value (on each edit), so the next open restores it. */
    public function setMask(string $mask): void
    {
        $this->mask = $mask;
        $this->save();
    }

    /** Promote a committed mask to the most-recent recall list (on panel close). */
    public function addRecentMask(string $mask): void
    {
        if ('' === trim($mask)) {
            return;
        }
        $this->recentMasks = array_values(array_filter($this->recentMasks, static fn (string $m): bool => $m !== $mask));
        array_unshift($this->recentMasks, $mask);
        $this->recentMasks = \array_slice($this->recentMasks, 0, self::MAX_MASKS);
        $this->save();
    }

    /**
     * @param mixed $entries the raw JSON value under `recentMasks`
     *
     * @return list<string>
     */
    private function loadMasks(mixed $entries): array
    {
        if (!\is_array($entries)) {
            return [];
        }
        $masks = array_values(array_filter($entries, static fn (mixed $entry): bool => \is_string($entry) && '' !== $entry));

        /** @var list<string> $masks */
        return \array_slice($masks, 0, self::MAX_MASKS);
    }

    private function save(): void
    {
        try {
            $this->fs->dumpFile(
                $this->file,
                json_encode(
                    [
                        'scope' => $this->scope,
                        'mask' => $this->mask,
                        'recentMasks' => $this->recentMasks,
                    ],
                    JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
                ),
            );
        } catch (IOExceptionInterface) {
            // best-effort persistence; prefs are non-critical
        }
    }
}
