<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Config\CommandRegistry;
use Porta\Config\RecommendedPlugin;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Plugin\Lifecycle\PluginEnablement;
use Porta\Plugin\Tool\ToolCatalog;
use Porta\Plugin\Tool\ToolJobs;
use Porta\Plugin\Tool\ToolManager;
use Porta\Plugin\Tool\ToolStatus;

/**
 * The setup steps the Welcome screen offers.
 *
 * Every step delegates to whatever already owns that action, so there is one
 * implementation of each: enabling the bundled highlighting engine is the same
 * call Settings → Highlighting makes, and enabling a plugin is the same call
 * Settings → Plugins makes. This screen is a *route* to those actions, never a
 * second copy of them.
 *
 * Steps are rebuilt on each open, so a project that is already configured shows
 * them all done rather than prompting again.
 *
 * A plugin card is done when the plugin is enabled AND the tools it requires
 * are there: an enabled formatter with no formatter is a ticked step the user
 * cannot use. Pressing one enables the plugin and queues those tools' install
 * — the machine-wide ones; composer's is the Settings page's.
 */
final readonly class WelcomeSteps
{
    public function __construct(
        private Translator $translator,
        private SyntaxHighlightProviderSource $highlighters,
        private PluginEnablement $lifecycle,
        private CommandRegistry $commands,
        private MagoDetector $mago,
        private SymfonyDetector $symfony,
        private ToolCatalog $tools,
        private ToolJobs $jobs,
    ) {}

    /** @return list<WelcomeStep> */
    public function all(): array
    {
        // Re-detected on every open (steps are rebuilt each time), so a
        // `composer require` mid-session is picked up by the next open.
        $installed = $this->mago->installed();
        $fixer = $installed ? RecommendedPlugin::BothTools : RecommendedPlugin::Formatter;
        $analyzer = $installed ? RecommendedPlugin::BothTools : RecommendedPlugin::Analyzer;

        return [
            new WelcomeStep(
                $this->translator->trans('Syntax highlighting'),
                // What the feature IS, never whether it is on: the checkbox
                // beside it already says that, and a description claiming "off
                // until enabled" contradicts its own [✓] the moment the step is
                // done — which is the first line a returning user reads.
                $this->translator->trans('Colours your code'),
                $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugin::Highlighter->value]),
                // Asking the source directly is one call; going through the
                // registry would mean probing every grammar against every
                // provider, because its fallback always claims a language.
                fn (): bool => [] !== $this->highlighters->highlightProviders(),
                function (): void {
                    $this->lifecycle->enable(RecommendedPlugin::Highlighter->value);
                },
            ),
            new WelcomeStep(
                $this->translator->trans('PHP language server'),
                $this->translator->trans('Completion, go-to-definition and diagnostics'),
                $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugin::LanguageServer->value]),
                fn (): bool => $this->isReady(RecommendedPlugin::LanguageServer),
                function (): void {
                    $this->enable(RecommendedPlugin::LanguageServer);
                },
            ),
            // Only on a project that IS a Symfony application — the card would
            // be noise anywhere else, and the server's features (routes,
            // container, Twig) only exist against the framework.
            ...(
                $this->symfony->isSymfonyProject()
                    ? [new WelcomeStep(
                        $this->translator->trans('Symfony language tools'),
                        $this->translator->trans('Routes, services, Twig and config completion for this Symfony app'),
                        $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugin::SymfonyLanguageServer->value]),
                        fn (): bool => $this->isReady(RecommendedPlugin::SymfonyLanguageServer),
                        function (): void {
                            $this->enable(RecommendedPlugin::SymfonyLanguageServer);
                        },
                    )] : []
            ),
            // Enable-only: the plugin's format-on-save toggle stays where it
            // lives (its settings page) — flipping save behaviour from an
            // onboarding shortcut would be a change the user never asked for.
            new WelcomeStep(
                $this->translator->trans('Code style fixer'),
                $this->translator->trans('%name% fixes formatting on demand; format-on-save lives in its settings', ['%name%' => $fixer->value]),
                $this->translator->trans('Enable %name%', ['%name%' => $fixer->value]),
                fn (): bool => $this->isReady($fixer),
                function () use ($fixer): void {
                    $this->enable($fixer);
                },
            ),
            new WelcomeStep(
                $this->translator->trans('Static analysis'),
                $this->translator->trans('%name% finds bugs without running the code; analyse-on-save in its settings', ['%name%' => $analyzer->value]),
                $this->translator->trans('Enable %name%', ['%name%' => $analyzer->value]),
                fn (): bool => $this->isReady($analyzer),
                function () use ($analyzer): void {
                    $this->enable($analyzer);
                },
            ),
            // A link step (null predicate): `ide_help` already closes every
            // view before opening the guide, which is exactly the ordering
            // running it from inside this view needs.
            new WelcomeStep(
                $this->translator->trans('Read the guide'),
                $this->translator->trans('Keys, panels and workflows, in the built-in help viewer'),
                $this->translator->trans('Open the guide'),
                null,
                function (): void {
                    $this->commands->run('ide_help');
                },
            ),
        ];
    }

    /**
     * A bundled plugin is installed but off until opted into, so "enabled"
     * means listed *and* not disabled.
     */
    private function isEnabled(RecommendedPlugin $plugin): bool
    {
        return \in_array($plugin->value, $this->lifecycle->listedNames(), strict: true) && !$this->lifecycle->isDisabled($plugin->value);
    }

    /** Enabled, and nothing it requires is missing. */
    private function isReady(RecommendedPlugin $plugin): bool
    {
        return $this->isEnabled($plugin) && [] === $this->blocking($plugin);
    }

    /**
     * Enable $plugin, then queue whatever it requires that is missing — but
     * never through composer: `composer require` edits the PROJECT, and a
     * card whose button says "Enable" must not do that behind it. That
     * install lives on the plugin's Settings page, where composer is named.
     */
    private function enable(RecommendedPlugin $plugin): void
    {
        $this->lifecycle->enable($plugin->value);
        $this->jobs->installMissing([$plugin->value], ToolManager::Composer);
    }

    /** @return list<ToolStatus> the required tools of $plugin that are not there */
    private function blocking(RecommendedPlugin $plugin): array
    {
        return \array_values(\array_filter($this->tools->statuses($plugin->value), static fn (ToolStatus $status): bool => $status->blocks()));
    }
}
