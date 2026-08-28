<?php

declare(strict_types=1);

namespace Porta\Settings;

use Porta\Container\AsIdeService;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * The settings pages, resolved across every registered section.
 *
 * {@see SettingsViewController} walked the tagged section list itself to answer
 * "who owns this nav id?", and anything else needing the same answer — a search
 * over every page, say — would have had to walk it again. One first-match-wins
 * loop, in one place.
 *
 * Consuming the tag as an iterator keeps the priority contract: the general
 * page claims every id as the fallback, so it must sort last.
 */
#[AsIdeService]
final readonly class SettingsPages
{
    /**
     * @param iterable<SettingsSection> $sections in tag-priority order, the general fallback last
     */
    public function __construct(
        #[AutowireIterator('porta.settings_section')]
        private iterable $sections,
    ) {}

    /**
     * Every page, section by section, in nav order.
     *
     * @return list<SettingsPage>
     */
    public function all(): array
    {
        $pages = [];
        foreach ($this->sections as $section) {
            foreach ($section->pages() as $page) {
                $pages[] = $page;
            }
        }

        return $pages;
    }

    /** The section owning $id — the general fallback claims anything unmatched. */
    public function sectionFor(string $id): SettingsSection
    {
        foreach ($this->sections as $section) {
            if ($section->handles($id)) {
                return $section;
            }
        }

        throw new \LogicException('No settings section claimed '.$id);
    }
}
