<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Search\SearchActions;

/** Project-wide search/navigation palette commands. */
final class SearchCommands implements CommandProvider
{
    public function __construct(
        private readonly SearchActions $searchActions,
    ) {}

    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('ide_problems', fn () => $this->searchActions->showProblems(), keyBound: true)
            ->add('ide_find_in_project', fn () => $this->searchActions->openProjectSearch(), keyBound: true)
            ->add('ide_goto_symbol', fn () => $this->searchActions->openSymbolSearch(), keyBound: true)
            ->add('ide_goto_test', fn () => $this->searchActions->goToTest(), keyBound: true);
    }
}
