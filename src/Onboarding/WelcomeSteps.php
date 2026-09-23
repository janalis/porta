<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Config\CommandRegistry;
use Porta\Config\RecommendedPlugins;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Plugin\Api\PluginApi;
use Porta\Plugin\Lifecycle\PluginEnablement;
use Porta\Plugin\Runtime\ToolInstallOffers;

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
        private ToolInstallOffers $offers,
    ) {}

    /** @return list<WelcomeStep> */
    public function all(): array
    {
        // Re-detected on every open (steps are rebuilt each time), so a
        // `composer require` mid-session is picked up by the next open.
        $installed = $this->mago->installed();
        $fixer = $installed ? RecommendedPlugins::BOTH_TOOLS : RecommendedPlugins::FORMATTER;
        $analyzer = $installed ? RecommendedPlugins::BOTH_TOOLS : RecommendedPlugins::ANALYZER;

        return [
            new WelcomeStep(
                $this->translator->trans('Syntax highlighting'),
                // What the feature IS, never whether it is on: the checkbox
                // beside it already says that, and a description claiming "off
                // until enabled" contradicts its own [x] the moment the step is
                // done — which is the first line a returning user reads.
                $this->translator->trans('Colours your code'),
                $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugins::HIGHLIGHTER]),
                // Asking the source directly is one call; going through the
                // registry would mean probing every grammar against every
                // provider, because its fallback always claims a language.
                fn (): bool => [] !== $this->highlighters->highlightProviders(),
                function (): void {
                    $this->lifecycle->enable(RecommendedPlugins::HIGHLIGHTER);
                },
            ),
            new WelcomeStep(
                $this->translator->trans('PHP language server'),
                $this->translator->trans('Completion, go-to-definition and diagnostics'),
                $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugins::LANGUAGE_SERVER]),
                fn (): bool => $this->isEnabled(RecommendedPlugins::LANGUAGE_SERVER),
                function (): void {
                    $this->lifecycle->enable(RecommendedPlugins::LANGUAGE_SERVER);
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
                        $this->translator->trans('Enable %name%', ['%name%' => RecommendedPlugins::SYMFONY_LANGUAGE_SERVER]),
                        // Enabled is NOT done here, unlike every other card.
                        // This server ships only as a GitHub release, so an
                        // enabled plugin with no binary is a ticked step and a
                        // status bar reading `LSP:none` — the card claiming
                        // credit for something the user cannot yet use.
                        fn (): bool => $this->isEnabled(RecommendedPlugins::SYMFONY_LANGUAGE_SERVER) && $this->toolReady(RecommendedPlugins::SYMFONY_LANGUAGE_SERVER),
                        function (): void {
                            $this->lifecycle->enable(RecommendedPlugins::SYMFONY_LANGUAGE_SERVER);
                            $this->installServerFor(RecommendedPlugins::SYMFONY_LANGUAGE_SERVER);
                        },
                    )] : []
            ),
            // Enable-only: the plugin's format-on-save toggle stays where it
            // lives (its settings page) — flipping save behaviour from an
            // onboarding shortcut would be a change the user never asked for.
            new WelcomeStep(
                $this->translator->trans('Code style fixer'),
                $this->translator->trans('%name% fixes formatting on demand; format-on-save lives in its settings', ['%name%' => $fixer]),
                $this->translator->trans('Enable %name%', ['%name%' => $fixer]),
                fn (): bool => $this->isEnabled($fixer),
                function () use ($fixer): void {
                    $this->lifecycle->enable($fixer);
                },
            ),
            new WelcomeStep(
                $this->translator->trans('Static analysis'),
                $this->translator->trans('%name% finds bugs without running the code; analyse-on-save in its settings', ['%name%' => $analyzer]),
                $this->translator->trans('Enable %name%', ['%name%' => $analyzer]),
                fn (): bool => $this->isEnabled($analyzer),
                function () use ($analyzer): void {
                    $this->lifecycle->enable($analyzer);
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
    private function isEnabled(string $plugin): bool
    {
        return \in_array($plugin, $this->lifecycle->listedNames(), strict: true) && !$this->lifecycle->isDisabled($plugin);
    }

    /**
     * Whether $plugin's external tool is there.
     *
     * A disabled plugin has not loaded and declares no offer, which reads as
     * "not ready" — correct, and the reason this is only ever ANDed with
     * {@see isEnabled()} rather than replacing it. A plugin that declares no
     * offer at all has no tool to wait for, so it is ready by default.
     */
    private function toolReady(string $plugin): bool
    {
        $offer = $this->offers->installOffer($plugin);

        return null === $offer || $offer['available'];
    }

    /**
     * Run $plugin's own install command, if it has one and its tool is not
     * already there.
     *
     * Reached straight after `enable()`, which activates the plugin — and
     * therefore registers its commands — synchronously, unless the plugin
     * needs a composer install first. When it does, activation finishes later
     * in a subprocess callback and there is no command to run yet; the card
     * simply stays unticked, and pressing it again installs. Silently doing
     * nothing is the right answer there, and guessing is not.
     */
    private function installServerFor(string $plugin): void
    {
        $offer = $this->offers->installOffer($plugin);
        if (null === $offer || $offer['available']) {
            return;
        }
        $self = \array_find($offer['via'], static fn (array $candidate): bool => PluginApi::INSTALL_SELF === $candidate[0]);
        if (null !== $self) {
            $this->commands->run($plugin.':'.$self[1]);
        }
    }
}
