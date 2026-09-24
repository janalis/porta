<?php

declare(strict_types=1);

namespace Porta\Command;

use Porta\Config\CommandRegistry;
use Porta\Config\KeyDispatch;
use Porta\Editor\BufferService;
use Porta\Editor\ChangeNavigation;
use Porta\Editor\DiagnosticNavigation;
use Porta\Editor\MemberNavigation;
use Porta\Lsp\NavigationController;
use Porta\Search\Everywhere\SearchEverywhereController;
use Porta\Search\SearchActions;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Project-wide search/navigation palette commands. */
#[AsTaggedItem(priority: 300)]
final readonly class SearchCommands implements CommandProvider
{
    public function __construct(
        private SearchActions $searchActions,
        private DiagnosticNavigation $diagnostics,
        private MemberNavigation $members,
        private ChangeNavigation $changes,
        #[Autowire(service: 'porta.go_to_symbol')]
        private SearchEverywhereController $goToSymbol,
        private BufferService $bufferService,
        private NavigationController $navigation,
    ) {}

    #[\Override]
    public function register(CommandRegistry $commands): void
    {
        $commands
            ->add('ide_problems', fn () => $this->searchActions->showProblems(), keyBound: KeyDispatch::Bound)
            ->add('next_problem', fn () => $this->diagnostics->next(), keyBound: KeyDispatch::Bound)
            ->add('prev_problem', fn () => $this->diagnostics->previous(), keyBound: KeyDispatch::Bound)
            ->add('next_member', fn () => $this->members->next(), keyBound: KeyDispatch::Bound)
            ->add('prev_member', fn () => $this->members->previous(), keyBound: KeyDispatch::Bound)
            ->add('next_change', fn () => $this->changes->next(), keyBound: KeyDispatch::Bound)
            ->add('prev_change', fn () => $this->changes->previous(), keyBound: KeyDispatch::Bound)
            ->add('ide_structure', fn () => $this->navigation->requestStructurePanel(), keyBound: KeyDispatch::Bound)
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
            ->add('ide_goto_test', fn () => $this->searchActions->goToTest(), keyBound: KeyDispatch::Bound)
            ->add('ide_goto_super', fn () => $this->searchActions->goToSuperMethod(), keyBound: KeyDispatch::Bound);
    }
}
