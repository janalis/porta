<?php

declare(strict_types=1);

namespace Porta\Ui;

use Porta\Widget\ResultsPanelFields;
use Porta\Widget\ResultsPanelWidget;
use Symfony\Component\Tui\Tui;

/**
 * The Find Action palette: given a command registry as data, it drives the
 * shared results panel — open, live substring filter, and run-by-index — while
 * the IDE coordinator owns the registry itself and the panel-mode multiplexing.
 */
final class CommandPalette
{
    /** @var list<array{label: string, run: callable(): void}> full registry for this session */
    private array $commands = [];
    /** @var list<array{label: string, run: callable(): void}> current filtered subset */
    private array $filtered = [];

    public function __construct(
        private readonly ResultsPanelWidget $resultsPanel,
        private readonly Tui $tui,
    ) {}

    /**
     * Open the palette over $commands and show them all (empty filter).
     *
     * @param list<array{label: string, run: callable(): void}> $commands
     */
    public function open(array $commands): void
    {
        $this->commands = $commands;
        $this->resultsPanel->open('Find Action', ResultsPanelFields::queryOnly());
        $this->filter('');
        $this->tui->setFocus($this->resultsPanel);
        $this->tui->requestRender();
    }

    /** Filter to commands whose label contains $query (case-insensitive) and render them. */
    public function filter(string $query): void
    {
        $needle = strtolower(trim($query));
        $this->filtered = array_values(array_filter(
            $this->commands,
            static fn (array $c) => '' === $needle || str_contains(strtolower($c['label']), $needle),
        ));

        $results = [];
        foreach ($this->filtered as $index => $command) {
            $results[] = ['path' => '', 'line' => $index, 'text' => $command['label']];
        }
        $this->resultsPanel->setResults($results);
        $this->tui->requestRender();
    }

    /** Run the command at filtered row $index (from the panel's Enter/jump). */
    public function runAt(int $index): void
    {
        $command = $this->filtered[$index] ?? null;
        if (null !== $command) {
            $command['run']();
        }
    }
}
