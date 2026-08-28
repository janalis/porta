<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;
use Porta\Event\SettingsSectionRequested;
use Porta\I18n\Translator;
use Porta\Search\Everywhere\SearchCategory;
use Porta\Search\Everywhere\SearchHit;
use Porta\Settings\SettingsPage;
use Porta\Settings\SettingsPages;
use Porta\Settings\SettingsRow;
use Porta\Settings\SettingsRowKind;
use Porta\Settings\SettingsSearchIndex;
use Porta\Settings\SettingsSearchSource;
use Porta\Settings\SettingsSection;
use Porta\Workbench\Menu\ViewMenuSource;
use Porta\Workbench\View\ContributedView;
use Porta\Workbench\View\ViewPointer;
use Porta\Workbench\View\ViewRegistry;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;

final class SettingsSearchSourceTest extends TestCase
{
    /** @return list<SearchHit> */
    private function collect(SettingsSearchSource $source, string $query): array
    {
        $hits = null;
        $source->query($query, static function (array $emitted) use (&$hits): void {
            $hits = $emitted;
        });
        \assert(null !== $hits, description: 'the query invoked emit with a batch of hits');

        return $hits;
    }

    /** Records `['open']` then `['event', section, key]`, so the ORDER is readable. */
    private function source(?array &$log = null): SettingsSearchSource
    {
        $log = [];
        $views = new ViewRegistry();
        $views->register($this->view($log));
        $events = new EventDispatcher();
        $events->addListener(SettingsSectionRequested::class, static function (SettingsSectionRequested $e) use (&$log): void {
            $log[] = ['event', $e->section, $e->row];
        });

        return new SettingsSearchSource(
            new SettingsSearchIndex(new SettingsPages([
                $this->section('interface', 'Interface', ['stickyScroll' => 'Sticky scroll']),
                $this->section('lang:php', 'PHP', ['indentStyle' => 'Indent style']),
                $this->section('lang:css', 'CSS', ['indentStyle' => 'Indent style']),
            ]), new Translator()),
            $views,
            $events,
        );
    }

    public function testHitsCarryTheSettingsCategory(): void
    {
        $hits = $this->collect($this->source(), 'sticky');

        $this->assertCount(1, $hits);
        $this->assertSame(SearchCategory::Settings, $hits[0]->category);
    }

    /**
     * The page is the dimmed detail, and it is load-bearing rather than
     * decorative: "Indent style" is the label of thirteen different rows in the
     * shipped catalogue, and the page is the only thing telling them apart.
     */
    public function testTheDetailIsThePageSoIdenticalLabelsStayDistinguishable(): void
    {
        $hits = $this->collect($this->source(), 'indent style');

        $this->assertSame(['Indent style', 'Indent style'], array_map(static fn (SearchHit $h): string => $h->label, $hits));
        $this->assertSame(['PHP', 'CSS'], array_map(static fn (SearchHit $h): string => $h->detail, $hits));
    }

    /**
     * Activation opens the view and then names the row — in that order,
     * because `SettingsViewController::open()` resets to General and would
     * discard the section an earlier event had carried.
     */
    public function testActivatingAHitOpensTheViewThenNamesItsOwnSectionAndRow(): void
    {
        $source = $this->source($log);
        $hits = $this->collect($source, 'css indent');

        ($hits[0]->activate)();

        $this->assertSame([['open'], ['event', 'lang:css', 'indentStyle']], $log);
    }

    /** The picker seeds itself with an empty query, so it must answer one. */
    public function testAnEmptyQueryListsSettingsRatherThanNothing(): void
    {
        $source = $this->source();
        $this->assertNotEmpty($this->collect($source, ''));

        $source->cancel(); // no-op for a synchronous source

        $this->assertNotEmpty($this->collect($source, ''), 'and it still answers afterwards');
    }

    /** A settings view that only records having been opened. */
    private function view(?array &$log): ContributedView
    {
        return new class($log) implements ContributedView {
            public function __construct(
                private ?array &$log,
            ) {}

            #[\Override]
            public function viewId(): string
            {
                return 'settings';
            }

            #[\Override]
            public function ownsMainArea(): bool
            {
                return true;
            }

            #[\Override]
            public function isOpen(): bool
            {
                return [] !== $this->log;
            }

            #[\Override]
            public function open(): void
            {
                $this->log[] = ['open'];
            }

            #[\Override]
            public function close(): void {}

            #[\Override]
            public function toggle(): void {}

            #[\Override]
            public function pointer(): ?ViewPointer
            {
                return null;
            }

            #[\Override]
            public function contextMenus(): ?ViewMenuSource
            {
                return null;
            }

            #[\Override]
            public function focusTarget(): AbstractWidget&FocusableInterface
            {
                throw new \LogicException('the reveal path never focuses this stub');
            }

            #[\Override]
            public function cycleFocus(int $direction): bool
            {
                return false;
            }
        };
    }

    /** @param array<string, string> $rows key => label */
    private function section(string $id, string $label, array $rows): SettingsSection
    {
        return new class($id, $label, $rows) implements SettingsSection {
            /** @param array<string, string> $rows */
            public function __construct(
                private readonly string $id,
                private readonly string $label,
                private readonly array $rows,
            ) {}

            #[\Override]
            public function pages(): array
            {
                return [new SettingsPage($this->id, $this->label)];
            }

            #[\Override]
            public function handles(string $section): bool
            {
                return $section === $this->id;
            }

            #[\Override]
            public function rows(string $section): array
            {
                $rows = [];
                foreach ($this->rows as $key => $label) {
                    $rows[] = new SettingsRow(key: $key, label: $label, kind: SettingsRowKind::Bool, value: false, restart: false);
                }

                return $rows;
            }

            #[\Override]
            public function change(string $section, string $key, bool|int|string $value): bool
            {
                return true;
            }

            #[\Override]
            public function reset(string $section, string $key): void {}
        };
    }
}
