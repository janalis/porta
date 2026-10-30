<?php

declare(strict_types=1);

namespace Porta\History;

use Porta\Support\JsonFile;
use Porta\Ui\ScopeKind;
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
    private string $scope;
    /** The label of a SAVED scope when one was last used, else '' — see {@see setSavedScope()}. */
    private string $savedScope;
    private string $mask;
    /** @var list<string> most-recent-first */
    private array $recentMasks;

    public function __construct(
        private readonly string $rootDir,
        private readonly Filesystem $fs = new Filesystem(),
    ) {
        $this->file = $rootDir.'/.porta/search-prefs.json';
        $data = JsonFile::read($this->file) ?? [];
        $this->scope = \is_string($data['scope'] ?? null) ? $data['scope'] : ScopeKind::Project->value;
        $this->savedScope = \is_string($data['savedScope'] ?? null) ? $data['savedScope'] : '';
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

    /** Record the active scope kind (on each Alt+S cycle), and forget any saved one. */
    public function setScope(ScopeKind $scope): void
    {
        $this->scope = $scope->value;
        $this->savedScope = '';
        $this->save();
    }

    /**
     * The label of the last-used SAVED scope, or '' when the last one was a
     * built-in.
     *
     * A saved scope has no {@see ScopeKind} — it carries its own paths — so the
     * kind field cannot hold it, and for as long as it could not, the one scope
     * a user had gone to the trouble of saving and PINNING was the only one the
     * store could not bring back. Its own label is the key, because that is
     * what the scope store keys on and what the selector shows.
     */
    public function lastSavedScope(): string
    {
        return $this->savedScope;
    }

    /** Record a saved scope as the active one; the kind is left as the fallback for when it is gone. */
    public function setSavedScope(string $label): void
    {
        $this->savedScope = $label;
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
        if ('' === \trim($mask)) {
            return;
        }
        $this->recentMasks = \array_values(\array_filter($this->recentMasks, static fn (string $m): bool => $m !== $mask));
        \array_unshift($this->recentMasks, $mask);
        $this->recentMasks = \array_slice($this->recentMasks, offset: 0, length: self::MAX_MASKS);
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
        $masks = \array_values(\array_filter($entries, static fn (mixed $entry): bool => \is_string($entry) && '' !== $entry));

        /** @var list<string> $masks */
        return \array_slice($masks, offset: 0, length: self::MAX_MASKS);
    }

    private function save(): void
    {
        // Best-effort persistence; prefs are non-critical.
        JsonFile::writeInProject($this->fs, $this->rootDir, $this->file, [
            'scope' => $this->scope,
            'savedScope' => $this->savedScope,
            'mask' => $this->mask,
            'recentMasks' => $this->recentMasks,
        ]);
    }
}
