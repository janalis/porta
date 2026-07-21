<?php

declare(strict_types=1);

namespace Porta\Onboarding;

/**
 * One setup step on the Welcome screen.
 *
 * Deliberately narrower than {@see \Porta\Config\SettingsRow}, which carries five
 * kinds and their editing modes: a step has exactly one interaction — run it —
 * so the widget stays small. Anything needing a real control (picking a theme,
 * say) delegates by opening the Settings page that owns it rather than growing
 * a second control surface here.
 *
 * `$isDone` is a predicate rather than a stored flag because every step's
 * completion is already recorded somewhere authoritative: an engine is enabled
 * or it isn't, a plugin is loaded or it isn't. Asking each time means the screen
 * cannot drift out of sync with the thing it describes, and a step the user
 * satisfied elsewhere shows as done the moment they come back.
 */
final readonly class WelcomeStep
{
    /**
     * @param \Closure(): bool $isDone      whether this step no longer needs the user
     * @param \Closure(): void $run         performs the step; safe to call when already done
     * @param string           $actionLabel what the button says while the step is pending
     */
    public function __construct(
        public string $title,
        public string $detail,
        public string $actionLabel,
        private \Closure $isDone,
        private \Closure $run,
    ) {}

    public function isDone(): bool
    {
        return ($this->isDone)();
    }

    public function run(): void
    {
        ($this->run)();
    }
}
