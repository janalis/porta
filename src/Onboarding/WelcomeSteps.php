<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Config\SettingsHighlightController;
use Porta\Highlight\SyntaxHighlightProviderSource;
use Porta\I18n\Translator;
use Porta\Plugin\PluginLifecycleOps;

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
final class WelcomeSteps
{
    /**
     * The bundled plugin providing a PHP language server. Named for the same
     * reason {@see SettingsHighlightController::BUNDLED_ENGINE} is: a bundled
     * plugin's capabilities are unknowable without loading it, so "which one
     * serves PHP?" has no general answer.
     */
    public const string PHP_LANGUAGE_SERVER = 'phpantom';

    public function __construct(
        private readonly Translator $translator,
        private readonly SyntaxHighlightProviderSource $highlighters,
        private readonly PluginLifecycleOps $lifecycle,
    ) {}

    /** @return list<WelcomeStep> */
    public function all(): array
    {
        return [
            new WelcomeStep(
                $this->translator->trans('Syntax highlighting'),
                $this->translator->trans('Colours your code; off until enabled'),
                $this->translator->trans('Enable %name%', ['%name%' => SettingsHighlightController::BUNDLED_ENGINE]),
                // Asking the source directly is one call; going through the
                // registry would mean probing every grammar against every
                // provider, because its fallback always claims a language.
                fn (): bool => [] !== $this->highlighters->highlightProviders(),
                function (): void {
                    $this->lifecycle->enable(SettingsHighlightController::BUNDLED_ENGINE);
                },
            ),
            new WelcomeStep(
                $this->translator->trans('PHP language server'),
                $this->translator->trans('Completion, go-to-definition and diagnostics'),
                $this->translator->trans('Enable %name%', ['%name%' => self::PHP_LANGUAGE_SERVER]),
                fn (): bool => $this->isEnabled(self::PHP_LANGUAGE_SERVER),
                function (): void {
                    $this->lifecycle->enable(self::PHP_LANGUAGE_SERVER);
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
}
