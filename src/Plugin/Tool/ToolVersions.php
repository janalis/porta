<?php

declare(strict_types=1);

namespace Porta\Plugin\Tool;

/**
 * What version of each tool is on this machine — asked of the BINARY, cached
 * in memory by its path, never written to disk.
 *
 * A sidecar file or a setting would be a second source of truth that desyncs
 * the moment someone swaps the binary by hand, and a setting would put a
 * machine-global fact into a file that travels with the project. A binary can
 * answer for itself in ~50ms, once per session, so it does.
 *
 * {@see known()} is the only thing a render path may call: it returns the
 * cache and never spawns. {@see probe()} is what fills it, and must be reached
 * from an action or a ready hook.
 */
final class ToolVersions
{
    /** `--version` prints one line and exits; there is nothing to watch arrive. */
    private const null NO_CHUNKS = null;

    /** @var array<string, string> binary path => the version it named ('' when it named none) */
    private array $versions = [];

    /** @var array<string, list<\Closure(): void>> binary path => who is waiting on the answer */
    private array $probing = [];

    /** @var \Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void */
    private readonly \Closure $run;

    /**
     * @param (\Closure(list<string>, string, \Closure(int, string): void, (\Closure(string): void)|null): void)|null $run
     *        inject a fake to test without processes
     */
    public function __construct(?\Closure $run = null)
    {
        $this->run = $run ?? ProcessRunner::default(ProcessRunner::PROBE_TIMEOUT);
    }

    /**
     * The cached version of $path, or '' when nothing is known — not probed
     * yet, or the binary names itself in some other shape.
     *
     * Cheap and throw-free by contract: the Settings page reads it once per
     * render.
     */
    public function known(string $path): string
    {
        return $this->versions[$path] ?? '';
    }

    /** Whether $path has been asked and has answered, version or not. */
    public function probed(string $path): bool
    {
        return \array_key_exists($path, $this->versions);
    }

    /**
     * Record what an install just reported, so the freshly landed binary does
     * not have to be asked again — {@see GithubInstaller} already ran it as the
     * smoke test.
     */
    public function remember(string $path, string $version): void
    {
        $this->versions[$path] = $version;
    }

    /** Drop what is known about $path, so the next probe asks again — after an update replaced it. */
    public function forget(string $path): void
    {
        unset($this->versions[$path]);
    }

    /**
     * Ask $binary what it is, unless that same path has already answered.
     *
     * Concurrent calls for one path share the one process: everyone waiting
     * hears the answer, because two processes would ask the same question of
     * the same file.
     *
     * @param list<string>            $args what makes it print its version
     * @param (\Closure(): void)|null $then runs once the cache has the answer
     */
    public function probe(string $binary, array $args = ['--version'], ?\Closure $then = null): void
    {
        if ($this->probed($binary)) {
            $then?->__invoke();

            return;
        }
        if (\array_key_exists($binary, $this->probing)) {
            if (null !== $then) {
                $this->probing[$binary][] = $then;
            }

            return;
        }
        $this->probing[$binary] = null === $then ? [] : [$then];
        // The binary's own directory as the cwd: a bare name resolves through
        // the PATH either way, and a pathed one does not care — but the cwd
        // must exist, and the project root is the one directory that can be
        // deleted underneath a long-lived IDE.
        $cwd = \str_contains($binary, needle: '/') ? \dirname($binary) : \sys_get_temp_dir();
        ($this->run)(
            [$binary, ...$args],
            $cwd,
            function (int $code, string $reported) use ($binary): void {
                // A binary that will not run leaves the version unknown rather
                // than stale: the caller's next question is "can I update this?",
                // and answering it from a version the file no longer has is worse
                // than answering "I do not know".
                $this->versions[$binary] = 0 === $code ? VersionText::of($reported) ?? '' : '';
                $waiting = $this->probing[$binary] ?? [];
                unset($this->probing[$binary]);
                foreach ($waiting as $then) {
                    $then();
                }
            },
            self::NO_CHUNKS,
        );
    }
}
