<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;
use Porta\Tests\Support\DocsTree;
use Porta\Tests\Support\Screenshots\Screenshots;

/**
 * The docs' screenshots are drawn at docs build, never committed, so nothing on
 * disk says a page's image exists — a name nobody draws is a 404 on the site,
 * silently. These hold the pages, the {@see Screenshots} list and the
 * scenarios that draw them to one another.
 */
final class DocsScreenshotsTest extends TestCase
{
    /** @return array<string, list<string>> screenshot name => the pages that show it */
    private static function references(): array
    {
        $references = [];
        foreach (DocsTree::pages() as $page) {
            $matches = [];
            \preg_match_all('~!\[[^\]]+\]\(/screenshots/([a-z0-9-]+)\.svg\)~', (string) \file_get_contents($page), $matches);
            foreach ($matches[1] as $name) {
                $references[$name][] = $page;
            }
        }

        return $references;
    }

    public function testEveryScreenshotAPageShowsIsDrawn(): void
    {
        $references = self::references();

        // Asserted first: a regex that stopped matching would find nothing undrawn.
        $this->assertGreaterThanOrEqual(\count(Screenshots::names()), \count($references, \COUNT_RECURSIVE) - \count($references), 'the references were read');
        $this->assertSame([], \array_values(\array_diff(\array_keys($references), Screenshots::names())), 'a page shows a screenshot no scenario draws');
    }

    public function testEveryScreenshotIsShownByAPage(): void
    {
        $this->assertSame([], \array_values(\array_diff(Screenshots::names(), \array_keys(self::references()))), 'a screenshot is drawn for no page');
    }

    public function testEveryPluginGuideShowsItsPlugin(): void
    {
        $references = self::references();
        $missing = [];
        foreach (DocsTree::plugins() as $plugin => $docs) {
            $guide = $docs.'/README.md';
            if (\is_file($guide) && !\in_array($guide, $references['plugin-'.$plugin] ?? [], strict: true)) {
                $missing[] = $plugin;
            }
        }

        $this->assertSame([], $missing, 'a bundled plugin\'s guide opens without its screenshot');
    }

    public function testEverySceneHasAScenario(): void
    {
        $sources = '';
        $files = \glob(\dirname(__DIR__, levels: 2).'/E2e/Screenshots/*.php');
        foreach (false === $files ? [] : $files as $file) {
            $sources .= \file_get_contents($file);
        }
        $taken = [];
        \preg_match_all("~->screenshot\\('([a-z0-9-]+)'~", $sources, $taken);

        $this->assertSame([], \array_values(\array_diff(Screenshots::SCENES, $taken[1])), 'a scene no scenario takes');
    }
}
