<?php

declare(strict_types=1);

namespace Porta\Workbench;

use Porta\Config\PortaHome;
use Porta\Filesystem\ProjectLocation;
use Porta\History\RecentProjectsStore;

/** The recent projects worth offering, as a person knows them: shared by the switcher and the desktop picker. */
final readonly class RecentProjectChoices
{
    public function __construct(
        private RecentProjectsStore $recent,
    ) {}

    /**
     * Skips $current, which is not somewhere to go, and any project since moved
     * or deleted, which would answer a keystroke with an error.
     *
     * @return list<array{label: string, hint: string, value: string}>
     */
    public function except(?string $current = null): array
    {
        $items = [];
        foreach ($this->recent->all() as $root) {
            if ($root === $current || !\is_dir($root)) {
                continue;
            }

            // A remote project's root is its cache, a hash deep in ~/.porta:
            // what a person knows it by is the server it mirrors.
            $remote = ProjectLocation::forRoot($root)->remote;
            $items[] = null === $remote
                ? ['label' => \basename($root), 'hint' => PortaHome::tilde($root), 'value' => $root]
                : ['label' => $remote->name(), 'hint' => $remote->label(), 'value' => $root];
        }

        return $items;
    }
}
