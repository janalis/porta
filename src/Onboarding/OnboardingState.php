<?php

declare(strict_types=1);

namespace Porta\Onboarding;

use Porta\Container\AsIdeService;
use Porta\Support\JsonFile;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Remembers that the Welcome screen has been shown, in
 * `<root>/.porta/onboarding.json` beside the other per-project state.
 *
 * **A positive marker, not an inference.** Two cheaper-looking signals were
 * considered and rejected:
 *
 * - *No `session.json`* — {@see \Porta\History\SessionStore::save()} deletes it
 *   when no buffers are open, so its absence means "new project **or** the user
 *   closed every tab last time".
 * - *No `.porta/` directory* — it is created lazily by whichever store persists
 *   first, but a repository can perfectly well commit `.porta/plugins/` (Porta
 *   itself used to, before the bundled plugins moved to `plugins/`), which
 *   would suppress onboarding for everyone who clones it.
 *
 * Project-scoped rather than global because the setup it walks through is
 * per-project: highlighting and the language server are configured in
 * `.porta.json`. It also keeps the state out of `PORTA_HOME`, which the test
 * suite points at a fixture inside the repository.
 *
 * A write failure is reported but never thrown: the cost of losing the marker
 * is showing the screen once more, which must not block a boot on a read-only
 * checkout.
 */
#[AsIdeService]
final readonly class OnboardingState
{
    private string $file;
    private Filesystem $fs;

    public function __construct(string $rootDir)
    {
        $this->file = $rootDir.'/.porta/onboarding.json';
        $this->fs = new Filesystem();
    }

    public function hasSeenWelcome(): bool
    {
        return true === (JsonFile::read($this->file)['welcomeSeen'] ?? null);
    }

    /** Whether the marker was written; false on an unwritable project. */
    public function markWelcomeSeen(): bool
    {
        return JsonFile::write($this->fs, $this->file, ['welcomeSeen' => true]);
    }
}
