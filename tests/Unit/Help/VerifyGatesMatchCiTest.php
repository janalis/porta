<?php

declare(strict_types=1);

namespace Porta\Tests\Unit\Help;

use PHPUnit\Framework\TestCase;

/**
 * `composer verify` and CI must grade the same tree.
 *
 * The chain cannot drift from the gates themselves — it is a composer script
 * array, so it IS the scripts it names — but it can drift from CI, which calls
 * them one by one across its jobs. That split is deliberate: the `tests` job
 * runs twice over an intl matrix, the coverage jobs need a pcov runner where
 * `static` needs none, and folding them into one `verify` job would serialise
 * what CI runs in parallel. `testing.md`'s flake doctrine also reads the
 * divergence between the `tests` and coverage jobs as its machine-load tell.
 *
 * So the two lists are kept identical by a ratchet rather than by a promise,
 * in the arch gate's doctrine: it fails in EITHER direction. A gate wired into
 * CI and not into `verify` is a gate a developer never runs; one in `verify`
 * and not in CI is a gate that only blocks the person who remembered.
 */
final class VerifyGatesMatchCiTest extends TestCase
{
    /**
     * Run by CI on purpose and NOT by `composer verify`.
     *
     * `test` is subsumed — the coverage pass runs the same suite under pcov, so
     * running both locally ran it twice. CI keeps its own `tests` job because
     * comparing it against the coverage job is how the flake doctrine tells a
     * machine-load failure from a real one, and because it is the leg that runs
     * over the intl matrix.
     *
     * The rest are not gates: they install, validate the manifest, or audit.
     *
     * @var list<string>
     */
    private const array NOT_IN_VERIFY = ['test', 'install', 'validate', 'audit'];

    public function testEveryGateCiRunsIsInVerifyAndTheOtherWayRound(): void
    {
        $root = \dirname(__DIR__, levels: 3);

        /** @var array{scripts: array<string, list<string>|string>} $composer */
        $composer = \json_decode((string) \file_get_contents($root.'/composer.json'), associative: true);
        $scripts = $composer['scripts'];

        \preg_match_all('/^\s*(?:- )?run: composer ([a-z:]+)/m', (string) \file_get_contents($root.'/.github/workflows/ci.yml'), $matches);
        $ci = \array_values(\array_unique(\array_diff($matches[1], self::NOT_IN_VERIFY)));

        $reachedByVerify = self::reach($scripts, 'verify');
        $reachedByCi = [];
        foreach ($ci as $script) {
            $reachedByCi = [...$reachedByCi, ...self::reach($scripts, $script)];
        }
        $reachedByCi = \array_values(\array_unique($reachedByCi));

        $ciOnly = \array_values(\array_diff($reachedByCi, $reachedByVerify));
        \sort($ciOnly);
        $this->assertSame([], $ciOnly, 'CI runs a gate `composer verify` never reaches — add it to the chain, or list it in NOT_IN_VERIFY and say why');

        // What `verify` reaches and CI does not may only be a CHAIN: a script
        // whose every step is a reference to another script runs no gate of its
        // own, and CI naming its parts instead is how the coverage passes get
        // their own runners. Anything else is a gate CI would never run.
        $verifyOnly = \array_values(\array_filter(
            \array_diff($reachedByVerify, $reachedByCi),
            static fn (string $name): bool => !self::isChain($scripts, $name),
        ));
        \sort($verifyOnly);
        $this->assertSame([], $verifyOnly, '`composer verify` runs a gate CI does not — wire it into a CI job, or it only blocks the person who remembered');
    }

    /**
     * $name and every script it reaches through `@` references.
     *
     * @param array<string, list<string>|string> $scripts
     *
     * @return list<string>
     */
    private static function reach(array $scripts, string $name): array
    {
        $out = [$name];
        foreach (self::steps($scripts, $name) as $step) {
            $referenced = self::referenced($scripts, $step);
            if (null === $referenced) {
                continue; // a shell command, or `@php`/`@putenv` — not another gate
            }
            $out = [...$out, ...self::reach($scripts, $referenced)];
        }

        return $out;
    }

    /**
     * Whether a script is nothing but a list of other scripts.
     *
     * @param array<string, list<string>|string> $scripts
     */
    private static function isChain(array $scripts, string $name): bool
    {
        $steps = self::steps($scripts, $name);
        if ([] === $steps) {
            return false;
        }

        return [] === \array_filter($steps, static fn (string $step): bool => null === self::referenced($scripts, $step));
    }

    /**
     * @param array<string, list<string>|string> $scripts
     *
     * @return list<string>
     */
    private static function steps(array $scripts, string $name): array
    {
        $steps = $scripts[$name] ?? [];

        return \is_array($steps) ? $steps : [$steps];
    }

    /**
     * The script a step runs: `@name`, or a chain handed to `bin/keep-going`,
     * which runs that chain's steps itself — `coverage:check` runs its two
     * passes at once through it, and they are still the gates CI runs.
     *
     * @param array<string, list<string>|string> $scripts
     */
    private static function referenced(array $scripts, string $step): ?string
    {
        $name = 1 === \preg_match('/^@php bin\/keep-going ([a-z:]+)/', $step, $match) ? $match[1] : \substr($step, offset: 1);

        return \str_starts_with($step, '@') && \array_key_exists($name, $scripts) ? $name : null;
    }
}
