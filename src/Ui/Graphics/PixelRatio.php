<?php

declare(strict_types=1);

namespace Porta\Ui\Graphics;

use Porta\Container\AsIdeService;
use Porta\Support\AsyncProcess;

/**
 * Finds the display's pixel ratio for {@see TerminalGraphics}: how many of the
 * terminal's pixels make one logical pixel. Kitty and Ghostty count cells in
 * device pixels, so on a Retina screen 100% showed a picture at half the size
 * a browser, or Preview, shows it.
 *
 * The `pixelRatio` setting names a ratio, or `auto` to ask the system. No
 * terminal reports its ratio, so `auto` asks what drives the screen:
 * - macOS: its backing scale factor, from a short JavaScript-for-Automation
 *   query run off the loop.
 * - Linux: `GDK_SCALE` or `QT_SCALE_FACTOR`.
 * - Over SSH the screen is on another machine, and nothing here can see it.
 * Otherwise the ratio is 1, what 100% meant before.
 */
#[AsIdeService]
final class PixelRatio
{
    /** The setting's choices ({@see \Porta\Config\IdeConfig::CHOICE_SETTINGS} mirrors them). */
    public const array CHOICES = ['auto', '1', '1.25', '1.5', '2', '3'];

    /** Asks macOS for the backing scale factor of the screen the terminal is on. */
    public const array MAC_QUERY = ['osascript', '-l', 'JavaScript', '-e', 'ObjC.import("AppKit"); $.NSScreen.mainScreen.backingScaleFactor'];

    /** A system query that has not answered by then is not going to. */
    private const float QUERY_TIMEOUT = 5.0;

    private string $setting = 'auto';

    /** @var array<string, string|false> */
    private array $env = [];

    private string $os = \PHP_OS_FAMILY;

    /** False until {@see probe()}: before it, nothing may be spawned. */
    private bool $probed = false;

    private ?AsyncProcess $query = null;

    /** `auto` is waiting for the terminal to say it draws pixels: until then no ratio matters. */
    private bool $waiting = false;

    /** @param list<string> $macQuery the system query, a test's stand-in in place of {@see MAC_QUERY} */
    public function __construct(
        private readonly TerminalGraphics $graphics,
        private readonly array $macQuery = self::MAC_QUERY,
    ) {}

    /** The `pixelRatio` setting changed, or was read at boot. */
    public function apply(string $setting): void
    {
        $this->setting = $setting;
        if ($this->probed) {
            $this->resolve();
        }
    }

    /**
     * Find out, once the IDE runs — beside the terminal's own graphics probe.
     *
     * @param array<string, string|false> $env
     */
    public function probe(array $env, string $os = \PHP_OS_FAMILY): void
    {
        [$this->env, $this->os, $this->probed] = [$env, $os, true];
        $this->graphics->onChange(function (): void {
            if ($this->waiting && $this->graphics->available()) {
                $this->resolve();
            }
        });
        $this->resolve();
    }

    /** The IDE is leaving: stop a query still out. */
    public function release(): void
    {
        $this->query?->cancel();
        $this->query = null;
    }

    private function resolve(): void
    {
        $this->release();
        $named = \is_numeric($this->setting) ? (float) $this->setting : null;
        if (null !== $named) {
            $this->graphics->setPixelRatio($named);

            return;
        }
        // Only a terminal that draws pixels has a zoom it changes: asking
        // the system for any other would spawn a process for nothing.
        $this->waiting = !$this->graphics->available();
        $scale = $this->env['GDK_SCALE'] ?? $this->env['QT_SCALE_FACTOR'] ?? false;
        match (true) {
            $this->waiting || false !== ($this->env['SSH_CONNECTION'] ?? false) => $this->graphics->setPixelRatio(1.0),
            'Darwin' === $this->os => $this->ask(),
            default => $this->graphics->setPixelRatio(\is_numeric($scale) ? (float) $scale : 1.0),
        };
    }

    /** Ask macOS off the loop; a query that fails leaves the ratio at 1. */
    private function ask(): void
    {
        $query = new AsyncProcess($this->macQuery, '/', timeout: self::QUERY_TIMEOUT);
        $this->query = $query;
        // A newer answer cancels this query (release()), and a cancelled one
        // never calls back: whatever arrives here is the answer still wanted.
        $started = $query->start(function (int $exit, string $stdout): void {
            $this->query = null;
            $ratio = \trim($stdout);
            $this->graphics->setPixelRatio(0 === $exit && \is_numeric($ratio) ? (float) $ratio : 1.0);
        });
        if (!$started) {
            $this->query = null;
            $this->graphics->setPixelRatio(1.0);
        }
    }
}
