<?php

declare(strict_types=1);

namespace Porta\Widget;

/**
 * Shared guard for the app-level input hook installed via
 * {@see \Symfony\Component\Tui\Widget\KeybindingsTrait::onInput()}: every
 * focusable widget's handleInput() gives the hook first refusal before
 * handling the key itself. Must be composed alongside KeybindingsTrait; the
 * $onInput declaration below is byte-identical to that trait's, so the two
 * flatten onto the same property.
 */
trait InputHook
{
    /** @var (\Closure(string): bool)|null */
    private ?\Closure $onInput = null;

    /** Whether the app-level input hook consumed $data. */
    protected function inputHookHandled(string $data): bool
    {
        return null !== $this->onInput && ($this->onInput)($data);
    }
}
