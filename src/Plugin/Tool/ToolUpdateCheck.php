<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

use Porta\Config\PortaHome;
use Porta\Support\JsonFile;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Whether it is time to ask the package indexes about newer tool versions,
 * and the note left behind once that was done.
 *
 * A once-per-launch check would cost requests every time a window opens —
 * several a day on a normal machine, all to learn the same thing — and on a
 * heavy day it runs into GitHub's per-IP limit the install already met. The
 * user picks the cadence (`toolUpdateCheck`: daily, weekly or off).
 *
 * The note lives under `~/.porta`, never in `.porta.json`: when this machine
 * last looked is a machine-global fact, and a project file travels to
 * everyone who clones the repository. It also keeps the latest version seen
 * for each tool, so the Settings page can say "1.3.0 available" between
 * checks without asking anyone.
 */
#[Autoconfigure(constructor: 'forHome')]
final readonly class ToolUpdateCheck
{
    public const int DAY = 86_400;

    public const string FILE = 'tool-updates.json';

    /** The note, or null on a machine with no home — where a check is never due and nothing is kept. */
    private ?string $file;

    /** @param string|null $portaDir the user's `~/.porta`, or null when there is none */
    public function __construct(
        private Filesystem $filesystem,
        ?string $portaDir,
    ) {
        $this->file = null === $portaDir ? null : $portaDir.'/'.self::FILE;
    }

    /** Production wiring: the note under the user's home. */
    public static function forHome(): self
    {
        $home = PortaHome::base();

        return new self(new Filesystem(), null === $home ? null : $home.'/.porta');
    }

    /**
     * Whether a check is owed at $now, $every seconds after the last.
     *
     * A missing, unreadable or malformed note means "never checked", which
     * costs one round of requests — the safe direction, unlike trusting a
     * corrupt timestamp and never checking again.
     *
     * A timestamp in the FUTURE is also due: a clock that was wrong when the
     * note was written would otherwise switch the feature off until it caught
     * up, which for a badly set clock is never.
     */
    public function due(int $now, int $every): bool
    {
        if (null === $this->file) {
            return false;
        }
        $state = JsonFile::read($this->file);
        /** @var mixed $checkedAt */
        $checkedAt = $state['checkedAt'] ?? null;

        return !\is_int($checkedAt) || $checkedAt > $now || ($now - $checkedAt) >= $every;
    }

    /**
     * The latest version each tool had when this machine last looked, keyed
     * `<plugin>/<tool>` — what the Settings page compares against.
     *
     * @return array<string, string>
     */
    public function latest(): array
    {
        $state = null === $this->file ? null : JsonFile::read($this->file);
        $latest = [];
        /** @var mixed $version */
        foreach (\is_array($state['latest'] ?? null) ? $state['latest'] : [] as $tool => $version) {
            if (!\is_string($tool) || !\is_string($version)) {
                continue;
            }
            $latest[$tool] = $version;
        }

        return $latest;
    }

    /**
     * Note that this machine looked at $now and saw $latest. A tool the check
     * could not ask about keeps what was seen before. Best-effort: a machine
     * that cannot write here checks again next launch, which is a wasted
     * round and not a broken feature.
     *
     * @param array<string, string> $latest `<plugin>/<tool>` => its newest version
     */
    public function record(int $now, array $latest): void
    {
        if (null === $this->file) {
            return;
        }
        JsonFile::write($this->filesystem, $this->file, ['checkedAt' => $now, 'latest' => [...$this->latest(), ...$latest]]);
    }
}
