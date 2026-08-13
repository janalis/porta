<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Editor\BufferService;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\SearchActions;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Project-wide search/navigation palette commands. */
#[AsTaggedItem(priority: 300)]
final class SearchCommands implements CommandProvider
{
    public function __construct(
        private readonly SearchActions $searchActions,
        #[Autowire(service: 'porta.go_to_symbol')]
        private readonly SearchEverywhereController $goToSymbol,
        private readonly BufferService $bufferService,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('ide_problems', fn () => $this->searchActions->showProblems(), keyBound: KeyDispatch::Bound)
            ->add('ide_find_in_project', fn () => $this->searchActions->openProjectSearch(), keyBound: KeyDispatch::Bound)
            ->add(
                'ide_goto_symbol',
                function (): void {
                    if ($this->bufferService->ensureTextEditorActive()) {
                        $this->goToSymbol->open();
                    }
                },
                keyBound: KeyDispatch::Bound,
            )
            ->add('ide_goto_test', fn () => $this->searchActions->goToTest(), keyBound: KeyDispatch::Bound);
    }
}
